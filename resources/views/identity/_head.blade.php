<div class="dash-head">@include('partials.icon', ['name' => 'users']) <span class="crumbs">Identity management</span> <span class="muted">›</span> {{ $tenant->name }}
    @if($tenant->isDemo())<span class="badge s-info">Demo</span>@endif
</div>
<div class="page-head" style="margin:6px 0 0">
    <div><h1>{{ $title }}</h1><p>{{ $subtitle }}</p></div>
    <div class="actions">
        <span class="muted" style="font-size:12px">Read {{ \Carbon\Carbon::parse($readAt)->diffForHumans() }}</span>
        <form method="post" action="{{ route('identity.refresh', $tenant) }}" class="inline">@csrf <button class="btn btn-sm">@include('partials.icon', ['name' => 'refresh', 'size' => 14]) Refresh</button></form>
        @can('staff.onboard')<a class="btn btn-sm btn-primary" href="{{ route('operations.onboard', $tenant) }}">@include('partials.icon', ['name' => 'plus', 'size' => 14]) Onboard user</a>@endcan
    </div>
</div>
<nav class="dash-tabs">
    <a href="{{ route('identity.users', $tenant) }}" class="{{ request()->routeIs('identity.users') ? 'active' : '' }}">Users</a>
    <a href="{{ route('identity.groups', $tenant) }}" class="{{ request()->routeIs('identity.groups', 'identity.group') ? 'active' : '' }}">Groups</a>
    <a href="{{ route('identity.guests', $tenant) }}" class="{{ request()->routeIs('identity.guests') ? 'active' : '' }}">Guests</a>
    <a href="{{ route('operations.tenant', $tenant) }}">Joiners and leavers</a>
</nav>
