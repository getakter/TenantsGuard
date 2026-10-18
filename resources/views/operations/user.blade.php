@extends('layouts.app')
@section('title', $profile['displayName'].' · '.$tenant->name)

@section('content')
@php
    $u = $profile;
    $snap = $snapshot;
@endphp
<div class="dash-head">@include('partials.icon', ['name' => 'user']) <a class="crumbs" href="{{ route('operations.tenant', $tenant) }}">{{ $tenant->name }} · People</a> <span class="muted">›</span> {{ $u['displayName'] }}</div>

<div class="page-head" style="margin-top:12px">
    <div style="display:flex;gap:14px;align-items:center">
        <span class="avatar" style="width:52px;height:52px;font-size:1.3rem">{{ strtoupper(mb_substr($u['displayName'], 0, 1)) }}</span>
        <div>
            <h1 style="margin:0">{{ $u['displayName'] }} @unless($u['accountEnabled'] ?? true)<span class="badge s-na">Sign-in blocked</span>@endunless</h1>
            <p>{{ $u['userPrincipalName'] }}{{ ! empty($u['jobTitle']) ? ' · '.$u['jobTitle'] : '' }}{{ ! empty($u['department']) ? ' · '.$u['department'] : '' }}</p>
        </div>
    </div>
    <div class="actions">
        @can('staff.onboard')<a class="btn" href="{{ route('templates.create', [$tenant, 'from_user' => $u['id']]) }}">Make a template from this person</a>@endcan
        @can('staff.offboard')<a class="btn btn-solid-danger" href="{{ route('operations.offboard', [$tenant, 'users' => [$u['id']]]) }}">Offboard</a>@endcan
    </div>
</div>

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'user']) Profile</div>
        <dl class="kv">
            <dt>Email</dt><dd>{{ $u['mail'] ?? '–' }}</dd>
            <dt>Manager</dt><dd>{{ $manager['displayName'] ?? '–' }}</dd>
            <dt>Office</dt><dd>{{ $u['officeLocation'] ?? '–' }}</dd>
            <dt>Company</dt><dd>{{ $u['companyName'] ?? '–' }}</dd>
            <dt>Mobile</dt><dd>{{ $u['mobilePhone'] ?? '–' }}</dd>
            <dt>Business phone</dt><dd>{{ $u['businessPhones'][0] ?? '–' }}</dd>
            <dt>Location</dt><dd>{{ trim(($u['city'] ?? '').' '.($u['country'] ?? '')) ?: '–' }} @if(! empty($u['usageLocation']))<small class="muted">(usage {{ $u['usageLocation'] }})</small>@endif</dd>
            <dt>Created</dt><dd>{{ ! empty($u['createdDateTime']) ? \Carbon\Carbon::parse($u['createdDateTime'])->toFormattedDateString() : '–' }}</dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'key']) Sign-in and MFA</div>
        @forelse($snap['methods'] as $m)
            <div style="padding:5px 0;border-bottom:1px solid var(--border)">{{ \Illuminate\Support\Str::of($m['type'])->afterLast('.')->replace('AuthenticationMethod', '')->headline() }}</div>
        @empty
            <p class="muted">No methods could be read.</p>
        @endforelse
        @if(count($snap['methods']) <= 1)<div class="alert alert-warn" style="margin-top:12px">No MFA method registered. This person cannot complete an MFA challenge.</div>@endif
        @if($snap['roles'])
            <div class="label-sm" style="margin-top:14px">Admin roles</div>
            @foreach($snap['roles'] as $r)<span class="badge sev-high">{{ $r['name'] }}</span> @endforeach
        @endif
    </div>
</div>

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'licence']) Licences ({{ count($snap['licences']) }})</div>
        @forelse($snap['licences'] as $l)<div style="padding:5px 0;border-bottom:1px solid var(--border)">{{ $l['name'] }}</div>@empty<p class="muted">None.</p>@endforelse
        <div class="card-title" style="margin-top:18px">@include('partials.icon', ['name' => 'laptop']) Devices ({{ count($snap['devices']) }})</div>
        @forelse($snap['devices'] as $d)<div style="padding:5px 0;border-bottom:1px solid var(--border)">{{ $d['name'] }} <small class="muted">{{ $d['os'] }} · {{ $d['owner'] }}</small></div>@empty<p class="muted">No Intune-managed devices.</p>@endforelse
    </div>
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'group']) Groups and Teams ({{ count($snap['groups']) }})</div>
        @forelse($snap['groups'] as $g)<div style="padding:5px 0;border-bottom:1px solid var(--border)">{{ $g['name'] }}@if($g['dynamic']) <small class="muted">(dynamic)</small>@endif @if(collect($snap['owned'] ?? [])->contains('id', $g['id']))<span class="chip">Owner</span>@endif</div>@empty<p class="muted">None.</p>@endforelse
        @if($snap['rules'] ?? [])
            <div class="card-title" style="margin-top:18px">Inbox rules ({{ count($snap['rules']) }})</div>
            @foreach($snap['rules'] as $r)<div style="padding:5px 0">{{ $r['name'] }} @if($r['forwards'])<span class="badge sev-high">Forwards mail</span>@endif</div>@endforeach
        @endif
    </div>
</div>
@endsection
