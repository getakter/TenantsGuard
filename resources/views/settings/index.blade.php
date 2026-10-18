@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="page-head">
    <div><h1>Settings</h1><p>The Microsoft app used to connect tenants and sign in, white-label branding and permissions.</p></div>
</div>

{{-- ============================ Microsoft app ============================ --}}
<div class="card" id="microsoft">
    <div class="page-head" style="margin-bottom:8px">
        <div>
            <h2 style="margin:0">Microsoft app</h2>
            <p>One multi-tenant Entra app powers the "Connect with Microsoft" consent popup, "Sign in with Microsoft", and all scans. You set it up once.</p>
        </div>
        @if($ms['configured'])
            <span class="badge s-pass" style="font-size:13px">Connected</span>
        @else
            <span class="badge s-fail" style="font-size:13px">Not set up</span>
        @endif
    </div>

    @if($ms['configured'])
        <dl class="kv" style="margin-bottom:12px">
            <dt>Application (client) ID</dt><dd class="mono">{{ $ms['client_id'] }}</dd>
            <dt>Set up by</dt><dd>{{ ['wizard' => 'Automatic setup', 'settings' => 'Manual registration (saved here)', 'env' => '.env file (MS_CLIENT_ID / MS_CLIENT_SECRET)'][$ms['source']] ?? $ms['source'] }}</dd>
            @if($ms['home_tenant'])<dt>Home tenant</dt><dd class="mono">{{ $ms['home_tenant'] }}</dd>@endif
            <dt>Redirect URIs</dt><dd class="mono">@foreach($ms['redirects'] as $u){{ $u }}<br>@endforeach</dd>
        </dl>
        <div class="actions">
            <a class="btn btn-ms" href="{{ route('connect.microsoft') }}" data-ms-popup><span class="ms-logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Connect a tenant with Microsoft</a>
            @if($ms['source'] !== 'env')
                <form method="post" action="{{ route('ms-setup.forget') }}" data-confirm="Remove the saved app credentials from TenantsGuard? The app registration itself stays in Entra.">
                    @csrf @method('delete') <button class="btn btn-danger">Remove saved credentials</button>
                </form>
            @endif
        </div>
        <div class="option" id="ms-auto" data-mode="update" data-start="{{ route('ms-setup.start') }}" data-poll="{{ route('ms-setup.poll') }}" style="margin-top:14px">
            <h3 style="margin-top:0">Update app permissions</h3>
            <p class="muted" style="margin-top:0">New modules, such as Intune, need extra Microsoft Graph permissions. Sign in once to add every permission listed below to the existing app. Then use <strong>Re-grant consent</strong> on each connected tenant. Manually registered apps can add them in the Entra portal instead.</p>
            <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:10px">
                <input type="checkbox" id="ms-auto-write" checked> Include write permissions (quick fixes, baselines, Intune deployment)
            </label>
            <button type="button" class="btn btn-ms" id="ms-auto-start"><span class="ms-logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Sign in with Microsoft to update permissions</button>
            <div id="ms-auto-step" hidden style="margin-top:14px">
                <p style="margin:0 0 6px">1. Copy this code:</p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <span class="code-big" id="ms-auto-code">-</span>
                    <button type="button" class="btn btn-sm" id="ms-auto-copy">Copy</button>
                </div>
                <p style="margin:12px 0 6px">2. Open Microsoft sign-in, paste the code, sign in as an admin of <em>your</em> tenant and accept:</p>
                <button type="button" class="btn btn-primary" id="ms-auto-open">Open Microsoft sign-in</button>
            </div>
            <div id="ms-auto-msg" class="alert" hidden style="margin-top:12px"></div>
        </div>
        <details style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:600">Replace with a different app registration</summary>
            <div style="margin-top:12px">@include('settings._manual')</div>
        </details>
    @else
        <p>Choose whichever method suits you. Both create the same kind of app. Automatic setup simply does the portal steps for you.</p>
        <div class="grid grid-2">
            <div class="option recommended" id="ms-auto" data-start="{{ route('ms-setup.start') }}" data-poll="{{ route('ms-setup.poll') }}">
                <h3>Method A: Automatic setup <span class="badge s-info">Recommended</span></h3>
                <p>Sign in once with a <strong>Global Administrator</strong> (or Application Administrator) of <em>your own</em> tenant. TenantsGuard then creates the app registration, its secret and its permissions for you.</p>
                <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <input type="checkbox" id="ms-auto-write" checked> Include write permissions (needed for quick fixes and baselines)
                </label>
                <button type="button" class="btn btn-ms" id="ms-auto-start"><span class="ms-logo" aria-hidden="true"><i></i><i></i><i></i><i></i></span>Sign in with Microsoft to set up</button>

                <div id="ms-auto-step" hidden style="margin-top:14px">
                    <p style="margin:0 0 6px">1. Copy this code:</p>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <span class="code-big" id="ms-auto-code">-</span>
                        <button type="button" class="btn btn-sm" id="ms-auto-copy">Copy</button>
                    </div>
                    <p style="margin:12px 0 6px">2. Open Microsoft sign-in, paste the code, pick your account and accept:</p>
                    <button type="button" class="btn btn-primary" id="ms-auto-open">Open Microsoft sign-in</button>
                    <p class="help">This page continues by itself once you finish.</p>
                </div>
                <div id="ms-auto-msg" class="alert" hidden style="margin-top:12px"></div>
                <p class="help" style="margin-top:10px">Sign-in uses Microsoft's own "Microsoft Graph Command Line Tools" client and only asks for permission to create applications. TenantsGuard does not keep that token.</p>
            </div>

            <div class="option">
                <h3>Method B: Manual registration</h3>
                @include('settings._manual')
            </div>
        </div>
    @endif
