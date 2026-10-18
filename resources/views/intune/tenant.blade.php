@extends('layouts.app')
@section('title', 'Intune: '.$tenant->name)
@use('App\Services\Intune\IntuneCatalog')
@use('App\Models\IntuneDeploymentItem')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'intune'])
<div class="page-head">
    <div>
        <h1>Intune: {{ $tenant->name }}</h1>
        <p>Device management readiness, policies in the tenant and TenantsGuard deployments.</p>
    </div>
    <div class="actions">
        @if(! empty($snap['read_at']))
            <small class="muted">Read from Microsoft {{ \Carbon\Carbon::parse($snap['read_at'])->diffForHumans() }}</small>
            <a class="btn" href="{{ route('tenants.intune', [$tenant, 'refresh' => 1]) }}">Refresh</a>
        @endif
    </div>
</div>

@if(! $snap['ready'])
    <div class="alert alert-warn">This tenant is not connected yet. Grant admin consent on the tenant page first.</div>
@else
    @php
        $lic = $snap['licenses'];
        $dev = $snap['devices'];
        $managed = collect($snap['policies'])->where('managed', true)->count();
    @endphp
    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="card stat"><div class="label">Licences</div>
            <div class="pills" style="margin-top:8px">
                @foreach(['intune' => 'Intune', 'p1' => 'Entra P1', 'p2' => 'Entra P2', 'mde' => 'Defender'] as $k => $l)
                    <span class="badge {{ ($lic[$k] ?? false) ? 's-pass' : 's-na' }}">{{ $l }}</span>
                @endforeach
            </div>
        </div>
        <div class="card stat"><div class="label">Managed devices</div><div class="value">{{ $dev['total'] }}</div>
            <small>{{ collect($dev['byOs'])->map(fn ($n, $os) => "{$n} {$os}")->implode(' · ') ?: 'None enrolled' }}</small></div>
        <div class="card stat"><div class="label">Non-compliant</div><div class="value" style="color:{{ $dev['noncompliant'] ? 'var(--fail)' : 'var(--pass)' }}">{{ $dev['noncompliant'] }}</div><small>{{ $dev['personal'] }} personal devices</small></div>
        <div class="card stat"><div class="label">Intune policies</div><div class="value">{{ count($snap['policies']) }}</div><small>{{ $managed }} managed by TenantsGuard</small></div>
    </div>

    @if(! empty($snap['denied']))
        <div class="alert alert-err">
            <strong>The TenantsGuard app is missing Intune permissions in this tenant.</strong>
            Microsoft refused {{ count($snap['denied']) }} Intune request(s), so the policy list and prerequisite checks below are incomplete.
            <ol style="margin:6px 0 0;padding-left:18px">
                <li>@can('settings.manage')<a href="{{ route('settings.index') }}#microsoft">Settings &gt; Microsoft app &gt; Update app permissions</a>@else Ask a Global Administrator to update the app permissions in Settings @endcan. This is done once for all tenants.</li>
                <li>Then click <strong>Re-grant consent</strong> on this tenant's page and accept the new permissions.</li>
            </ol>
        </div>
    @endif

    @unless($lic['intune'] ?? false)
        <div class="alert alert-warn">No Intune licence was detected. Device templates are skipped during deployment. Conditional Access templates still need Entra ID P1.</div>
    @endunless

    @can('intune.deploy')
        <div class="card">
            <h2>Deploy a bundle</h2>
            <form method="post" action="{{ route('tenants.intune.deploy', $tenant) }}" class="grid grid-3" style="align-items:end">
                @csrf
                <div class="field" style="margin:0">
                    <label>Bundle</label>
                    <select name="bundle_id">@foreach($bundles as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                </div>
                <label style="font-weight:400;display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="update_existing" value="0"><input type="checkbox" name="update_existing" value="1"> Update policies that already exist
                </label>
                <div class="actions">
                    <button class="btn" name="mode" value="preview">Preview</button>
                    <button class="btn btn-primary" name="mode" value="deploy" data-confirm-btn="Deploy the selected bundle to {{ $tenant->name }}?">Deploy</button>
                </div>
            </form>
        </div>
    @endcan

    <div class="grid grid-2" style="margin-top:16px">
        <div class="card">
            <h2>Prerequisites</h2>
            <table>
                <tbody>
                @foreach($snap['prereqs'] as $key => $p)
                    @php
                        $ok = $p['result']['ok'];
                    @endphp
                    <tr>
                        <td style="width:30px;font-size:18px">{!! $ok === true ? '<span style="color:var(--pass)">✓</span>' : ($ok === false ? '<span style="color:var(--fail)">✗</span>' : '<span class="muted">?</span>') !!}</td>
                        <td>
                            <strong>{{ $p['name'] }}</strong> <span class="chip chip-platform">{{ IntuneCatalog::PLATFORMS[$p['platform']] }}</span>
                            <div class="muted" style="font-size:13px">{{ $p['result']['detail'] }}</div>
                            @if($ok !== true)
                                <details style="font-size:13px"><summary style="cursor:pointer">How to fix</summary><ol style="padding-left:18px;margin:6px 0">@foreach($p['steps'] as $s)<li>{{ $s }}</li>@endforeach</ol></details>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>TenantsGuard templates in this tenant</h2>
            @if($snap['latest']->isEmpty())
                <p class="muted">Nothing deployed yet.</p>
            @else
                <table>
                    <tbody>
                    @foreach($snap['latest']->filter(fn ($i) => ($catalog[$i->template]['kind'] ?? 'manual') !== 'manual') as $item)
                        <tr>
                            <td class="mono">{{ $item->template }}</td>
                            <td>{{ $catalog[$item->template]['name'] ?? $item->display_name }}<br><small class="muted">{{ $item->assignment }} · {{ $item->updated_at->diffForHumans() }}</small></td>
                            <td><span class="badge {{ IntuneDeploymentItem::STATUS_LABELS[$item->status][1] ?? 's-na' }}">{{ IntuneDeploymentItem::STATUS_LABELS[$item->status][0] ?? $item->status }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>All Intune policies in the tenant</h2>
        <div class="table-wrap" style="max-height:420px;overflow:auto">
            <table>
                <thead><tr><th>Type</th><th>Name</th><th>Source</th><th>Last modified</th></tr></thead>
                <tbody>
                @forelse(collect($snap['policies'])->sortBy(['type', 'name']) as $pol)
                    <tr>
                        <td>{{ $pol['type'] }}</td>
                        <td>{{ $pol['name'] }}</td>
                        <td>@if($pol['managed'])<span class="badge s-info">TenantsGuard</span>@else<span class="badge s-na">Existing</span>@endif</td>
                        <td class="muted">{{ $pol['modified'] ? \Carbon\Carbon::parse($pol['modified'])->diffForHumans() : '–' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No Intune policies found. If you expected some, check that the app has the Intune read permissions.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card" style="margin-top:16px">
    <h2>Deployment history</h2>
    <table>
        <thead><tr><th>When</th><th>Bundle</th><th>Mode</th><th>Result</th><th>By</th><th></th></tr></thead>
        <tbody>
        @forelse($history as $d)
            <tr>
                <td>{{ $d->created_at->diffForHumans() }}</td>
                <td>{{ $d->bundle_name }}</td>
                <td>{{ $d->dry_run ? 'Preview' : 'Deploy' }}</td>
                <td>@foreach($d->summary ?? [] as $k => $n)<span class="badge {{ IntuneDeploymentItem::STATUS_LABELS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(IntuneDeploymentItem::STATUS_LABELS[$k][0] ?? $k) }}</span> @endforeach</td>
                <td class="muted">{{ $d->user?->name ?? 'System' }}</td>
                <td><a href="{{ route('intune.run', $d->batch) }}">Details</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">No deployments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
