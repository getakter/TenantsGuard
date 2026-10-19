@extends('layouts.app')
@section('title', 'Portfolio')

@section('content')
<div class="page-head">
    <div>
        <h1>Client portfolio</h1>
        <p>{{ auth()->user()->seesAllTenants() ? 'Every Microsoft 365 tenant you manage' : 'The tenants assigned to you' }}: posture, control plan progress and what needs attention.</p>
    </div>
    <div class="actions">
        @if(count($rows) && auth()->user()->can('tenants.scan'))
            <form method="post" action="{{ route('tenants.scan-all') }}" data-busy="Scanning…">@csrf <button class="btn">Scan all</button></form>
        @endif
        @can('tenants.manage')
            @php
                $msReady = \App\Support\MicrosoftApp::configured();
            @endphp
            @include('partials.ms-button', ['href' => $msReady ? route('connect.microsoft') : route('tenants.create'), 'label' => 'Connect a client', 'disabled' => false, 'popup' => $msReady])
            <a class="btn" href="{{ route('tenants.create') }}">Other ways to add</a>
        @endcan
    </div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    <div class="card stat"><div class="label">Clients</div><div class="value">{{ $totals['tenants'] }}</div>@if($totals['pending'])<small>{{ $totals['pending'] }} awaiting consent</small>@endif</div>
    <div class="card stat">
        <div class="label">Average scores</div>
        <div style="display:flex;gap:18px;align-items:baseline">
            <div><div class="value">{{ $totals['avg'] === null ? '–' : $totals['avg'].'%' }}</div><small>TenantsGuard posture</small></div>
            <div><div class="value" style="color:{{ $totals['secure'] === null ? 'var(--muted)' : ($totals['secure'] >= 80 ? 'var(--pass)' : ($totals['secure'] >= 50 ? 'var(--warn)' : 'var(--fail)')) }}">{{ $totals['secure'] === null ? '–' : $totals['secure'].'%' }}</div><small>Microsoft Secure Score</small></div>
        </div>
    </div>
    <div class="card stat"><div class="label">Critical gaps</div><div class="value" style="color:{{ $totals['critical'] ? 'var(--critical)' : 'var(--pass)' }}">{{ $totals['critical'] }}</div></div>
    <div class="card stat"><div class="label">Drifted controls</div><div class="value" style="color:{{ $totals['drift'] ? 'var(--fail)' : 'var(--pass)' }}">{{ $totals['drift'] }}</div><small>since previous scans</small></div>
</div>

@if($attention)
    <div class="card" style="margin-bottom:16px">
        <div class="page-head" style="margin-bottom:6px">
            <h2 style="margin:0">Needs attention ({{ count($attention) }})</h2>
            @if(count($attention) > 8)<button type="button" class="linkbtn" onclick="document.querySelectorAll('.att-more').forEach(e => e.hidden = !e.hidden)">Show all</button>@endif
        </div>
        <table>
            <tbody>
            @foreach($attention as $i => $a)
                <tr class="{{ $i >= 8 ? 'att-more' : '' }}" @if($i >= 8) hidden @endif>
                    <td style="width:90px"><span class="badge {{ ['critical' => 'sev-critical', 'warning' => 's-manual', 'info' => 's-info'][$a['level']] }}">{{ ucfirst($a['level']) }}</span></td>
                    <td style="width:200px"><strong>{{ $a['tenant'] ?? 'Platform' }}</strong></td>
                    <td>{{ $a['text'] }}</td>
                    <td style="width:70px"><a href="{{ $a['url'] }}">Open</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if(! count($rows))
    <div class="card empty">
        <h2>No clients {{ auth()->user()->seesAllTenants() ? 'yet' : 'assigned to you' }}</h2>
        @can('tenants.manage')
            <p>Connect a client's Microsoft 365 tenant with the consent popup, or add a demo tenant to try everything without Azure.</p>
            <div class="actions" style="justify-content:center">
                <a class="btn btn-primary" href="{{ route('tenants.create') }}">Add your first client</a>
                <form method="post" action="{{ route('tenants.demo') }}" data-busy="Creating…">@csrf <button class="btn">Add demo tenant</button></form>
            </div>
        @else
            <p>Ask a Global Administrator to assign clients to your account.</p>
        @endcan
    </div>
