@php
    $brand = \App\Models\Setting::branding();
    $me = auth()->user();
    $routeTenant = request()->route('tenant');
    if ($routeTenant instanceof \App\Models\Tenant) {
        session(['ctx_tenant' => $routeTenant->id]);
    }
    $clients = $me->visibleTenants()->orderBy('name')->get(['tenants.id', 'tenants.name', 'tenants.domain']);
    $ctx = $routeTenant instanceof \App\Models\Tenant ? $routeTenant : $clients->firstWhere('id', session('ctx_tenant'));
    $pick = request()->routeIs('identity.pick') ? request()->route('action') : null;
    $is = fn (...$names) => request()->routeIs(...$names);
    $groups = [
        ['Portfolio', 'grid', [[ 'All clients', route('dashboard'), $is('dashboard'), true ]]],
        [$ctx ? $ctx->name : 'Client', 'building', $ctx ? [
            ['Dashboard', route('tenants.dashboard', $ctx), $is('tenants.dashboard'), true],
            ['Security posture', route('tenants.show', $ctx), $is('tenants.show'), true],
            ['Microsoft Secure Score', route('tenants.securescore', $ctx), $is('tenants.securescore'), true],
            ['People', route('operations.tenant', $ctx), $is('operations.tenant', 'operations.user'), true],
            ['Control plan', route('plans.rollout', $ctx), $is('plans.*'), true],
            ['Framework scorecard', route('compliance.tenant', $ctx), $is('compliance.tenant'), true],
            ['Intune and devices', route('tenants.intune', $ctx), $is('tenants.intune', 'tenants.intune.*'), $me->can('intune.view')],
            ['Access and permissions', route('access.tenant', $ctx), $is('access.tenant'), true],
            ['Client settings', route('tenants.edit', $ctx), $is('tenants.edit'), $me->can('tenants.manage')],
        ] : []],
        ['Identity Management', 'users', [
            ['Users', $ctx ? route('identity.users', $ctx) : route('identity.pick', 'users'), $is('identity.users', 'operations.user') || $pick === 'users', $me->can('tenants.view')],
            ['Groups', $ctx ? route('identity.groups', $ctx) : route('identity.pick', 'groups'), $is('identity.groups', 'identity.group') || $pick === 'groups', $me->can('tenants.view')],
            ['Guests', $ctx ? route('identity.guests', $ctx) : route('identity.pick', 'guests'), $is('identity.guests') || $pick === 'guests', $me->can('tenants.view')],
            ['Onboard user', route('identity.pick', 'onboard'), $is('operations.onboard', 'operations.onboard.run') || $pick === 'onboard', $me->can('staff.onboard')],
            ['Bulk onboard users', route('identity.pick', 'bulk'), $is('operations.bulk*') || $pick === 'bulk', $me->can('staff.onboard')],
            ['Offboard users', route('identity.pick', 'offboard'), $is('operations.offboard*') || $pick === 'offboard', $me->can('staff.offboard')],
            ['User templates', route('templates.index'), $is('templates.*') || $pick === 'templates', $me->can('staff.onboard')],
            ['Lifecycle activity', route('operations.index'), $is('operations.index', 'operations.run'), true],
        ]],
        ['Data security (Purview)', 'lock', [
            ['Purview programmes', route('purview.index'), $is('purview.*'), $me->can('purview.view')],
        ]],
        ['Microsoft 365 Copilot', 'apps', [
            ['Copilot programmes', route('copilot.index'), $is('copilot.*'), $me->can('copilot.view')],
        ]],
        ['Security & Compliance', 'shield', [
            ['Control plans', route('compliance.index'), $is('compliance.index', 'compliance.profiles.*', 'compliance.framework', 'compliance.portfolio'), true],
            ['Microsoft 365 baselines', route('baselines.index'), $is('baselines.*'), true],
            ['Controls and frameworks', route('controls.index'), $is('controls.*'), true],
        ]],
        ['Intune', 'laptop', [
            ['Intune bundles', route('intune.index'), $is('intune.*'), $me->can('intune.view')],
        ]],
        ['Tools', 'terminal', [
            ['PowerShell scripts', route('scripts.index'), $is('scripts.index', 'scripts.show', 'scripts.create', 'scripts.edit', 'scripts.run'), $me->can('scripts.view')],
            ['Script runs and results', route('scripts.runs'), $is('scripts.runs', 'scripts.runs.*'), $me->can('scripts.view')],
        ]],
        ['Monitor', 'pulse', [
            ['Activity and audit', route('activity.index'), $is('activity.*'), $me->can('activity.view')],
            ['Permission scope', route('access.index'), $is('access.index'), true],
        ]],
        ['TenantsGuard', 'cog', [
            ['Users', route('users.index'), $is('users.*'), $me->can('users.manage')],
            ['Roles & permissions', route('roles.index'), $is('roles.*'), $me->can('users.manage')],
            ['Settings', route('settings.index'), $is('settings.*'), $me->can('settings.manage')],
            ['My profile', route('profile.show'), $is('profile.*', 'mfa.*'), true],
        ]],
    ];
    $alerts = \App\Http\Controllers\TenantDashboardController::unreadCount($me);
@endphp
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $brand['company_name'] }}</title>
    <script>try { document.documentElement.dataset.theme = localStorage.getItem('tgTheme') || 'dark'; } catch (e) {}</script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=8">
    <style>:root { --accent: {{ $brand['accent_color'] }}; }</style>
