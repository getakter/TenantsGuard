<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LifecycleTemplate;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Lifecycle\Onboarding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Per-client user templates: the defaults a new account gets (username format, display name suffix,
 * domain, licences, groups, company and address). One template per client can be the default.
 */
class UserTemplateController extends Controller
{
    public const FIELDS = [
        'username_format', 'display_suffix', 'domain', 'usage_location', 'job_title', 'department', 'office', 'company',
        'street', 'city', 'state', 'postal_code', 'country_name', 'business_phone', 'manager_id', 'password_mode', 'tap_hours',
        'licences', 'groups', 'checklist',
    ];

    public function index(Request $request)
    {
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get();

        return view('templates.index', [
            'tenants' => $tenants,
            'templates' => LifecycleTemplate::with('tenant')->whereIn('tenant_id', $tenants->pluck('id'))
                ->orderByDesc('is_default')->orderBy('name')->get()->groupBy('tenant_id'),
            'formats' => LifecycleTemplate::USERNAME_FORMATS,
        ]);
    }

    public function create(Request $request, Tenant $tenant)
    {
        $template = new LifecycleTemplate(['tenant_id' => $tenant->id, 'data' => ['username_format' => 'first.last', 'password_mode' => 'tap', 'tap_hours' => 8]]);
        if ($request->filled('from_user')) {
            try {
                $snap = (new Directory($tenant))->snapshot($request->input('from_user'));
                $u = (new Directory($tenant))->graph->get('/users/'.$snap['user']['id'].'?$select=id,displayName,jobTitle,department,officeLocation,companyName,streetAddress,city,state,postalCode,country,usageLocation,businessPhones,userPrincipalName');
                $manager = null;
                try {
                    $manager = (new Directory($tenant))->graph->get('/users/'.$snap['user']['id'].'/manager')['id'] ?? null;
                } catch (GraphException) {
                }
                $template->name = trim(($u['jobTitle'] ?? '') ?: 'Like '.$u['displayName']);
                $template->data = array_filter([
                    'username_format' => 'first.last', 'password_mode' => 'tap', 'tap_hours' => 8,
                    'domain' => substr(strrchr($u['userPrincipalName'], '@'), 1),
                    'usage_location' => $u['usageLocation'] ?? null, 'job_title' => $u['jobTitle'] ?? null, 'department' => $u['department'] ?? null,
                    'office' => $u['officeLocation'] ?? null, 'company' => $u['companyName'] ?? null, 'street' => $u['streetAddress'] ?? null,
                    'city' => $u['city'] ?? null, 'state' => $u['state'] ?? null, 'postal_code' => $u['postalCode'] ?? null, 'country_name' => $u['country'] ?? null,
                    'business_phone' => $u['businessPhones'][0] ?? null, 'manager_id' => $manager,
                    'licences' => array_column($snap['licences'], 'skuId'),
                    'groups' => array_column(array_filter($snap['groups'], fn ($g) => ! $g['dynamic']), 'id'),
                ], fn ($v) => $v !== null && $v !== '');
            } catch (GraphException $e) {
                return back()->withErrors(['graph' => $e->friendly()]);
            }
        }

        return $this->form($tenant, $template);
    }

    public function edit(Tenant $tenant, LifecycleTemplate $template)
    {
        abort_unless($template->tenant_id === $tenant->id, 404);

        return $this->form($tenant, $template);
    }

    public function store(Request $request, Tenant $tenant)
    {
        return $this->save($request, $tenant, new LifecycleTemplate(['tenant_id' => $tenant->id]));
    }

    public function update(Request $request, Tenant $tenant, LifecycleTemplate $template)
    {
        abort_unless($template->tenant_id === $tenant->id, 404);

        return $this->save($request, $tenant, $template);
    }

    private function form(Tenant $tenant, LifecycleTemplate $template)
    {
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $dir = new Directory($tenant);
        try {
            $data = [
                'domains' => $dir->domains(), 'country' => $dir->country(), 'licences' => $dir->licences(), 'groups' => $dir->groups(),
                'users' => collect($dir->users())->where('accountEnabled', true)->values()->all(),
            ];
        } catch (GraphException $e) {
            return redirect()->route('templates.index')->withErrors(['graph' => $e->friendly()]);
        }

        return view('templates.form', $data + [
            'tenant' => $tenant, 'template' => $template, 'formats' => LifecycleTemplate::USERNAME_FORMATS, 'checklist' => Onboarding::CHECKLIST,
        ]);
    }

    private function save(Request $request, Tenant $tenant, LifecycleTemplate $template)
    {
        $in = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('lifecycle_templates')->where('tenant_id', $tenant->id)->ignore($template->id)],
            'is_default' => 'nullable|boolean',
            'username_format' => ['required', Rule::in(array_keys(LifecycleTemplate::USERNAME_FORMATS))],
            'display_suffix' => 'nullable|string|max:40',
            'domain' => 'nullable|string|max:190',
            'usage_location' => 'nullable|string|size:2',
            'job_title' => 'nullable|string|max:128',
            'department' => 'nullable|string|max:64',
            'office' => 'nullable|string|max:128',
            'company' => 'nullable|string|max:64',
            'street' => 'nullable|string|max:128',
            'city' => 'nullable|string|max:64',
            'state' => 'nullable|string|max:64',
            'postal_code' => 'nullable|string|max:16',
            'country_name' => 'nullable|string|max:64',
            'business_phone' => 'nullable|string|max:32',
            'manager_id' => 'nullable|string|max:64',
            'password_mode' => 'required|in:password,tap',
            'tap_hours' => 'nullable|integer|min:1|max:8',
            'licences' => 'array',
            'licences.*' => 'string|max:64',
            'groups' => 'array',
            'groups.*' => 'string|max:64',
            'checklist' => 'array',
            'checklist.*' => [Rule::in(array_keys(Onboarding::CHECKLIST))],
        ]);
        $data = array_filter(array_intersect_key($in, array_flip(self::FIELDS)), fn ($v) => $v !== null && $v !== '');
        $data += ['licences' => [], 'groups' => [], 'checklist' => []];

        DB::transaction(function () use ($template, $in, $data, $request, $tenant) {
            if (! empty($in['is_default'])) {
                LifecycleTemplate::where('tenant_id', $tenant->id)->where('id', '!=', $template->id ?? 0)->update(['is_default' => false]);
            }
            $template->fill(['name' => $in['name'], 'is_default' => ! empty($in['is_default']), 'data' => $data]);
            $template->created_by ??= $request->user()->id;
            $template->save();
        });
        ActivityLog::record('staff.template', $tenant, 'info', "Saved user template \"{$template->name}\"".($template->is_default ? ' (default)' : ''));

        return redirect()->route('templates.index')->with('status', "Template \"{$template->name}\" saved for {$tenant->name}.");
    }

    public function destroy(Tenant $tenant, LifecycleTemplate $template)
    {
        abort_unless($template->tenant_id === $tenant->id, 404);
        $template->delete();

        return back()->with('status', 'Template deleted.');
    }
}