</div>

{{-- ============================ Microsoft sign-in ============================ --}}
<div class="card">
    <h2>Sign in with Microsoft</h2>
    <p>Existing console users can always sign in with a Microsoft account that uses the same email. Users from the tenants below also get a console account automatically the first time they sign in.</p>
    <form method="post" action="{{ route('ms-setup.signin') }}">
        @csrf
        <div class="field">
            <label>Allowed sign-in tenants</label>
            <input type="text" name="signin_tenants" value="{{ old('signin_tenants', implode(', ', $ms['signin_tenants'])) }}" placeholder="yourmsp.com or tenant ID, comma separated">
            <div class="help">Usually only your own MSP tenant. Domains are converted to tenant IDs. Leave empty to allow existing users only.</div>
        </div>
        <div class="field">
            <label>Role for automatically created accounts</label>
            @php
                $defaultRole = \App\Models\Setting::find('ms_default_role')?->value ?: 'read_only';
            @endphp
            <select name="default_role">
                @foreach(\App\Models\Role::where('key', '!=', 'global_admin')->orderByDesc('is_system')->orderBy('id')->get() as $r)
                    <option value="{{ $r->key }}" @selected($defaultRole === $r->key)>{{ $r->name }}</option>
                @endforeach
            </select>
            <div class="help">New staff get this role with access to all tenants. Adjust it under Users afterwards.</div>
        </div>
        <button class="btn">Save</button>
    </form>
</div>

