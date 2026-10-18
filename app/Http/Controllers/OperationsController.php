<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LifecycleRun;
use App\Models\LifecycleTask;
use App\Models\LifecycleTemplate;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Graph\GraphException;
use App\Services\Lifecycle\Directory;
use App\Services\Lifecycle\Offboarding;
use App\Services\Lifecycle\Onboarding;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Identity management: onboarding, bulk onboarding and offboarding across client tenants. */
class OperationsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $runs = LifecycleRun::visibleTo($user)->with('tenant', 'user')->latest('id');

        return view('operations.index', [
            'tenants' => $user->visibleTenants()->orderBy('name')->get()->filter->isReady()->values(),
            'tasks' => LifecycleTask::with('run', 'tenant')->where('status', 'pending')
                ->whereIn('tenant_id', $user->visibleTenants()->pluck('id'))->orderBy('execute_at')->limit(30)->get(),
            'open' => (clone $runs)->where('status', 'in_progress')->limit(30)->get()->filter(fn ($r) => array_filter($r->steps, fn ($s) => $s['status'] === 'pending')),
            'recent' => (clone $runs)->limit(25)->get(),
        ]);
    }

    public function tenant(Tenant $tenant)
    {
        return view('operations.tenant', [
            'tenant' => $tenant,
            'runs' => LifecycleRun::where('tenant_id', $tenant->id)->with('user')->latest('id')->limit(50)->get(),
            'templates' => LifecycleTemplate::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'tasks' => LifecycleTask::with('run')->where('tenant_id', $tenant->id)->where('status', 'pending')->orderBy('execute_at')->get(),
        ]);
    }

    // ------------------------------------------------------------------ onboarding

    public function onboardForm(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $dir = new Directory($tenant);
        try {
            $data = [
                'domains' => $dir->domains(),
                'country' => $dir->country(),
                'licences' => $dir->licences(),
                'groups' => $dir->groups(),
                'users' => collect($dir->users())->where('accountEnabled', true)->values()->all(),
            ];
        } catch (GraphException $e) {
            return redirect()->route('operations.tenant', $tenant)->withErrors(['graph' => $e->friendly().' Staff onboarding needs User.ReadWrite.All and Group.ReadWrite.All. Update app permissions in Settings and re-grant consent.']);
        }

        return view('operations.onboard', $data + [
            'tenant' => $tenant,
            'templates' => LifecycleTemplate::where('tenant_id', $tenant->id)->orderByDesc('is_default')->orderBy('name')->get(),
            'checklist' => Onboarding::CHECKLIST,
        ]);
    }

    public function onboard(Request $request, Tenant $tenant, Onboarding $onboarding)
    {
        $in = $request->validate([
            'first_name' => 'required|string|max:64',
            'last_name' => 'required|string|max:64',
            'display_name' => 'required|string|max:128',
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'domain' => 'required|string|max:190',
            'job_title' => 'nullable|string|max:128',
            'department' => 'nullable|string|max:64',
            'office' => 'nullable|string|max:128',
            'mobile' => 'nullable|string|max:32',
            'business_phone' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:64',
            'street' => 'nullable|string|max:128',
            'city' => 'nullable|string|max:64',
            'state' => 'nullable|string|max:64',
            'postal_code' => 'nullable|string|max:16',
            'country_name' => 'nullable|string|max:64',
            'usage_location' => 'required|string|size:2',
            'manager_id' => 'nullable|string|max:64',
            'password_mode' => 'required|in:password,tap',
            'tap_hours' => 'nullable|integer|min:1|max:8',
            'licences' => 'array',
            'licences.*' => 'string|max:64',
            'groups' => 'array',
            'groups.*' => 'string|max:64',
            'copy_from' => 'nullable|string|max:64',
            'start_date' => 'nullable|date|after_or_equal:today',
            'ticket' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:2000',
            'checklist' => 'array',
            'checklist.*' => [Rule::in(array_keys(Onboarding::CHECKLIST))],
            'save_template' => 'nullable|string|max:120',
        ], ['username.regex' => 'Use letters, numbers, dots, dashes or underscores.']);

        // Fail early with a clear message if the sign-in name is taken.
        try {
            (new Directory($tenant))->user($in['username'].'@'.$in['domain']);

            return back()->withErrors(['username' => $in['username'].'@'.$in['domain'].' already exists in this tenant. Choose another username.'])->withInput();
        } catch (GraphException $e) {
            if ($e->status !== 404) {
                return back()->withErrors(['username' => $e->friendly()])->withInput();
            }
        }

        if (filled($in['save_template'] ?? null)) {
            LifecycleTemplate::updateOrCreate(['tenant_id' => $tenant->id, 'name' => $in['save_template']], [
                'data' => array_intersect_key($in, array_flip(['domain', 'job_title', 'department', 'office', 'business_phone', 'company', 'street', 'city', 'state', 'postal_code', 'country_name', 'usage_location', 'manager_id', 'password_mode', 'tap_hours', 'licences', 'groups', 'checklist'])),
                'created_by' => $request->user()->id,
            ]);
        }

        @set_time_limit(180);
        $result = $onboarding->run($tenant, $in, $request->user());

        return redirect()->route('operations.run', $result['run'])
            ->with('credentials', $result['credentials'])
            ->with($result['run']->status === 'failed' ? 'errors' : 'status', $result['run']->status === 'failed'
                ? (new ViewErrorBag)->put('default', new MessageBag(['run' => 'Onboarding failed. See the steps below.']))
                : "{$in['display_name']} has been onboarded.");
    }

    // ------------------------------------------------------------------ offboarding

    public const OFFBOARD_REPLY = 'Thank you for your email. {name} is no longer with {company}. Please contact {manager}.';

    /** Per-client offboarding defaults ("Save as default for this client"). */
    public static function offboardDefaults(Tenant $tenant): array
    {
        $saved = json_decode((string) Setting::find('offboard_defaults_'.$tenant->id)?->value, true) ?: [];

        return $saved + [
            'steps' => array_keys(array_filter(Offboarding::STEPS, fn ($s) => $s[3])),
            'licence_delay_days' => 30,
            'delete_after_days' => 90,
            'device_action' => 'retire',
            'auto_reply_message' => self::OFFBOARD_REPLY,
            'saved' => false,
        ];
    }

    public function offboardForm(Request $request, Tenant $tenant)
    {
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $dir = new Directory($tenant);
        $ids = (array) $request->input('users', $request->filled('user') ? [$request->input('user')] : []);
        $ids = array_slice(array_values(array_unique(array_filter($ids, 'is_string'))), 0, 25);
        try {
            $users = $dir->users();
            $selected = [];
            foreach ($ids as $id) {
                $snapshot = $dir->snapshot($id);
                $guard = null;
                try {
                    Offboarding::guard($dir, $snapshot['user']['id']);
                } catch (RuntimeException $e) {
                    $guard = $e->getMessage();
                }
                $manager = null;
                try {
                    $manager = $dir->graph->get('/users/'.$snapshot['user']['id'].'/manager');
                } catch (GraphException) {
                }
                $selected[] = compact('snapshot', 'guard', 'manager');
            }
        } catch (GraphException $e) {
            return redirect()->route('operations.tenant', $tenant)->withErrors(['graph' => $e->friendly()]);
        }

        return view('operations.offboard', [
            'tenant' => $tenant,
            'users' => $users,
            'selected' => $selected,
            'steps' => Offboarding::STEPS,
            'defaults' => self::offboardDefaults($tenant),
        ]);
    }

    public function offboard(Request $request, Tenant $tenant, Offboarding $offboarding)
    {
        $in = $request->validate([
            'users' => 'required|array|min:1|max:25',
            'users.*' => 'string|max:64',
            'confirm' => 'required|string',
            'steps' => 'required|array|min:1',
            'steps.*' => [Rule::in(array_keys(Offboarding::STEPS))],
            'auto_reply_message' => 'nullable|string|max:2000',
            'devices' => 'array',
            'devices.*' => 'in:none,retire,wipe',
            'device_action' => 'nullable|in:none,retire,wipe',
            'licence_delay_days' => 'nullable|integer|min:0|max:90',
            'delete_after_days' => 'nullable|integer|min:1|max:365',
            'manager_upn' => 'nullable|string|max:190',
            'new_owner_id' => 'nullable|string|max:64',
            'when' => 'required|in:now,later',
            'scheduled_at' => 'exclude_unless:when,later|required|date|after:now',
            'ticket' => 'nullable|string|max:80',
            'reason' => 'nullable|string|max:2000',
            'save_defaults' => 'nullable|boolean',
        ], ['scheduled_at.after' => 'Choose a date and time in the future.', 'scheduled_at.required' => 'Choose when the offboarding should run.']);

        $dir = new Directory($tenant);
        $targets = [];
        foreach (array_unique($in['users']) as $id) {
            try {
                $targets[] = $dir->user($id);
            } catch (GraphException $e) {
                return back()->withErrors(['offboard' => $e->friendly()])->withInput();
            }
        }
        $expected = count($targets) === 1 ? $targets[0]['userPrincipalName'] : 'OFFBOARD '.count($targets);
        if (strcasecmp(trim($in['confirm']), $expected) !== 0) {
            return back()->withErrors(['confirm' => count($targets) === 1 ? 'Type the user principal name exactly to confirm.' : "Type {$expected} to confirm."])->withInput();
        }
        if (! empty($in['new_owner_id']) && in_array($in['new_owner_id'], array_column($targets, 'id'), true)) {
            return back()->withErrors(['new_owner_id' => 'The new owner is also being offboarded. Choose someone who is staying.'])->withInput();
        }

        if (! empty($in['save_defaults'])) {
            Setting::put('offboard_defaults_'.$tenant->id, json_encode(array_intersect_key($in, array_flip(['steps', 'licence_delay_days', 'delete_after_days', 'device_action', 'auto_reply_message'])) + ['saved' => true]));
        }

        $at = $in['when'] === 'later' ? Carbon::parse($in['scheduled_at']) : null;
        @set_time_limit(60 + 60 * count($targets));
        $runs = [];
        $errors = [];
        foreach ($targets as $u) {
            $per = array_diff_key($in, array_flip(['users', 'confirm', 'when', 'scheduled_at', 'save_defaults']));
            $per['user_id'] = $u['id'];
            $manager = null;
            try {
                $manager = $dir->graph->get('/users/'.$u['id'].'/manager');
            } catch (GraphException) {
            }
            if (count($targets) > 1 || empty($per['manager_upn'])) {
                $per['manager_upn'] = $manager['userPrincipalName'] ?? null;
            }
            if (count($targets) > 1) {
                unset($per['devices']); // per-device choices only apply to a single leaver
            }
            $per['auto_reply_message'] = strtr($per['auto_reply_message'] ?? self::OFFBOARD_REPLY, [
                '{name}' => $u['displayName'],
                '{company}' => $tenant->name,
                '{manager}' => $manager ? $manager['displayName'].' ('.($manager['mail'] ?? $manager['userPrincipalName']).')' : 'our main office',
            ]);
            try {
                $runs[] = $at ? $offboarding->schedule($tenant, $per, $request->user(), $at) : $offboarding->run($tenant, $per, $request->user());
            } catch (RuntimeException|GraphException $e) {
                $errors[] = $u['displayName'].': '.($e instanceof GraphException ? $e->friendly() : $e->getMessage());
            }
        }

        if (count($targets) === 1) {
            if ($errors) {
                return back()->withErrors(['offboard' => $errors[0]])->withInput();
            }
            $run = $runs[0];

            return redirect()->route('operations.run', $run)->with('status', $at
                ? "Offboarding of {$run->target_name} is scheduled for ".$at->format('D j M Y, H:i').'. Nothing changes until then.'
                : "{$run->target_name} has been offboarded. Complete the open checklist items below.");
        }

        $names = implode(', ', array_map(fn ($r) => $r->target_name, $runs));
        $redirect = redirect()->route('operations.tenant', $tenant);
        if ($runs) {
            $redirect->with('status', ($at ? 'Scheduled for '.$at->format('D j M Y, H:i').': ' : 'Offboarded: ').$names.'.');
        }

        return $errors ? $redirect->withErrors(['offboard' => 'Not offboarded: '.implode(' | ', $errors)]) : $redirect;
    }

    // ------------------------------------------------------------------ bulk onboarding

    /** CSV header aliases (CIPP and Microsoft 365 admin center exports). */
    private const CSV_COLUMNS = [
        'first_name' => ['firstname', 'first_name', 'givenname', 'first name'],
        'last_name' => ['lastname', 'last_name', 'surname', 'last name'],
        'display_name' => ['displayname', 'display_name', 'display name', 'name'],
        'username' => ['username', 'user name', 'mailnickname', 'alias'],
        'job_title' => ['jobtitle', 'job_title', 'job title', 'title'],
        'department' => ['department'],
        'office' => ['office', 'officelocation', 'office location'],
        'mobile' => ['mobile', 'mobilephone', 'mobile phone'],
        'business_phone' => ['businessphone', 'business phone', 'phone', 'officephone'],
        'manager' => ['manager', 'managerupn', 'manager upn', 'manageremail'],
        'usage_location' => ['usagelocation', 'usage location', 'country code'],
        'start_date' => ['startdate', 'start date', 'start'],
    ];

    public const CSV_EXAMPLE = "FirstName,LastName,JobTitle,Department,Mobile,Manager,StartDate\nAlex,Morgan,Dental Nurse,Clinical,+61 400 111 222,,\nPriya,Shah,Receptionist,Front office,,,";

    public function bulkForm(Tenant $tenant)
    {
        abort_unless($tenant->isReady(), 409, 'Connect the tenant first.');
        $dir = new Directory($tenant);
        try {
            $data = ['domains' => $dir->domains(), 'licences' => $dir->licences(), 'groups' => $dir->groups(), 'country' => $dir->country()];
        } catch (GraphException $e) {
            return redirect()->route('operations.tenant', $tenant)->withErrors(['graph' => $e->friendly()]);
        }

        return view('operations.bulk', $data + [
            'tenant' => $tenant,
            'templates' => LifecycleTemplate::where('tenant_id', $tenant->id)->orderByDesc('is_default')->orderBy('name')->get(),
            'formats' => LifecycleTemplate::USERNAME_FORMATS,
            'example' => self::CSV_EXAMPLE,
            'checklist' => Onboarding::CHECKLIST,
        ]);
    }

    /** Parse the CSV and show a validated preview. Nothing is created yet. */
    public function bulkPreview(Request $request, Tenant $tenant)
    {
        $in = $request->validate([
            'csv' => 'nullable|string|max:100000',
            'csv_file' => 'nullable|file|mimes:csv,txt|max:512',
            'template_id' => ['nullable', 'integer', Rule::exists('lifecycle_templates', 'id')->where('tenant_id', $tenant->id)],
            'domain' => 'required|string|max:190',
            'username_format' => ['required', Rule::in(array_keys(LifecycleTemplate::USERNAME_FORMATS))],
            'usage_location' => 'required|string|size:2',
            'password_mode' => 'required|in:password,tap',
            'licences' => 'array',
            'licences.*' => 'string|max:64',
            'groups' => 'array',
            'groups.*' => 'string|max:64',
            'start_date' => 'nullable|date|after_or_equal:today',
            'ticket' => 'nullable|string|max:80',
            'checklist' => 'array',
            'checklist.*' => [Rule::in(array_keys(Onboarding::CHECKLIST))],
        ]);
        $text = $request->hasFile('csv_file') ? (string) file_get_contents($request->file('csv_file')->getRealPath()) : (string) ($in['csv'] ?? '');
        $rows = self::parseCsv($text);
        if (! $rows) {
            return back()->withErrors(['csv' => 'No rows found. The first line must be a header with at least FirstName and LastName.'])->withInput();
        }
        if (count($rows) > 50) {
            return back()->withErrors(['csv' => 'Up to 50 people per batch. Split the file and run it in parts.'])->withInput();
        }

        $dir = new Directory($tenant);
        try {
            $existing = collect($dir->users());
        } catch (GraphException $e) {
            return back()->withErrors(['csv' => $e->friendly()])->withInput();
        }
        $template = isset($in['template_id']) ? LifecycleTemplate::find($in['template_id']) : null;
        $tpl = $template?->data ?? [];
        $taken = $existing->pluck('userPrincipalName')->map(fn ($u) => strtolower($u))->all();
        $seen = [];
        $people = [];
        foreach ($rows as $i => $r) {
            $p = ['line' => $i + 2, 'errors' => []];
            $p['first_name'] = trim($r['first_name'] ?? '');
            $p['last_name'] = trim($r['last_name'] ?? '');
            $p['display_name'] = trim($r['display_name'] ?? '') ?: trim($p['first_name'].' '.$p['last_name']).(($tpl['display_suffix'] ?? '') !== '' ? ' '.$tpl['display_suffix'] : '');
            $p['username'] = strtolower(trim($r['username'] ?? '')) ?: LifecycleTemplate::username($p['first_name'], $p['last_name'], $in['username_format']);
            $p['upn'] = $p['username'].'@'.$in['domain'];
            foreach (['job_title', 'department', 'office', 'mobile', 'business_phone'] as $k) {
                $p[$k] = trim($r[$k] ?? '') ?: ($tpl[$k] ?? null);
            }
            $p['usage_location'] = strtoupper(trim($r['usage_location'] ?? '') ?: $in['usage_location']);
            $p['start_date'] = trim($r['start_date'] ?? '') ?: ($in['start_date'] ?? null);
            $p['manager_id'] = $tpl['manager_id'] ?? null;
            if ($m = trim($r['manager'] ?? '')) {
                $mgr = $existing->first(fn ($u) => strcasecmp($u['userPrincipalName'], $m) === 0 || strcasecmp($u['mail'] ?? '', $m) === 0 || strcasecmp($u['displayName'], $m) === 0);
                $mgr ? $p['manager_id'] = $mgr['id'] : $p['errors'][] = "Manager \"{$m}\" was not found.";
                $p['manager_name'] = $mgr['displayName'] ?? $m;
            }
            if ($p['first_name'] === '' || $p['last_name'] === '') {
                $p['errors'][] = 'First and last name are required.';
            }
            if (! preg_match('/^[a-z0-9._-]+$/', $p['username'])) {
                $p['errors'][] = 'Username can only contain letters, numbers, dots, dashes and underscores.';
            } elseif (in_array(strtolower($p['upn']), $taken, true)) {
                $p['errors'][] = $p['upn'].' already exists in the tenant.';
            } elseif (in_array($p['upn'], $seen, true)) {
                $p['errors'][] = 'Duplicate of another row in this file.';
            }
            if ($p['start_date'] && (strtotime($p['start_date']) === false || strtotime($p['start_date']) < strtotime('today'))) {
                $p['errors'][] = 'Start date must be today or later.';
            }
            if (strlen($p['usage_location']) !== 2) {
                $p['errors'][] = 'Usage location must be a two-letter country code.';
            }
            $seen[] = $p['upn'];
            $people[] = $p;
        }

        $shared = array_intersect_key($in, array_flip(['domain', 'password_mode', 'licences', 'groups', 'ticket', 'checklist'])) + ['licences' => [], 'groups' => [], 'checklist' => []];
        foreach (['company', 'street', 'city', 'state', 'postal_code', 'country_name'] as $k) {
            $shared[$k] = $tpl[$k] ?? null;
        }
        $request->session()->put('bulk_onboard_'.$tenant->id, ['people' => $people, 'shared' => $shared, 'template' => $template?->name]);
        $licNames = collect($dir->licences())->pluck('name', 'skuId');
        $grpNames = collect($dir->groups())->pluck('name', 'id');

        return view('operations.bulk-preview', [
            'tenant' => $tenant,
            'people' => $people,
            'shared' => $shared,
            'template' => $template,
            'licNames' => $licNames,
            'grpNames' => $grpNames,
            'licences' => $dir->licences(),
        ]);
    }

    public function bulk(Request $request, Tenant $tenant, Onboarding $onboarding)
    {
        $batch = $request->session()->pull('bulk_onboard_'.$tenant->id);
        if (! $batch) {
            return redirect()->route('operations.bulk', $tenant)->withErrors(['csv' => 'The preview expired or was already used. Paste the list again.']);
        }
        $ready = array_values(array_filter($batch['people'], fn ($p) => ! $p['errors']));
        @set_time_limit(60 + 30 * count($ready));
        $results = [];
        foreach ($ready as $p) {
            $data = array_intersect_key($p, array_flip(['first_name', 'last_name', 'display_name', 'username', 'job_title', 'department', 'office', 'mobile', 'business_phone', 'usage_location', 'manager_id', 'start_date']));
            $data = array_filter($data + $batch['shared'], fn ($v) => $v !== null && $v !== '');
            $data += ['licences' => [], 'groups' => [], 'checklist' => [], 'tap_hours' => 8];
            $data['notes'] = 'Bulk onboarding'.($batch['template'] ? ' (template: '.$batch['template'].')' : '');
            try {
                $res = $onboarding->run($tenant, $data, $request->user());
                $results[] = ['person' => $p, 'run' => $res['run'], 'credentials' => $res['credentials']];
            } catch (\Throwable $e) {
                $results[] = ['person' => $p, 'run' => null, 'credentials' => null, 'error' => $e instanceof GraphException ? $e->friendly() : $e->getMessage()];
            }
        }
        ActivityLog::record('staff.onboard', $tenant, 'info', 'Bulk onboarding: '.count(array_filter($results, fn ($r) => $r['run']?->status !== 'failed' && $r['run'])).' of '.count($ready).' account(s) created.');

        return response()->view('operations.bulk-result', ['tenant' => $tenant, 'results' => $results, 'skipped' => count($batch['people']) - count($ready)])
            ->header('Cache-Control', 'no-store');
    }

    /** Minimal CSV reader: header row mapped through CSV_COLUMNS aliases. */
    public static function parseCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", trim($text)));
        $lines = array_values(array_filter(explode("\n", $text), fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            return [];
        }
        $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $map = [];
        foreach (str_getcsv($lines[0], $delim) as $i => $h) {
            $h = strtolower(trim($h));
            foreach (self::CSV_COLUMNS as $key => $aliases) {
                if (in_array($h, $aliases, true)) {
                    $map[$i] = $key;
                }
            }
        }
        if (! in_array('first_name', $map, true) || ! in_array('last_name', $map, true)) {
            return [];
        }
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $delim);
            $row = [];
            foreach ($map as $i => $key) {
                $row[$key] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    // ------------------------------------------------------------------ client picker for the Identity management menu

    public const PICK = [
        'users' => ['Users', 'identity.users', 'tenants.view', 'All member accounts with licences, MFA, admin status and last sign-in.'],
        'groups' => ['Groups', 'identity.groups', 'tenants.view', 'Teams, Microsoft 365, security and distribution groups with members and owners.'],
        'guests' => ['Guests', 'identity.guests', 'tenants.view', 'External guest accounts: invitation state, last sign-in and stale guest clean-up.'],
        'onboard' => ['Onboard user', 'operations.onboard', 'staff.onboard', 'Create one account with licences, groups, manager and first sign-in.'],
        'bulk' => ['Bulk onboard users', 'operations.bulk', 'staff.onboard', 'Create many accounts from a CSV list with one shared template.'],
        'offboard' => ['Offboard users', 'operations.offboard', 'staff.offboard', 'Lock out, hand over mail and files, remove access. Now or scheduled.'],
        'templates' => ['User templates', 'templates.create', 'staff.onboard', 'Default username format, licences, groups and company details per client.'],
    ];

    public function pick(Request $request, string $action)
    {
        abort_unless(isset(self::PICK[$action]), 404);
        [$title, $route, $ability, $desc] = self::PICK[$action];
        abort_unless($request->user()->can($ability), 403);
        $tenants = $request->user()->visibleTenants()->orderBy('name')->get()->filter->isReady()->values();
        if ($request->filled('tenant') && ($t = $tenants->firstWhere('id', (int) $request->input('tenant')))) {
            $request->session()->put('identity_tenant', $t->id);

            return redirect()->route($route, $t);
        }
        if ($tenants->count() === 1) {
            return redirect()->route($route, $tenants->first());
        }

        return view('operations.pick', compact('title', 'desc', 'action', 'tenants') + ['last' => $request->session()->get('identity_tenant')]);
    }

    // ------------------------------------------------------------------ runs, steps, tasks, templates

    public function run(LifecycleRun $run)
    {
        Gate::authorize('access-tenant', $run->tenant);

        return view('operations.run', ['run' => $run->load('tenant', 'user', 'tasks'), 'credentials' => session('credentials')]);
    }

    public function step(Request $request, LifecycleRun $run, string $key)
    {
        Gate::authorize('access-tenant', $run->tenant);
        abort_unless($request->user()->can($run->type === 'onboard' ? 'staff.onboard' : 'staff.offboard'), 403);
        $step = collect($run->steps)->firstWhere('key', $key) ?? abort(404);
        abort_unless($step['kind'] === 'manual', 422);
        $done = $step['status'] !== 'done';
        $run->updateStep($key, $done ? 'done' : 'pending', null, $request->user()->name);
        ActivityLog::record('staff.checklist', $run->tenant, 'info', ($done ? 'Completed' : 'Reopened')." \"{$step['label']}\" for {$run->target_upn}");

        return back()->with('status', $done ? 'Marked as done.' : 'Reopened.');
    }

    public function cancelTask(Request $request, LifecycleTask $task)
    {
        Gate::authorize('access-tenant', $task->tenant);
        abort_unless($request->user()->can($task->run->type === 'onboard' ? 'staff.onboard' : 'staff.offboard'), 403);
        abort_unless($task->status === 'pending', 422);
        $task->update(['status' => 'cancelled', 'message' => 'Cancelled by '.$request->user()->name]);
        $task->run->updateStep(match ($task->action) {
            'enable_account' => 'enable', 'remove_licenses' => 'licences', 'offboard' => 'scheduled', default => 'delete_account',
        }, 'cancelled', 'Cancelled by '.$request->user()->name);
        ActivityLog::record('staff.task', $task->tenant, 'info', 'Cancelled: '.LifecycleTask::ACTIONS[$task->action].' for '.$task->run->target_upn);

        return back()->with('status', 'Scheduled task cancelled.');
    }

    public function deleteTemplate(Tenant $tenant, LifecycleTemplate $template)
    {
        abort_unless($template->tenant_id === $tenant->id, 404);
        $template->delete();

        return back()->with('status', 'Template deleted.');
    }
}
