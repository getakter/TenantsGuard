<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ComplianceProfile;
use App\Models\FrameworkAttestation;
use App\Models\IntuneBundle;
use App\Models\Tenant;
use App\Services\Compliance\ComplianceEngine;
use App\Services\Compliance\FrameworkRegistry;
use App\Services\Compliance\PlanDefaults;
use App\Services\Intune\IntuneCatalog;
use App\Services\Scanner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    public function index(Request $request)
    {
        $frameworks = collect(FrameworkRegistry::all())->map(function ($fw) {
            $total = count($fw['requirements']);
            $automated = count(array_filter($fw['requirements'], fn ($r) => $r['controls']));

            return $fw + ['total' => $total, 'automated_count' => $automated];
        });

        $tenants = $request->user()->visibleTenants()->with('complianceProfile')->orderBy('name')->get();
        $summary = [];
        foreach ($tenants as $t) {
            if ($t->complianceProfile) {
                $summary[$t->id] = ComplianceEngine::evaluate($t);
            }
        }

        return view('compliance.index', [
            'frameworks' => $frameworks,
            'profiles' => ComplianceProfile::withCount('tenants')->orderByDesc('is_system')->orderBy('name')->get(),
            'tenants' => $tenants,
            'summary' => $summary,
        ]);
    }

    public function framework(string $key)
    {
        $fw = FrameworkRegistry::find($key) ?? abort(404);

        return view('compliance.framework', ['fw' => $fw, 'controls' => FrameworkRegistry::controls()]);
    }

    // ------------------------------------------------------------------ profiles

    public function create(Request $request)
    {
        $from = $request->filled('from') ? ComplianceProfile::find($request->integer('from')) : null;
        $tenant = $request->filled('tenant') ? $request->user()->visibleTenants()->find($request->integer('tenant')) : null;
        $profile = $from
            ? $from->replicate()->fill(['name' => $tenant ? $tenant->name.' - ' : $from->name.' (copy)', 'is_system' => false])
            : new ComplianceProfile(['name' => $tenant ? $tenant->name.' - ' : '', 'frameworks' => [], 'excluded_controls' => [], 'exclude_from_score' => true]);

        return view('compliance.profile-form', ['profile' => $profile, 'tenants' => collect(), 'assigned' => [], 'forTenant' => $tenant, 'copyFrom' => $from]);
    }

    public function store(Request $request)
    {
        $from = $request->filled('copy_from') ? ComplianceProfile::find($request->integer('copy_from')) : null;
        $profile = ComplianceProfile::create($this->validated($request) + [
            'is_system' => false,
            'excluded_controls' => $from?->excluded_controls ?? [],
            'control_settings' => $from?->control_settings,
            'phases' => $from?->phases,
        ]);
        if ($request->filled('tenant_id') && ($t = $request->user()->visibleTenants()->find($request->integer('tenant_id')))) {
            $t->update(['compliance_profile_id' => $profile->id]);
            Scanner::rescore($t);
        }
        ActivityLog::record('compliance.profile', null, 'info', "Control plan \"{$profile->name}\" created for ".implode(', ', $profile->frameworkKeys()));

        return redirect()->route('compliance.profiles.edit', $profile)->with('status', 'Control plan created. Step 2: review the controls, choose scope and phases, then save.');
    }

    public function edit(Request $request, ComplianceProfile $profile)
    {
        return view('compliance.profile-form', [
            'profile' => $profile,
            'tenants' => $request->user()->visibleTenants()->orderBy('name')->get(),
            'assigned' => $profile->tenants()->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, ComplianceProfile $profile)
    {
        $data = $this->validated($request, $profile);

        // In-scope controls: everything offered and not unticked. Unticked controls need a reason.
        $offered = (array) $request->input('offered', []);
        $inScope = (array) $request->input('in_scope', []);
        $reasons = (array) $request->input('reason', []);
        $excluded = $profile->excluded_controls ?? [];
        foreach ($offered as $id) {
            if (! FrameworkRegistry::control($id)) {
                continue;
            }
            if (in_array($id, $inScope, true)) {
                unset($excluded[$id]);
            } else {
                $excluded[$id] = trim((string) ($reasons[$id] ?? '')) ?: 'Out of scope for this customer';
            }
        }
        $data['excluded_controls'] = $excluded;

        // Phase names and per-control rollout settings.
        $phases = [];
        foreach ((array) $request->input('phase_names', []) as $n => $name) {
            $name = trim((string) $name);
            if ($name !== '' && (int) $n >= 1 && (int) $n <= 8) {
                $phases[] = ['n' => (int) $n, 'name' => Str::limit($name, 80, ''), 'description' => trim((string) ($request->input("phase_descriptions.{$n}") ?? ''))];
            }
        }
        if ($phases) {
            $data['phases'] = $phases;
        }
        $phaseNumbers = array_column($phases ?: PlanDefaults::PHASES, 'n');
        $settings = $profile->control_settings ?? [];
        foreach ($offered as $id) {
            $template = IntuneCatalog::find($id);
            $phase = (int) $request->input("phase.{$id}");
            $assignment = $request->input("assignment.{$id}");
            $settings[$id] = array_filter([
                'phase' => in_array($phase, $phaseNumbers, true) ? $phase : null,
                'assignment' => $template && in_array($assignment, $template['targets'] ?? [], true) ? $assignment : null,
            ], fn ($v) => $v !== null);
        }
        $data['control_settings'] = array_filter($settings);
        $profile->update($data);

        // Tenant assignment (only tenants the user can see).
        $visible = $request->user()->visibleTenants()->pluck('id')->all();
        $chosen = array_intersect(array_map('intval', (array) $request->input('tenant_ids', [])), $visible);
        Tenant::whereIn('id', $visible)->where('compliance_profile_id', $profile->id)->whereNotIn('id', $chosen)->update(['compliance_profile_id' => null]);
        Tenant::whereIn('id', $chosen)->update(['compliance_profile_id' => $profile->id]);
        foreach (Tenant::whereIn('id', $visible)->get() as $t) {
            Scanner::rescore($t);
        }

        ActivityLog::record('compliance.profile', null, 'info', "Compliance profile \"{$profile->name}\" saved: ".implode(', ', $profile->frameworkKeys()).', '.count($excluded).' controls out of scope, '.count($chosen).' tenants');

        return redirect()->route('compliance.profiles.edit', $profile)->with('status', 'Profile saved.');
    }

    /** Download a plan definition as JSON (no tenant data). */
    public function export(ComplianceProfile $profile)
    {
        $json = [
            'type' => 'tenantsguard.control-plan',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'name' => $profile->name,
            'description' => $profile->description,
            'frameworks' => $profile->frameworks,
            'phases' => $profile->phases,
            'excluded_controls' => $profile->excluded_controls ?? [],
            'control_settings' => $profile->control_settings ?? [],
            'exclude_from_score' => $profile->exclude_from_score,
            'intune_bundle' => $profile->intuneBundle?->name,
        ];

        return response()->streamDownload(fn () => print (json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            Str::slug($profile->name).'.control-plan.json', ['Content-Type' => 'application/json']);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:1024']);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data) || ($data['type'] ?? null) !== 'tenantsguard.control-plan') {
            return back()->withErrors(['file' => 'This is not a TenantsGuard control plan export.']);
        }
        $known = array_keys(FrameworkRegistry::all());
        $frameworks = array_values(array_filter((array) ($data['frameworks'] ?? []), fn ($f) => in_array($f['key'] ?? null, $known, true)));
        if (! $frameworks) {
            return back()->withErrors(['file' => 'The file contains no frameworks this version knows.']);
        }
        $controls = FrameworkRegistry::controls();
        $name = Str::limit((string) ($data['name'] ?? 'Imported plan'), 140, '');
        while (ComplianceProfile::where('name', $name)->exists()) {
            $name .= ' (imported)';
        }
        $profile = ComplianceProfile::create([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'frameworks' => $frameworks,
            'phases' => is_array($data['phases'] ?? null) ? $data['phases'] : null,
            'excluded_controls' => array_intersect_key((array) ($data['excluded_controls'] ?? []), $controls),
            'control_settings' => array_intersect_key((array) ($data['control_settings'] ?? []), $controls),
            'exclude_from_score' => (bool) ($data['exclude_from_score'] ?? true),
            'intune_bundle_id' => ! empty($data['intune_bundle']) ? IntuneBundle::where('name', $data['intune_bundle'])->value('id') : null,
            'is_system' => false,
        ]);
        ActivityLog::record('compliance.profile', null, 'info', "Control plan \"{$profile->name}\" imported");

        return redirect()->route('compliance.profiles.edit', $profile)->with('status', 'Control plan imported. Review it and assign clients.');
    }

    public function destroy(ComplianceProfile $profile)
    {
        $tenants = $profile->tenants()->get();
        $profile->delete();
        foreach ($tenants as $t) {
            Scanner::rescore($t->fresh());
        }

        return redirect()->route('compliance.index')->with('status', 'Profile deleted. Its tenants no longer have a compliance profile.');
    }

    private function validated(Request $request, ?ComplianceProfile $profile = null): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('compliance_profiles', 'name')->ignore($profile?->id)],
            'description' => 'nullable|string|max:2000',
            'frameworks' => 'required|array|min:1',
            'frameworks.*' => [Rule::in(array_keys(FrameworkRegistry::all()))],
            'e8_level' => 'nullable|in:1,2,3',
            'intune_bundle_id' => 'nullable|exists:intune_bundles,id',
        ], ['frameworks.required' => 'Choose at least one framework.']);

        $frameworks = [];
        foreach ($request->input('frameworks') as $key) {
            $entry = ['key' => $key];
            if (FrameworkRegistry::find($key)['levels']) {
                $entry['target_level'] = (int) ($request->input($key.'_level') ?: 2);
            }
            $frameworks[] = $entry;
        }

        return [
            'name' => $request->string('name')->trim()->value(),
            'description' => $request->input('description'),
            'frameworks' => $frameworks,
            'exclude_from_score' => $request->boolean('exclude_from_score'),
            'intune_bundle_id' => $request->input('intune_bundle_id') ?: null,
        ];
    }

    // ------------------------------------------------------------------ tenant scorecard

    public function tenant(Tenant $tenant)
    {
        $tenant->load('complianceProfile');

        return view('compliance.tenant', [
            'tenant' => $tenant,
            'profile' => $tenant->complianceProfile,
            'profiles' => ComplianceProfile::orderBy('name')->get(),
            'result' => ComplianceEngine::evaluate($tenant),
        ]);
    }

    public function assign(Request $request, Tenant $tenant)
    {
        $data = $request->validate(['compliance_profile_id' => 'nullable|exists:compliance_profiles,id']);
        $tenant->update(['compliance_profile_id' => $data['compliance_profile_id'] ?? null]);
        Scanner::rescore($tenant->fresh());
        ActivityLog::record('compliance.assign', $tenant, 'info', 'Compliance profile set to '.($tenant->complianceProfile?->name ?? 'none'));

        return back()->with('status', 'Compliance profile updated.');
    }

    public function attest(Request $request, Tenant $tenant, string $framework, string $requirement)
    {
        $fw = FrameworkRegistry::find($framework) ?? abort(404);
        abort_unless(collect($fw['requirements'])->contains('id', $requirement), 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(FrameworkAttestation::STATUSES))],
            'note' => 'required|string|max:2000',
            'evidence_url' => 'nullable|url|max:500',
            'review_by' => 'nullable|date|after:today',
        ]);
        FrameworkAttestation::updateOrCreate(
            ['tenant_id' => $tenant->id, 'framework' => $framework, 'requirement' => $requirement],
            $data + ['user_id' => $request->user()->id]
        );
        ActivityLog::record('compliance.attest', $tenant, 'info', FrameworkRegistry::SHORT[$framework]." {$requirement}: ".FrameworkAttestation::STATUSES[$data['status']].' — '.$data['note']);

        return redirect(route('compliance.tenant', $tenant).'#'.$framework.'-'.md5($requirement))->with('status', 'Attestation saved.');
    }

    public function unattest(Tenant $tenant, string $framework, string $requirement)
    {
        FrameworkAttestation::where(['tenant_id' => $tenant->id, 'framework' => $framework, 'requirement' => $requirement])->delete();
        ActivityLog::record('compliance.attest', $tenant, 'info', (FrameworkRegistry::SHORT[$framework] ?? $framework)." {$requirement}: attestation removed");

        return redirect(route('compliance.tenant', $tenant).'#'.$framework.'-'.md5($requirement))->with('status', 'Attestation removed.');
    }
}