</head>
<body class="app">
<header class="topbar">
    <button class="icon-btn only-mobile" type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu">@include('partials.icon', ['name' => 'menu'])</button>
    <a class="brand" href="{{ route('dashboard') }}">
        @if($brand['logo_url'])
            <img src="{{ $brand['logo_url'] }}" alt="" style="height:28px;border-radius:6px">
        @else
            <span class="brand-mark"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg></span>
        @endif
        <span class="brand-name">{{ $brand['company_name'] }}</span>
    </a>
    <span class="topbar-sep"></span>
    <label class="client-switch" title="Switch client">
        @include('partials.icon', ['name' => 'building', 'size' => 18])
        <select onchange="if (this.value) location.href = this.value" aria-label="Client">
            <option value="{{ route('dashboard') }}">* All clients ({{ $clients->count() }})</option>
            @foreach($clients as $c)
                <option value="{{ route('tenants.dashboard', $c->id) }}" @selected($routeTenant instanceof \App\Models\Tenant && $routeTenant->id === $c->id)>{{ $c->name }}{{ $c->domain ? ' ('.$c->domain.')' : '' }}</option>
            @endforeach
        </select>
    </label>
    @if($routeTenant instanceof \App\Models\Tenant && $me->can('tenants.scan') && $routeTenant->isReady())
        <form method="post" action="{{ route('tenants.dashboard.refresh', $routeTenant) }}" data-busy="…" class="inline">@csrf
            <button class="icon-btn" title="Refresh data for {{ $routeTenant->name }} (re-run all tests)">@include('partials.icon', ['name' => 'refresh'])</button>
        </form>
    @endif
    <span style="flex:1"></span>

    <button class="icon-btn" type="button" id="themeBtn" title="Switch light / dark">@include('partials.icon', ['name' => 'sun'])</button>

    <div class="pop" id="searchPop">
        <button class="icon-btn" type="button" data-pop="searchPop" title="Search people across clients">@include('partials.icon', ['name' => 'search'])</button>
        <div class="pop-panel pop-wide">
            <input type="text" id="searchInput" placeholder="Search people in all clients…" autocomplete="off">
            <div id="searchResults" class="pop-list"><div class="pop-empty">Type a name, email or job title.</div></div>
        </div>
    </div>

    <div class="pop" id="bellPop">
        <button class="icon-btn" type="button" data-pop="bellPop" title="Alerts">@include('partials.icon', ['name' => 'bell'])@if($alerts)<span class="dot">{{ $alerts > 9 ? '9+' : $alerts }}</span>@endif</button>
        <div class="pop-panel pop-wide">
            <div class="pop-title">Alerts (last 7 days)</div>
            <div id="bellList" class="pop-list"><div class="pop-empty">Loading…</div></div>
            @can('activity.view')<a class="pop-foot" href="{{ route('activity.index') }}">Open activity and audit</a>@endcan
        </div>
    </div>

    <div class="pop" id="userPop">
        <button class="user-chip" type="button" data-pop="userPop">
            <span class="avatar">{{ strtoupper(mb_substr($me->name, 0, 1)) }}</span>
            <span class="user-meta"><small>{{ $me->role?->name }}</small><span>{{ $me->email }}</span></span>
            @include('partials.icon', ['name' => 'down', 'size' => 16])
        </button>
        <div class="pop-panel">
            <a href="{{ route('profile.show') }}">My profile and MFA</a>
            @can('settings.manage')<a href="{{ route('settings.index') }}">Settings</a>@endcan
            <form method="post" action="{{ route('logout') }}">@csrf <button>Sign out</button></form>
        </div>
    </div>
</header>

<div class="shell">
    <aside class="sidebar">
        <nav class="nav">
            @foreach($groups as [$label, $icon, $items])
                @php
                    $items = array_values(array_filter($items, fn ($i) => $i[3]));
                    $open = collect($items)->contains(fn ($i) => $i[2]);
                @endphp
                @continue(! $items)
                @if(count($items) === 1 && $label === 'Portfolio')
                    <a href="{{ $items[0][1] }}" class="nav-top {{ $open ? 'active' : '' }}">@include('partials.icon', ['name' => $icon]) <span>{{ $items[0][0] }}</span></a>
                @else
                    <details class="nav-group" @if($open || ($label !== 'TenantsGuard' && $loop->index === 1)) open @endif>
                        <summary>@include('partials.icon', ['name' => $icon]) <span>{{ $label }}</span>@include('partials.icon', ['name' => 'chevron', 'size' => 14])</summary>
                        @foreach($items as [$text, $url, $active])
                            <a href="{{ $url }}" class="{{ $active ? 'active' : '' }}">{{ $text }}</a>
                        @endforeach
                    </details>
                @endif
            @endforeach
        </nav>
        <div class="sidebar-foot">
            <span>{{ $clients->count() }} client(s)@unless($me->seesAllTenants()) assigned @endunless</span>
        </div>
    </aside>
    <main class="main">
        @if(session('status'))
            <div class="alert alert-ok">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-err">
                @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif
        @yield('content')
    </main>
</div>
<script>
window.TG = { search: @json(route('search.users')), notifications: @json(route('notifications')) };
</script>
<script src="{{ asset('js/app.js') }}?v=8"></script>
@stack('scripts')
</body>
</html>
