@extends('layouts.app')
@section('title', 'Access and permissions: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'access'])
@php
    $caps = \App\Support\Access::CAPABILITIES;
    $tiers = \App\Support\Access::TIERS;
    $presets = \App\Support\Access::PRESETS;
    $canEdit = auth()->user()->can('tenants.manage');
    $isGA = auth()->user()->isGlobalAdmin();
    $selected = old('caps', $enabled);
    $currentPreset = old('preset', $preset);
    $presetCaps = collect($presets)->map(fn ($p) => $p[2] ?? $plan)->all();
    $enabledCount = collect($status)->where('enabled', true)->count();
    $gaps = collect($status)->filter(fn ($s) => $s['enabled'] && in_array($s['grant'], ['missing', 'partial'], true));
    $extra = collect($status)->where('extra', true);
    $tier0 = collect($status)->filter(fn ($s) => $s['enabled'] && $s['tier'] === 'privileged');
    $unknown = collect($status)->contains('grant', 'unknown');
    $grantBadge = [
        'granted' => ['Granted', 's-pass'], 'partial' => ['Partly granted', 's-manual'],
        'missing' => ['Not granted', 's-neutral'], 'unknown' => ['Not checked', 's-neutral'],
    ];
@endphp

<div class="access-layers">
    <div><span class="layer-n">1</span><strong>App plan</strong><span class="muted">What the TenantsGuard app may request at all. Set by a Global Administrator under Permission scope.</span></div>
    <div><span class="layer-n">2</span><strong>Access profile</strong><span class="muted">What TenantsGuard may do in this client. Checked before every change, even if more is granted.</span></div>
    <div><span class="layer-n">3</span><strong>Client grant</strong><span class="muted">What the client actually granted to the app, read from the app's access token.</span></div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    <div class="card stat"><div class="label">Enabled capabilities</div><div class="value">{{ $enabledCount }}<small class="muted" style="font-size:14px"> / {{ count($plan) }} in plan</small></div></div>
    <div class="card stat"><div class="label">Grant gaps</div><div class="value {{ $gaps->isNotEmpty() ? 'text-warn' : '' }}">{{ $unknown ? '–' : $gaps->count() }}</div></div>
    <div class="card stat"><div class="label">Over-granted</div><div class="value {{ $extra->isNotEmpty() ? 'text-fail' : '' }}">{{ $unknown ? '–' : $extra->count() }}</div></div>
    <div class="card stat"><div class="label">Tier 0 enabled</div><div class="value {{ $tier0->isNotEmpty() ? 'text-fail' : '' }}">{{ $tier0->count() }}</div></div>
</div>

@if($extra->isNotEmpty())
    <div class="alert alert-warn"><strong>Least privilege:</strong> the client granted permissions for {{ $extra->pluck('name')->implode(', ') }}, which this profile does not use. TenantsGuard will not use them, but anyone holding the app secret could. Download the grant script below to revoke them.</div>
@endif

<form method="post" action="{{ route('access.update', $tenant) }}" id="access-form">
    @csrf
    <fieldset @disabled(! $canEdit) style="border:0;padding:0;margin:0">
    <div class="card">
        <div class="card-title">Access profile <small class="muted" style="font-weight:400">Start from a preset, then fine-tune.</small></div>
        <div class="preset-grid">
            @foreach($presets as $key => [$pname, $pdesc])
                <label class="preset-card"><input type="radio" name="preset" value="{{ $key }}" @checked($currentPreset === $key)>
                    <span><strong>{{ $pname }}</strong><small class="muted">{{ $pdesc }}</small></span></label>
            @endforeach
            <label class="preset-card"><input type="radio" name="preset" value="custom" @checked($currentPreset === 'custom')>
                <span><strong>Custom</strong><small class="muted">Choose capabilities one by one.</small></span></label>
        </div>
        @error('caps')<div class="error-text" style="margin-top:8px">{{ $message }}</div>@enderror
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table class="access-table">
                <thead><tr><th style="width:56px">On</th><th>Capability</th><th>Used by</th><th>Grant in client</th><th style="width:170px">Time limit</th></tr></thead>
                <tbody>
                @foreach($caps as $key => [$name, $tier, $desc, $groles, $eroles, $features, $required])
                    @php
                        $st = $status[$key];
                        $inPlan = in_array($key, $plan, true);
                        $roleList = array_merge($groles, $eroles);
                        [$gl, $gc] = $grantBadge[$st['grant']];
                        if ($st['extra']) { [$gl, $gc] = ['Granted, not used', 's-fail']; }
                    @endphp
                    <tr class="{{ $inPlan ? '' : 'is-off' }}" data-tier="{{ $tier }}">
                        <td>
                            <span class="switch"><input type="checkbox" name="caps[]" value="{{ $key }}" data-cap @checked(in_array($key, (array) $selected, true)) @disabled($required || ! $inPlan) aria-label="{{ $name }}"><span class="slider"></span></span>
                            @if($required)<input type="hidden" name="caps[]" value="{{ $key }}">@endif
                        </td>
                        <td>
                            <strong>{{ $name }}</strong> <span class="badge {{ $tiers[$tier][1] }}">{{ $tiers[$tier][0] }}</span>
                            @if($required)<span class="chip">Always on</span>@endif
                            @unless($inPlan)<span class="chip">Not in app plan</span>@endunless
                            <div class="muted" style="font-size:13px;margin-top:2px">{{ $desc }}</div>
                            <details class="roles"><summary>{{ count($roleList) }} {{ \Illuminate\Support\Str::plural('permission', count($roleList)) }}</summary>
                                <ul>@foreach($roleList as $r)<li class="mono">{{ $r }} @if(in_array($r, $st['missing'], true) && $st['grant'] !== 'unknown')<span class="chip">missing</span>@endif</li>@endforeach</ul>
                            </details>
                        </td>
                        <td>@foreach($features as $f)<span class="chip">{{ $f }}</span> @endforeach</td>
                        <td><span class="badge {{ $gc }}">{{ $gl }}</span>
                            @if($st['grant'] === 'partial')<div class="help">{{ count($st['missing']) }} missing</div>@endif</td>
                        <td>
                            @if($tier === 'privileged')
                                <select name="expiry[{{ $key }}]" aria-label="Time limit for {{ $name }}">
                                    <option value="">No limit</option>
                                    @if($st['expires'])<option value="keep" selected>Until {{ \Carbon\Carbon::parse($st['expires'])->format('j M H:i') }}</option>@endif
                                    @foreach([1 => '1 day', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $dl)<option value="{{ $d }}" @selected(old("expiry.$key") == $d)>{{ $dl }}</option>@endforeach
                                </select>
                                @if($st['expires'] && ! $st['enabled'])<div class="help">Expired</div>@endif
                            @else
                                <span class="muted">–</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="reason-card">
        <div class="field" style="margin:0">
            <label for="reason">Justification</label>
            <textarea id="reason" name="reason" rows="2" maxlength="500" placeholder="Change ticket, who asked for it and why">{{ old('reason') }}</textarea>
            <div class="help">Recorded in the audit log. Required when enabling a privileged (Tier 0) capability{{ $isGA ? '' : ', which only a Global Administrator can do' }}. Prefer a time limit for Tier 0.</div>
            @error('reason')<div class="error-text">{{ $message }}</div>@enderror
        </div>
    </div>
    </fieldset>

    @if($canEdit)
        <div class="sticky-actions">
            <span class="muted">Disabling a capability takes effect immediately. Enabling one also needs the grant in the client.</span>
            <span style="flex:1"></span>
            <button class="btn btn-primary">Save access profile</button>
        </div>
    @endif
</form>

<div class="grid grid-2" style="margin-top:16px">
    <div class="card">
        <h2>Bring the client grant in line</h2>
        @if($tenant->isDemo())
            <p class="muted">Demo client: simulate what the client administrator would do.</p>
            @if($canEdit)
                <div class="actions">
                    <form method="post" action="{{ route('access.simulate', $tenant) }}">@csrf <input type="hidden" name="mode" value="profile"><button class="btn btn-primary">Simulate the grant script</button></form>
                    <form method="post" action="{{ route('access.simulate', $tenant) }}">@csrf <input type="hidden" name="mode" value="all"><button class="btn">Simulate full consent</button></form>
                </div>
            @endif
        @else
            <p><strong>Recommended: least-privilege grant script.</strong> A Global Administrator of the client runs it. It grants exactly the enabled capabilities and revokes everything else TenantsGuard holds. Preview first with <code>-WhatIf</code>.</p>
            @if($canEdit)
                <div class="actions">
                    <a class="btn btn-primary" href="{{ route('access.script', $tenant) }}">Download grant script (.ps1)</a>
                    @if($configured)<a class="btn" href="{{ route('access.consent', $tenant) }}" data-confirm="Grant every permission in the app plan? Full admin consent grants all {{ count(\App\Support\Access::allRoles($plan)) }} permissions in the app plan, more than this profile needs. Use the grant script for least privilege.">Full admin consent</a>@endif
                </div>
            @endif
            <p class="help">Full admin consent is quicker but grants the whole app plan; the access profile still limits what TenantsGuard does. For Exchange Online, run the script with <code>-AssignExchangeRole</code> as well.</p>
        @endif
    </div>
    <div class="card">
        <h2>Verify</h2>
        <p class="muted" style="margin-top:0">The check reads the roles in a fresh app-only access token. It changes nothing in the client.</p>
        <dl class="kv">
            <dt>Last checked</dt><dd>{{ $tenant->isDemo() ? 'Demo (live)' : ($checkedAt ? $checkedAt->diffForHumans() : 'Never') }}</dd>
            <dt>Permissions granted</dt><dd>{{ $unknown ? 'Unknown' : count(\App\Services\Access\TenantAccess::granted($tenant)) }}</dd>
        </dl>
        @if($canEdit && ! $tenant->isDemo())
            <form method="post" action="{{ route('access.check', $tenant) }}" data-busy="Checking…" style="margin-top:12px">@csrf <button class="btn">Check grant now</button></form>
        @endif
        @error('check')<div class="error-text">{{ $message }}</div>@enderror
    </div>
</div>

<script>
(function () {
  const form = document.getElementById('access-form');
  if (!form) return;
  const presets = {{ \Illuminate\Support\Js::from($presetCaps) }};
  const boxes = [...form.querySelectorAll('[data-cap]')];
  form.querySelectorAll('input[name=preset]').forEach((r) => r.addEventListener('change', () => {
    const caps = presets[r.value];
    if (!caps) return;
    boxes.forEach((b) => { if (!b.disabled) b.checked = caps.includes(b.value); });
  }));
  boxes.forEach((b) => b.addEventListener('change', () => {
    const custom = form.querySelector('input[name=preset][value=custom]');
    if (custom) custom.checked = true;
  }));
})();
</script>
@endsection
