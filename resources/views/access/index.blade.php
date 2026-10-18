@extends('layouts.app')
@section('title', 'Permission scope')

@section('content')
@php
    $caps = \App\Support\Access::CAPABILITIES;
    $tiers = \App\Support\Access::TIERS;
    $isGA = auth()->user()->isGlobalAdmin();
    $planRoles = \App\Support\Access::allRoles($plan);
    $secretDays = $secretExpires ? (int) now()->diffInDays($secretExpires, false) : null;
    $cell = function (array $s) {
        if (! $s['in_plan']) { return ['·', '', 'Not in the app plan']; }
        if ($s['extra']) { return ['Over', 's-fail', 'Granted in the client but not enabled: revoke it']; }
        if (! $s['enabled']) { return ['Off', 'is-off', 'Disabled in the access profile']; }
        return match ($s['grant']) {
            'granted' => ['On', 's-pass', 'Enabled and granted'],
            'partial' => ['Partial', 's-manual', 'Enabled, some permissions missing'],
            'missing' => ['Gap', 's-manual', 'Enabled but not granted in the client'],
            default => ['On ?', 's-info', 'Enabled; grant not checked yet'],
        };
    };
    $matrix = $rows->map(fn ($r) => array_map($cell, $r['status']))->all();
@endphp

<div class="page-head">
    <div><h1>Permission scope</h1><p>What TenantsGuard may do in each client, what each client granted, and the health of the app registration.</p></div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    <div class="card stat"><div class="label">Clients</div><div class="value">{{ $rows->count() }}</div></div>
    <div class="card stat"><div class="label">Tier 0 enabled</div><div class="value {{ $tier0 ? 'text-fail' : '' }}">{{ $tier0 }}</div><div class="help">clients with privileged capabilities</div></div>
    <div class="card stat"><div class="label">Over-granted</div><div class="value {{ $extra ? 'text-fail' : '' }}">{{ $extra }}</div><div class="help">clients granting more than their profile</div></div>
    <div class="card stat"><div class="label">App secret</div><div class="value {{ $secretDays !== null && $secretDays < 60 ? 'text-warn' : '' }}">{{ $secretDays === null ? '–' : ($secretDays < 0 ? 'Expired' : $secretDays.' d') }}</div><div class="help">{{ $secretExpires ? 'Expires '.$secretExpires->format('j M Y') : 'Expiry unknown' }}</div></div>
</div>

<div class="card" style="padding:0">
    <div class="card-title" style="padding:16px 16px 0">Clients × capabilities
        <small class="muted" style="font-weight:400">@if($unchecked){{ $unchecked }} {{ \Illuminate\Support\Str::plural('client', $unchecked) }} not checked yet: open the client and select Check grant.@endif</small></div>
    <div class="table-wrap">
        <table class="access-matrix">
            <thead><tr><th>Client</th>
                @foreach($caps as $key => [$name, $tier])<th title="{{ $tiers[$tier][0] }}"><span class="tier-dot tier-{{ $tier }}"></span>{{ $name }}</th>@endforeach
            </tr></thead>
            <tbody>
            @forelse($rows as $i => $row)
                <tr>
                    <td><a href="{{ route('access.tenant', $row['tenant']) }}"><strong>{{ $row['tenant']->name }}</strong></a>
                        @if($row['tenant']->isDemo())<span class="badge s-info">Demo</span>@endif
                        <div class="help">{{ $row['tenant']->access_profile === null ? 'Full (app plan)' : 'Custom profile' }}</div></td>
                    @foreach($matrix[$i] as $key => [$label, $cls, $tip])
                        <td><span class="badge {{ $cls }}" title="{{ $tip }}">{{ $label }}</span></td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($caps) + 1 }}" class="muted">No clients yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid-2" style="margin-top:16px">
    <div class="card">
        <h2>App permission plan</h2>
        <p class="muted" style="margin-top:0">Capabilities the TenantsGuard app registration requests ({{ count($planRoles) }} application permissions). Keep it to what your service actually offers: an unused permission in the plan is granted by every full consent.</p>
        <form method="post" action="{{ route('access.plan') }}">
            @csrf
            <fieldset @disabled(! $isGA) style="border:0;padding:0;margin:0">
                @foreach($caps as $key => [$name, $tier, $desc, $groles, $eroles, , $required])
                    <label class="plan-row">
                        <span class="switch"><input type="checkbox" name="caps[]" value="{{ $key }}" @checked(in_array($key, $plan, true)) @disabled($required)><span class="slider"></span></span>
                        <span><strong>{{ $name }}</strong> <span class="badge {{ $tiers[$tier][1] }}">{{ $tiers[$tier][0] }}</span>
                            <small class="muted">{{ count($groles) + count($eroles) }} {{ \Illuminate\Support\Str::plural('permission', count($groles) + count($eroles)) }}@if($eroles) · Exchange Online resource @endif</small></span>
                    </label>
                @endforeach
            </fieldset>
            @if($isGA)
                <div class="actions" style="margin-top:12px">
                    <button class="btn btn-primary" data-confirm="Change the app permission plan? Added capabilities need the app permissions updated under Settings and a new grant in each client. Removed capabilities stop being usable in every client at once.">Save app plan</button>
                    @can('settings.manage')<a class="btn" href="{{ route('settings.index') }}">Update app permissions in Settings</a>@endcan
                </div>
            @else
                <p class="help">Only a Global Administrator can change the app plan.</p>
            @endif
        </form>
    </div>

    <div class="card">
        <h2>Security recommendations</h2>
        <ul class="reco">
            <li><strong>Least privilege per client.</strong> Use the Read-only or Standard preset unless the client's contract covers more, and grant with the per-client script instead of full consent.</li>
            <li><strong>Tier 0 only when needed.</strong> Privileged operations and Exchange Online can change admin rights or wipe devices. Enable them for a limited time with a justification; they expire on their own.</li>
            <li><strong>Certificate instead of secret.</strong> Replace the client secret with a certificate credential stored in a key vault, and rotate it before it expires.</li>
            <li><strong>Protect the workload identity.</strong> In your own tenant, use Conditional Access for workload identities to restrict where the app can sign in from (Entra Workload ID licence).</li>
            <li><strong>GDAP for people, the app for automation.</strong> Technicians should use granular delegated admin (GDAP) relationships with time-bound roles; this app covers unattended automation.</li>
            <li><strong>Review quarterly.</strong> Check the matrix for over-granted clients and capabilities nobody uses, and record each review in the audit log.</li>
        </ul>
    </div>
</div>
@endsection