@else
    <div class="filters" id="pf-filters">
        <input type="text" data-pf="q" placeholder="Search clients, domains, tags…">
        @if($tags)
            <select data-pf="tag"><option value="">All tags</option>@foreach($tags as $tg)<option value="{{ strtolower($tg) }}">{{ $tg }}</option>@endforeach</select>
        @endif
        <select data-pf="plan"><option value="">All control plans</option><option value="none">No control plan</option>@foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
        <select data-pf="state"><option value="">All clients</option><option value="attention">Needs attention</option><option value="pending">Awaiting consent</option></select>
    </div>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table id="pf-table">
                <thead><tr><th>Client</th><th title="TenantsGuard posture score">Posture</th><th title="Microsoft Secure Score">Secure Score</th><th>Critical / high</th><th>Drift</th><th title="Open security alerts">Alerts</th><th>Control plan</th><th>Last scan</th><th></th></tr></thead>
                <tbody>
                @foreach($rows as $r)
                    @php
                        $t = $r['tenant'];
                        $needs = ! $t->isReady() || $r['drift'] || $r['critical'] || $r['errors'] || $r['stale'] || $r['alerts_urgent'];
                    @endphp
                    <tr data-q="{{ strtolower($t->name.' '.$t->domain.' '.implode(' ', $t->tags ?? [])) }}" data-tags="{{ strtolower(implode('|', $t->tags ?? [])) }}"
                        data-plan="{{ $r['plan']?->id ?? 'none' }}" data-attention="{{ $needs ? 1 : 0 }}" data-pending="{{ $t->isReady() ? 0 : 1 }}">
                        <td>
                            <a href="{{ route('tenants.dashboard', $t) }}"><strong>{{ $t->name }}</strong></a>
                            @if($t->isDemo())<span class="badge s-info">Demo</span>@endif
                            <br><small class="muted">{{ $t->domain ?? $t->ms_tenant_id }}</small>
                            @foreach($t->tags ?? [] as $tg)<span class="tag">{{ $tg }}</span>@endforeach
                        </td>
                        <td style="white-space:nowrap">
                            @if(! $t->isReady())<span class="badge s-manual">Consent {{ $t->consent_status }}</span>
                            @elseif($r['score'] === null)<span class="muted">Not scanned</span>
                            @else
                                <strong style="color:{{ $r['score'] >= 80 ? 'var(--pass)' : ($r['score'] >= 50 ? 'var(--warn)' : 'var(--fail)') }}">{{ $r['score'] }}%</strong>
                                @if($r['delta'])<small style="color:{{ $r['delta'] > 0 ? 'var(--pass)' : 'var(--fail)' }}">{{ $r['delta'] > 0 ? '▲' : '▼' }}{{ abs($r['delta']) }}</small>@endif
                            @endif
                        </td>
                        <td style="white-space:nowrap">
                            @if($r['secure'] !== null)
                                <a href="{{ route('tenants.securescore', $t) }}" style="color:{{ $r['secure'] >= 70 ? 'var(--pass)' : ($r['secure'] >= 40 ? 'var(--warn)' : 'var(--fail)') }};font-weight:700">{{ round($r['secure']) }}%</a>
                                @if($r['secure_delta'])<small style="color:{{ $r['secure_delta'] > 0 ? 'var(--pass)' : 'var(--fail)' }}">{{ $r['secure_delta'] > 0 ? '▲' : '▼' }}{{ abs($r['secure_delta']) }}</small>@endif
                            @elseif($r['secure_error'])<span class="muted" title="{{ $r['secure_error'] }}">n/a</span>
                            @else<span class="muted">–</span>@endif
                        </td>
                        <td>@if($r['critical'])<span class="badge sev-critical">{{ $r['critical'] }}</span>@endif @if($r['high'])<span class="badge sev-high">{{ $r['high'] }}</span>@endif @if(! $r['critical'] && ! $r['high'] && $r['score'] !== null)<span class="muted">–</span>@endif</td>
                        <td>@if($r['drift'])<span class="badge s-fail">{{ $r['drift'] }}</span>@else<span class="muted">–</span>@endif</td>
                        <td>@if($r['alerts'])<a href="{{ route('alerts.tenant', $t) }}" class="badge {{ $r['alerts_urgent'] ? 's-fail' : 's-manual' }}" title="{{ $r['alerts_urgent'] }} critical or high">{{ $r['alerts'] }}</a>@else<span class="muted">–</span>@endif</td>
                        <td style="min-width:180px">
                            @if($r['plan'])
                                <a href="{{ route('plans.rollout', $t) }}">{{ \Illuminate\Support\Str::limit($r['plan']->name, 34) }}</a>
                                @if($r['progress'] !== null)
                                    <div style="display:flex;gap:6px;align-items:center"><div class="bar" style="width:90px"><span class="p" style="width:{{ $r['progress'] }}%"></span></div><small>{{ $r['progress'] }}%</small></div>
                                @endif
                            @else
                                <a class="muted" href="{{ route('plans.rollout', $t) }}">Set up</a>
                            @endif
                        </td>
                        <td style="white-space:nowrap" class="{{ $r['stale'] ? '' : 'muted' }}">@if($r['stale'])<span style="color:var(--warn)">⚠</span>@endif {{ $r['scanned']?->diffForHumans() ?? '–' }}</td>
                        <td><a class="btn btn-sm" href="{{ route('tenants.dashboard', $t) }}">Open</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="help"><span id="pf-count">{{ count($rows) }}</span> of {{ count($rows) }} clients shown.</p>
@endif

<script>
(function () {
    const rows = [...document.querySelectorAll('#pf-table tbody tr')];
    const ctl = document.querySelectorAll('[data-pf]');
    function apply() {
        const f = {}; ctl.forEach((c) => (f[c.dataset.pf] = c.value.toLowerCase()));
        let n = 0;
        rows.forEach((r) => {
            const d = r.dataset;
            const ok = (!f.q || d.q.includes(f.q)) && (!f.tag || d.tags.split('|').includes(f.tag)) && (!f.plan || d.plan === f.plan)
                && (!f.state || (f.state === 'attention' ? d.attention === '1' : d.pending === '1'));
            r.hidden = !ok; if (ok) n++;
        });
        const c = document.getElementById('pf-count'); if (c) c.textContent = n;
        try { localStorage.setItem('tg-pf', JSON.stringify(f)); } catch (e) {}
    }
    try { const s = JSON.parse(localStorage.getItem('tg-pf') || '{}'); ctl.forEach((c) => { if (s[c.dataset.pf] !== undefined) c.value = s[c.dataset.pf]; }); } catch (e) {}
    ctl.forEach((c) => c.addEventListener('input', apply));
    apply();
})();
</script>
@endsection