<div class="grid grid-2" style="margin-top:16px" id="health">
    <div class="card">
        <h2>System health</h2>
        <table>
            <tbody>
            @foreach($health as $h)
                <tr>
                    <td style="width:28px;font-size:18px">{!! $h['ok'] === true ? '<span style="color:var(--pass)">✓</span>' : ($h['ok'] === false ? '<span style="color:var(--fail)">✗</span>' : '<span class="muted">•</span>') !!}</td>
                    <td><strong>{{ $h['label'] }}</strong><div class="muted" style="font-size:13px">{{ $h['detail'] }}</div></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <details style="margin-top:10px">
            <summary style="cursor:pointer;font-weight:600">Run the scheduler on Windows</summary>
            <p class="help">Create a Windows Scheduled Task that runs every minute:</p>
            <pre class="evidence">C:\xampp\php\php.exe {{ base_path('artisan') }} schedule:run</pre>
            <p class="help">Or keep a terminal open with <code>php artisan schedule:work</code>. It rescans clients every {{ $interval }} h for drift and prunes history older than {{ $retention }} days.</p>
        </details>
    </div>

    <div class="card">
        <h2>Alert notifications</h2>
        <p class="muted">Get drift, scan failures, failed changes and consent problems in Microsoft Teams, Slack, or your PSA or automation tool.</p>
        @error('notify')<div class="alert alert-err">{{ $message }}</div>@enderror
        <form method="post" action="{{ route('settings.notifications') }}">
            @csrf
            <div class="field">
                <label>Webhook URL</label>
                <input type="url" name="notify_webhook_url" value="{{ old('notify_webhook_url', $notify['url']) }}" placeholder="https://…">
                <div class="help">Teams: in the channel choose Workflows, then "Post to a channel when a webhook request is received", and copy the URL. Leave empty to turn alerts off.</div>
            </div>
            <div class="field">
                <label>Format</label>
                <select name="notify_format">@foreach(\App\Services\Notifier::FORMATS as $k => $v)<option value="{{ $k }}" @selected($notify['format'] === $k)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="field">
                <label>Send alerts for</label>
                @foreach(\App\Services\Notifier::EVENTS as $k => $v)
                    <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="notify_events[]" value="{{ $k }}" @checked(in_array($k, $notify['events'], true))> {{ $v }}</label>
                @endforeach
            </div>
            <div class="actions">
                <button class="btn btn-primary">Save</button>
                <button class="btn" name="test" value="1">Save and send test</button>
            </div>
        </form>
    </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <div class="card">
        <h2>Branding (console and reports)</h2>
        <form method="post" action="{{ route('settings.update') }}">
            @csrf
            <div class="field"><label>Company name</label><input type="text" name="company_name" value="{{ old('company_name', $branding['company_name']) }}" required></div>
            <div class="field"><label>Accent colour</label><input type="text" name="accent_color" value="{{ old('accent_color', $branding['accent_color']) }}" pattern="#[0-9a-fA-F]{6}" required><div class="help">Hex, for example #0f6cbd (Microsoft Fluent blue). Used for buttons, links and selection; status colours stay fixed.</div></div>
            <div class="field"><label>Logo URL (optional)</label><input type="url" name="logo_url" value="{{ old('logo_url', $branding['logo_url']) }}"></div>
            <div class="field"><label>Report footer</label><input type="text" name="report_footer" value="{{ old('report_footer', $branding['report_footer']) }}"></div>
            <button class="btn btn-primary">Save</button>
        </form>
    </div>
    <div class="card">
        <h2>Scanning</h2>
        <dl class="kv">
            <dt>Auto rescan</dt><dd>Every {{ $interval }} h (keep <code>php artisan schedule:work</code> running)</dd>
            <dt>History kept</dt><dd>{{ $retention }} days</dd>
        </dl>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h2>Application permissions (app plan)</h2>
    <p class="muted">The app registration requests the permissions of the capabilities in the app plan. Change the plan under <a href="{{ route('access.index') }}">Permission scope</a>, then use <strong>Update app permissions</strong> above. For manual registration, add these under API permissions as <em>application</em> permissions.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Capability</th><th>Tier</th><th>Permissions</th><th>In plan</th></tr></thead>
            <tbody>
            @foreach(\App\Support\Access::CAPABILITIES as $key => [$name, $tier, $desc, $groles, $eroles])
                <tr>
                    <td><strong>{{ $name }}</strong><div class="help">{{ $desc }}</div></td>
                    <td><span class="badge {{ \App\Support\Access::TIERS[$tier][1] }}">{{ \App\Support\Access::TIERS[$tier][0] }}</span></td>
                    <td class="mono" style="font-size:12.5px">@foreach($groles as $r){{ $r }}<br>@endforeach @foreach($eroles as $r){{ $r }} <span class="muted">(Office 365 Exchange Online)</span><br>@endforeach</td>
                    <td>{{ in_array($key, $plan, true) ? 'Yes' : 'No' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
