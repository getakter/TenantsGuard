{{-- Client header + tab navigation shared by every tenant page. Params: $tenant, $active --}}
<div class="client-head">
    <div>
        <div class="crumbs"><a href="{{ route('dashboard') }}">Portfolio</a> / {{ $tenant->name }}</div>
        <div class="client-meta">
            @if($tenant->isDemo())<span class="badge s-info">Demo</span>@endif
            @unless($tenant->isReady())<span class="badge s-manual">Consent {{ $tenant->consent_status }}</span>@endunless
            <span class="muted">{{ $tenant->domain ?? $tenant->ms_tenant_id }}</span>
            @foreach($tenant->tags ?? [] as $tg)<span class="tag">{{ $tg }}</span>@endforeach
            @if($tenant->contact_name || $tenant->contact_email)
                <span class="muted">· Contact: {{ $tenant->contact_name }} @if($tenant->contact_email)<a href="mailto:{{ $tenant->contact_email }}">{{ $tenant->contact_email }}</a>@endif</span>
            @endif
        </div>
    </div>
</div>
<nav class="tabs client-tabs">
    <a href="{{ route('tenants.dashboard', $tenant) }}" class="tab {{ $active === 'dashboard' ? 'active' : '' }}">Dashboard</a>
    <a href="{{ route('tenants.show', $tenant) }}" class="tab {{ $active === 'posture' ? 'active' : '' }}">Security posture</a>
    <a href="{{ route('tenants.securescore', $tenant) }}" class="tab {{ $active === 'securescore' ? 'active' : '' }}">Microsoft Secure Score</a>
    <a href="{{ route('operations.tenant', $tenant) }}" class="tab {{ $active === 'people' ? 'active' : '' }}">People</a>
    <a href="{{ route('plans.rollout', $tenant) }}" class="tab {{ $active === 'plan' ? 'active' : '' }}">Control plan</a>
    <a href="{{ route('compliance.tenant', $tenant) }}" class="tab {{ $active === 'compliance' ? 'active' : '' }}">Framework scorecard</a>
    @can('intune.view')
        <a href="{{ route('tenants.intune', $tenant) }}" class="tab {{ $active === 'intune' ? 'active' : '' }}">Intune and devices</a>
    @endcan
    @can('alerts.view')
        <a href="{{ route('alerts.tenant', $tenant) }}" class="tab {{ $active === 'alerts' ? 'active' : '' }}">Security alerts</a>
    @endcan
    @can('licensing.view')
        <a href="{{ route('licensing.tenant', $tenant) }}" class="tab {{ $active === 'licensing' ? 'active' : '' }}">Licences</a>
    @endcan
    <a href="{{ route('access.tenant', $tenant) }}" class="tab {{ $active === 'access' ? 'active' : '' }}">Access and permissions</a>
    @can('tenants.manage')
        <a href="{{ route('tenants.edit', $tenant) }}" class="tab {{ $active === 'settings' ? 'active' : '' }}">Client settings</a>
    @endcan
</nav>
