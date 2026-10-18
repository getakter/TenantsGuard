@extends('layouts.app')
@section('title', 'Secure Score: '.$tenant->name)
@use('App\Models\SecureScoreSnapshot')
@use('App\Services\Compliance\ComplianceEngine')
@use('App\Services\Compliance\FrameworkRegistry')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'securescore'])

@include('securescore._lenses', ['tenant' => $tenant, 'snap' => $snap, 'prev' => $prev, 'compliance' => $compliance, 'plan' => $plan])

@if($snap && $snap->ok())
    @php
        $open = $actions->filter(fn ($a) => in_array($a['status'], ['to_address', 'partial'], true));
        $helpable = $open->filter(fn ($a) => $a['links']);
    @endphp
    <div class="grid grid-4" style="margin:16px 0">
        <div class="card stat"><div class="label">Points available</div><div class="value">{{ round($open->sum('gain')) }}</div><small>{{ $open->count() }} open actions</small></div>
        <div class="card stat"><div class="label">TenantsGuard can help</div><div class="value" style="color:var(--accent-text)">{{ round($helpable->sum('gain')) }}</div><small>points from {{ $helpable->count() }} actions mapped to controls</small></div>
        <div class="card stat"><div class="label">Also close framework gaps</div><div class="value">{{ $helpable->filter(fn ($a) => $a['frameworks'])->count() }}</div><small>actions that count for compliance too</small></div>
        <div class="card stat"><div class="label">Licensed / active users</div><div class="value" style="font-size:1.4rem">{{ $snap->licensed_users ?? '–' }} / {{ $snap->active_users ?? '–' }}</div></div>
    </div>

    <div class="card">
        <div class="page-head" style="margin-bottom:8px">
            <div>
                <h2 style="margin:0">Improvement actions, prioritised by points</h2>
                <p class="muted" style="margin:2px 0 0">Each action is matched to the TenantsGuard control that fixes it, so you can apply it through a quick fix or your control plan and see which frameworks benefit.</p>
            </div>
        </div>
        <div class="filters" id="ss-filters">
            <input type="text" data-ss="q" placeholder="Search actions…">
            <select data-ss="category"><option value="">All categories</option>@foreach(array_keys($snap->categories ?? []) as $c)<option value="{{ strtolower($c) }}">{{ $c }}</option>@endforeach</select>
            <select data-ss="status"><option value="open">Open (to address / partial)</option><option value="">All statuses</option>@foreach(SecureScoreSnapshot::STATUS as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select data-ss="tg"><option value="">All actions</option><option value="yes">TenantsGuard can help</option><option value="fw">Also closes a framework gap</option><option value="no">Microsoft portal only</option></select>
        </div>
        <div class="table-wrap">
            <table id="ss-table">
                <thead><tr><th>Action</th><th>Category</th><th style="text-align:right">Points</th><th>Status</th><th>User impact / cost</th><th>TenantsGuard control</th><th>Frameworks</th></tr></thead>
                <tbody>
                @foreach($actions as $a)
                    <tr data-q="{{ strtolower($a['title'].' '.$a['name']) }}" data-category="{{ strtolower($a['category']) }}" data-status="{{ $a['status'] }}"
                        data-tg="{{ $a['links'] ? 'yes' : 'no' }}" data-fw="{{ $a['frameworks'] ? 1 : 0 }}">
                        <td>
                            <strong>{{ $a['title'] }}</strong>
                            <br><small class="muted">{{ $a['service'] }} · {{ $a['name'] }}</small>
                            @if($a['action_url'])<br><a href="{{ $a['action_url'] }}" target="_blank" rel="noopener" style="font-size:12px">Open in Microsoft</a>@endif
                        </td>
                        <td>{{ $a['category'] }}</td>
                        <td style="text-align:right;white-space:nowrap">
                            {{ rtrim(rtrim(number_format($a['score'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($a['max'], 2), '0'), '.') }}
                            @if($a['gain'] > 0)<br><small style="color:var(--accent-text);font-weight:700">+{{ rtrim(rtrim(number_format($a['gain'], 2), '0'), '.') }} available</small>@endif
                        </td>
                        <td><span class="badge {{ SecureScoreSnapshot::STATUS[$a['status']][1] }}">{{ SecureScoreSnapshot::STATUS[$a['status']][0] }}</span></td>
                        <td style="font-size:13px">{{ $a['user_impact'] ?? '–' }} / {{ $a['cost'] ?? '–' }}</td>
                        <td>
                            @forelse($a['links'] as $l)
                                <div style="margin-bottom:4px">
                                    @if($l['source'] === 'posture')<a href="{{ route('tenants.show', $tenant) }}#{{ $l['id'] }}">{{ $l['id'] }}</a>
                                    @else<a href="{{ route('tenants.intune', $tenant) }}">{{ $l['id'] }}</a>@endif
                                    <span class="badge {{ ComplianceEngine::CONTROL_STATUS[$l['status']][1] ?? 's-na' }}">{{ ComplianceEngine::CONTROL_STATUS[$l['status']][0] ?? $l['status'] }}</span>
                                    @if($l['in_plan'])<a class="chip" href="{{ route('plans.rollout', $tenant) }}">Plan · phase {{ $l['phase'] }}</a>@endif
                                </div>
                            @empty
                                <span class="muted" style="font-size:13px">Microsoft portal</span>
                            @endforelse
                        </td>
                        <td>@foreach($a['frameworks'] as $fk)<span class="fw fw-{{ $fk }}">{{ FrameworkRegistry::SHORT[$fk] }}</span> @endforeach</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="help">"TenantsGuard can help" means a TenantsGuard control addresses the same setting. Microsoft recalculates Secure Score about once a day, so changes appear after the next calculation and scan.</p>
    </div>
@endif

<script>
(function () {
    const rows = [...document.querySelectorAll('#ss-table tbody tr')];
    const ctl = document.querySelectorAll('[data-ss]');
    function apply() {
        const f = {}; ctl.forEach((c) => (f[c.dataset.ss] = c.value.toLowerCase()));
        rows.forEach((r) => {
            const d = r.dataset;
            const status = f.status === 'open' ? ['to_address', 'partial'].includes(d.status) : (!f.status || d.status === f.status);
            const tg = !f.tg || (f.tg === 'fw' ? d.fw === '1' : d.tg === f.tg);
            r.hidden = !((!f.q || d.q.includes(f.q)) && (!f.category || d.category === f.category) && status && tg);
        });
    }
    ctl.forEach((c) => c.addEventListener('input', apply));
    apply();
})();
</script>
@endsection
