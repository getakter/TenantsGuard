@extends('layouts.app')
@section('title', 'Dashboard: '.$tenant->name)
@use('App\Services\Checks\Catalog')

@section('content')
@php
    $statusBadge = ['pass' => ['Passed', 's-pass'], 'fail' => ['Failed', 's-fail'], 'manual' => ['Review', 's-manual'], 'na' => ['Not applicable', 's-na'],
        'error' => ['Could not check', 's-error'], 'info' => ['Info', 's-info'], 'pending' => ['Not run', 's-na']];
    $o = $overview;
    $ring = function (float $ratio, float $r, string $color) {
        $c = 2 * M_PI * $r;
        $len = max(0, min(1, $ratio)) * $c;
        $track = '<circle cx="60" cy="60" r="'.$r.'" fill="none" stroke="var(--surface-3)" stroke-width="10"/>';
        if ($len < 1) {
            return $track;
        }

        return '<circle cx="60" cy="60" r="'.$r.'" fill="none" stroke="var(--surface-3)" stroke-width="10"/>'
            .'<circle cx="60" cy="60" r="'.$r.'" fill="none" style="stroke:'.$color.'" stroke-width="10" stroke-linecap="round" stroke-dasharray="'.round($len, 2).' '.round($c, 2).'" transform="rotate(-90 60 60)"/>';
    };
    $idRatio = $summary['identity']['total'] ? $summary['identity']['passed'] / $summary['identity']['total'] : 0;
    $devRatio = $summary['devices']['total'] ? $summary['devices']['passed'] / $summary['devices']['total'] : 0;
    $area = $tab === 'devices' ? 'devices' : 'identity';
    $areaTests = array_values(array_filter($tests, fn ($t) => $t['area'] === $area));
    $canScan = auth()->user()->can('tenants.scan');
@endphp

<div class="dash-head">@include('partials.icon', ['name' => 'grid']) <span class="crumbs">{{ $tenant->name }}</span> <span class="muted">›</span> Dashboard
    @if($tenant->isDemo())<span class="badge s-info">Demo</span>@endif
    @unless($tenant->isReady())<span class="badge s-manual">Consent {{ $tenant->consent_status }}</span>@endunless
</div>
<nav class="dash-tabs">
    @foreach(\App\Http\Controllers\TenantDashboardController::TABS as $k => $label)
        <a href="{{ route('tenants.dashboard', [$tenant, 'tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
    <a href="{{ route('tenants.show', $tenant) }}">Security posture (classic)</a>
</nav>

{{-- ================= action bar ================= --}}
<div class="dash-bar">
    <div class="card">
        <div class="pop" id="portalPop">
            <button class="btn btn-outline" type="button" data-pop="portalPop">@include('partials.icon', ['name' => 'down', 'size' => 16]) Portals</button>
            <div class="pop-panel" style="left:0;right:auto;min-width:280px">
                @foreach($portals as $name => $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener">{{ $name }} @include('partials.icon', ['name' => 'external', 'size' => 13])</a>
                @endforeach
            </div>
        </div>
        @can('reports.view')
            <button class="btn btn-primary" type="button" @if($scan) onclick="openModal('execModal')" @else aria-disabled="true" title="Run a scan first" @endif>@include('partials.icon', ['name' => 'file', 'size' => 16]) Executive Summary</button>
            @if($scan)<a class="btn" href="{{ route('reports.excel', $tenant) }}">@include('partials.icon', ['name' => 'download', 'size' => 16]) Excel workbook</a>@endif
        @endcan
    </div>
    <div class="card">
        <label class="fieldset" style="margin:0"><span>Select a report</span>
            <select onchange="location.href='{{ route('tenants.dashboard', [$tenant, 'tab' => $tab]) }}&report='+encodeURIComponent(this.value)">
                <optgroup label="Built-in">
                    @foreach(collect($reports)->where('custom', false) as $r)<option value="{{ $r['key'] }}" @selected($r['key'] === $reportKey)>{{ $r['name'] }}</option>@endforeach
                </optgroup>
                @if(collect($reports)->where('custom', true)->isNotEmpty())
                    <optgroup label="Custom">
                        @foreach(collect($reports)->where('custom', true) as $r)<option value="{{ $r['key'] }}" @selected($r['key'] === $reportKey)>{{ $r['name'] }}</option>@endforeach
                    </optgroup>
                @endif
            </select>
        </label>
        @if($canScan)
            <button class="btn" type="button" onclick="openFlyout('reportFly')">@include('partials.icon', ['name' => 'plus', 'size' => 16]) Create custom report</button>
            @if($tenant->isReady())
                <form method="post" action="{{ route('tenants.dashboard.refresh', $tenant) }}" class="inline" data-busy="Updating…">@csrf <button class="btn">@include('partials.icon', ['name' => 'refresh', 'size' => 16]) Update report</button></form>
            @endif
            @if($report['custom'])
                <form method="post" action="{{ route('tenants.dashboard.reports.destroy', [$tenant, $report['model']]) }}" class="inline" data-confirm="Delete the custom report {{ $report['name'] }} for all clients?">@csrf @method('delete') <button class="btn btn-solid-danger">Delete</button></form>
            @endif
        @endif
    </div>
</div>

@if($tab === 'overview')
    @if(! ($o['ready'] ?? false))
        <div class="card empty-card"><div><h2>Not connected</h2><p>Grant admin consent for {{ $tenant->name }} to see the dashboard.</p>
            @can('tenants.manage')<a class="btn btn-primary" href="{{ route('tenants.edit', $tenant) }}">Client settings</a>@endcan</div></div>
    @else
        {{-- ================= row 1 ================= --}}
        <div class="dash-grid">
            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'building']) Tenant</div>
                <dl class="facts">
                    <dt>Name</dt><dd>{{ $o['tenant']['name'] }}</dd>
                    <dt>Tenant ID</dt><dd><span class="pill-id">{{ $o['tenant']['id'] ?? 'unknown' }}</span></dd>
                    <dt>Primary domain</dt><dd><span class="pill-id">{{ $o['tenant']['domain'] ?? 'unknown' }}</span></dd>
                    <dt>Last scan</dt><dd>{{ $scan ? $scan->created_at->diffForHumans().' · posture score '.$scan->score.'%' : 'Never' }}</dd>
                </dl>
            </div>
            <div class="tiles">
                @foreach([
                    ['Users', 'users', 'user', 'ti-orange'], ['Guests', 'guests', 'guest', 'ti-sky'],
                    ['Groups', 'groups', 'group', 'ti-purple'], ['App registrations', 'apps', 'apps', 'ti-red'],
                    ['Managed devices', 'devices', 'laptop', 'ti-amber'], ['Compliant devices', 'compliant', 'device', 'ti-green'],
                ] as [$label, $key, $icon, $cls])
                    <div class="tile"><span class="ti {{ $cls }}">@include('partials.icon', ['name' => $icon, 'size' => 18])</span>
                        <span><small class="muted">{{ $label }}</small><b>{{ $o['metrics'][$key] ?? '–' }}</b></span></div>
                @endforeach
            </div>
            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'shield']) Assessment</div>
                <div class="assess">
                    <div>
                        <small class="muted">{{ $report['name'] }}</small>
                        <div style="margin-top:12px"><small class="muted">Identity</small><div class="big">{{ $summary['identity']['passed'] }}/{{ $summary['identity']['total'] }} <small class="muted">tests</small></div></div>
                        <div style="margin-top:10px"><small class="muted">Devices</small><div class="big">{{ $summary['devices']['passed'] }}/{{ $summary['devices']['total'] }} <small class="muted">tests</small></div></div>
                        <div style="margin-top:10px"><small class="muted">Last data collection</small><div><b>{{ $scan ? $scan->created_at->diffForHumans() : 'never' }}</b></div></div>
                    </div>
                    <svg width="130" height="130" viewBox="0 0 120 120" role="img" aria-label="Identity {{ round($idRatio * 100) }}% passed, devices {{ round($devRatio * 100) }}% passed">
                        {!! $ring($idRatio, 50, 'var(--c1)') !!}
                        {!! $ring($devRatio, 34, 'var(--c2)') !!}
                        <text x="60" y="64" text-anchor="middle" style="fill:var(--text);font-weight:700;font-size:15px">{{ $summary['identity']['total'] + $summary['devices']['total'] ? round(($summary['identity']['passed'] + $summary['devices']['passed']) / ($summary['identity']['total'] + $summary['devices']['total']) * 100) : 0 }}%</text>
                    </svg>
                </div>
                <div class="help" style="margin-top:8px"><span style="color:var(--c1)">●</span> Identity <span style="color:var(--c2);margin-left:8px">●</span> Devices</div>
            </div>
        </div>

        {{-- ================= row 2 ================= --}}
        <div class="dash-grid-2">
            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'shield']) Secure Score</div>
                @php
                    $hist = collect($secure?->history ?? [])->take(-30)->values();
                @endphp
                @if($hist->count() > 1)
                    @php
                        $W = 640;
                        $H = 220;
                        $L = 40;
                        $B = 24;
                        // Scale to the score range (like CIPP), not to the maximum possible score, so changes are visible.
                        $max = max(10, ceil($hist->max('score') * 1.25 / 5) * 5);
                        $step = ($W - $L - 10) / max(1, $hist->count() - 1);
                        $yv = fn ($v) => round(10 + ($H - $B - 10) * (1 - $v / $max), 1);
                        $pts = $hist->map(fn ($p, $i) => round($L + $i * $step, 1).','.$yv($p['score']))->implode(' ');
                        $every = max(1, (int) ceil($hist->count() / 8));
                        $lastI = $hist->count() - 1;
                    @endphp
                    <svg class="svg-chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Secure Score history">
                        @foreach([0, .25, .5, .75, 1] as $f)
                            <line x1="{{ $L }}" x2="{{ $W - 10 }}" y1="{{ $yv($max * $f) }}" y2="{{ $yv($max * $f) }}" stroke="var(--chart-grid)" stroke-dasharray="3 4"/>
                            <text x="{{ $L - 6 }}" y="{{ $yv($max * $f) + 4 }}" text-anchor="end">{{ round($max * $f) }}</text>
                        @endforeach
                        <polyline points="{{ $pts }}" fill="none" style="stroke:var(--accent-text)" stroke-width="2.5"/>
                        @foreach($hist as $i => $p)
                            <circle cx="{{ round($L + $i * $step, 1) }}" cy="{{ $yv($p['score']) }}" r="3.5" style="fill:var(--accent-text)"><title>{{ $p['date'] }}: {{ $p['score'] }} of {{ $p['max'] }}</title></circle>
                            @if(($i % $every === 0 && $lastI - $i >= $every / 2) || $i === $lastI)
                                <text x="{{ round($L + $i * $step, 1) }}" y="{{ $H - 6 }}" text-anchor="{{ $i === $lastI ? 'end' : ($i === 0 ? 'start' : 'middle') }}">{{ \Carbon\Carbon::parse($p['date'])->format('M j') }}</text>
                            @endif
                        @endforeach
                    </svg>
                    <p class="muted" style="margin:8px 0 0">Microsoft Secure Score measures the security posture of the tenant. <a href="{{ route('tenants.securescore', $tenant) }}">Improvement actions</a></p>
                    <div class="chart-foot">
                        <div><small class="muted">Latest %</small><b>{{ round($secure->percent) }}%</b></div>
                        <div><small class="muted">Current score</small><b>{{ number_format($secure->current_score, 2) }}</b></div>
                        <div><small class="muted">Max score</small><b>{{ number_format($secure->max_score, 2) }}</b></div>
                    </div>
                @else
                    <div class="empty-card">No Secure Score history yet. It is captured with every scan.</div>
                @endif
            </div>

            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'user']) User authentication</div>
                @if($a = $o['auth'])
                    @php
                        $enf = ['ca' => ['CA policy', '#13a10e'], 'security_defaults' => ['Security defaults', '#13a10e'], 'none' => ['No enforcement', '#d13438'], 'unknown' => ['Enforcement unknown', '#8a8886']][$a['enforcement']];
                    @endphp
                    @include('partials.sankey', [
                        'aria' => 'User authentication flow',
                        'nodes' => [
                            ['all', 'Enabled users', 0, $a['total'], '#0f6cbd'],
                            ['reg', 'MFA registered', 1, $a['registered'], '#13a10e'],
                            ['unreg', 'Not registered', 1, $a['unregistered'], '#f7630c'],
                            ['enf', $enf[0], 2, $a['registered'], $enf[1]],
                            ['none', 'Cannot complete MFA', 2, $a['unregistered'], '#d13438'],
                        ],
                        'links' => [['all', 'reg', $a['registered']], ['all', 'unreg', $a['unregistered']], ['reg', 'enf', $a['registered']], ['unreg', 'none', $a['unregistered']]],
                    ])
                    <p class="muted" style="margin:10px 0 0">
                        {{ $a['total'] ? round($a['registered'] / $a['total'] * 100, 1) : 0 }}% of enabled users have registered MFA methods.
                        {{ $a['enforcement'] === 'none' ? 'No Conditional Access policy or security defaults require MFA for all users.' : ($a['enforcement'] === 'unknown' ? 'Enforcement could not be read.' : 'MFA is required by '.strtolower($enf[0]).'.') }}
                    </p>
                @else
                    <div class="empty-card">Could not read MFA registration. {{ $o['errors']['auth'] ?? '' }}</div>
                @endif
            </div>
        </div>

        {{-- ================= row 3 ================= --}}
        <div class="dash-grid-2">
            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'key']) All users auth methods</div>
                @if($m = $o['methods'])
                    @include('partials.sankey', [
                        'aria' => 'Authentication methods',
                        'h' => 210,
                        'nodes' => [
                            ['u', 'Users', 0, $m['total'], '#0f6cbd'],
                            ['single', 'Single factor', 1, $m['single'], '#d13438'],
                            ['phish', 'Phishable MFA', 1, $m['phishable'], '#f7630c'],
                            ['res', 'Phish-resistant', 1, $m['resistant'], '#13a10e'],
                        ],
                        'links' => [['u', 'single', $m['single']], ['u', 'phish', $m['phishable']], ['u', 'res', $m['resistant']]],
                    ])
                    <div class="pills" style="margin-top:10px">@foreach($m['byMethod'] as $label => $n)<span class="chip">{{ $label }}: {{ $n }}</span>@endforeach</div>
                    <p class="muted" style="margin:10px 0 0">{{ $m['total'] ? round(($m['phishable'] + $m['resistant']) / $m['total'] * 100, 1) : 0 }}% of users have MFA configured. {{ $m['total'] ? round($m['resistant'] / $m['total'] * 100, 1) : 0 }}% use phishing-resistant methods (passkeys, FIDO2, Windows Hello).</p>
                @else
                    <div class="empty-card">Could not read authentication methods.</div>
                @endif
            </div>

            <div class="card">
                <div class="card-title">@include('partials.icon', ['name' => 'licence']) License overview</div>
                @if($o['licences'])
                    @foreach(collect($o['licences'])->sortByDesc('total')->take(8) as $l)
                        <div class="lic-bar">
                            <div class="row"><span>{{ $l['name'] }}</span><span class="muted">{{ $l['used'] }} of {{ $l['total'] }} used · <b style="color:{{ $l['available'] ? 'var(--pass)' : 'var(--fail)' }}">{{ $l['available'] }} free</b></span></div>
                            <div class="bar"><span style="width:{{ $l['total'] ? min(100, $l['used'] / $l['total'] * 100) : 0 }}%"></span></div>
                        </div>
                    @endforeach
                    <div class="chart-foot">
                        <div><small class="muted">Subscriptions</small><b>{{ count($o['licences']) }}</b></div>
                        <div><small class="muted">Seats used</small><b>{{ collect($o['licences'])->sum('used') }}</b></div>
                        <div><small class="muted">Seats free</small><b>{{ collect($o['licences'])->sum('available') }}</b></div>
                    </div>
                @else
                    <div class="empty-card">No licence data available. {{ $o['errors']['licences'] ?? '' }}</div>
                @endif
            </div>
        </div>
        <p class="help">Overview data read {{ \Carbon\Carbon::parse($o['read_at'])->diffForHumans() }} and cached for 15 minutes. "Update report" refreshes everything.</p>
    @endif
@else
    {{-- ================= Identity / Devices tests ================= --}}
    <div class="card">
        <div class="card-title">{{ $tab === 'devices' ? 'Device tests' : 'Identity tests' }} <span class="muted" style="font-weight:400">· {{ $report['name'] }}</span></div>
        @if(! $areaTests)
            <div class="empty-card">This report has no {{ $area }} tests. Choose another report or create a custom one.</div>
        @else
            <div class="test-toolbar">
                <input type="text" id="tq" placeholder="Search tests…" oninput="filterTests()">
                <select id="tstatus" onchange="filterTests()"><option value="">All results</option>@foreach(['fail' => 'Failed', 'pass' => 'Passed', 'manual' => 'Review', 'na' => 'Not applicable', 'error' => 'Could not check', 'pending' => 'Not run'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                <select id="trisk" onchange="filterTests()"><option value="">All risk levels</option><option>High</option><option>Medium</option><option>Low</option></select>
                <span class="muted" id="tcount"></span>
                <span style="flex:1"></span>
                <button class="btn btn-sm" type="button" onclick="exportTests()">@include('partials.icon', ['name' => 'download', 'size' => 15]) Export CSV</button>
            </div>
            <div class="table-wrap">
                <table class="test-table">
                    <thead><tr><th>Name</th><th>Category</th><th>Result</th><th>Risk</th><th>User impact</th><th>Effort</th><th>Standard</th></tr></thead>
                    <tbody>
                    @foreach($areaTests as $i => $t)
                        <tr data-i="{{ $i }}" data-status="{{ $t['status'] }}" data-risk="{{ $t['risk'] }}" data-q="{{ strtolower($t['id'].' '.$t['name'].' '.$t['category']) }}" onclick="openTest({{ $i }})">
                            <td><strong>{{ $t['name'] }}</strong><br><small class="muted">{{ $t['id'] }}</small></td>
                            <td class="muted">{{ $t['category'] }}</td>
                            <td><span class="badge {{ $statusBadge[$t['status']][1] ?? 's-na' }}">{{ $statusBadge[$t['status']][0] ?? $t['status'] }}</span></td>
                            <td><span class="lvl lvl-{{ $t['risk'] }}">{{ $t['risk'] }}</span></td>
                            <td><span class="lvl lvl-{{ $t['impact'] }}">{{ $t['impact'] }}</span></td>
                            <td><span class="lvl lvl-{{ $t['effort'] }}">{{ $t['effort'] }}</span></td>
                            <td><span class="lvl lvl-{{ $t['standard'] ? 'Yes' : 'No' }}">{{ $t['standard'] ? 'Yes' : 'No' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

<div class="scrim" id="scrim" onclick="closeAll()"></div>

{{-- ================= test details flyout ================= --}}
<aside class="flyout" id="testFly" aria-label="Test details">
    <div class="flyout-head">
        <h2 id="tfName"></h2>
        <button class="icon-btn" type="button" onclick="stepTest(-1)" title="Previous test">@include('partials.icon', ['name' => 'up'])</button>
        <button class="icon-btn" type="button" onclick="stepTest(1)" title="Next test">@include('partials.icon', ['name' => 'down'])</button>
        <button class="icon-btn" type="button" onclick="closeAll()" title="Close">@include('partials.icon', ['name' => 'x'])</button>
    </div>
    <div class="flyout-body" id="tfBody"></div>
</aside>

{{-- ================= custom report flyout ================= --}}
@if($canScan)
<aside class="flyout" id="reportFly" aria-label="Create custom report">
    <form method="post" action="{{ route('tenants.dashboard.reports.store', $tenant) }}" style="display:contents">
        @csrf
        <div class="flyout-head"><h2>Create custom report</h2><button class="icon-btn" type="button" onclick="closeAll()">@include('partials.icon', ['name' => 'x'])</button></div>
        <div class="flyout-body">
            <div class="box">
                <h3>Report details</h3>
                <label class="fieldset" style="display:block;margin:14px 0 18px"><span>Report name</span><input type="text" name="name" value="{{ old('name') }}" required maxlength="120"></label>
                <label class="fieldset" style="display:block"><span>Description</span><textarea name="description" rows="2" maxlength="500">{{ old('description') }}</textarea></label>
            </div>
            <div style="display:flex;gap:10px;align-items:center;margin:4px 0 12px">
                <strong style="color:var(--accent-text)">Selected tests:</strong>
                <span class="count-chip" style="color:var(--accent-text)"><span id="selId">0</span> Identity</span>
                <span class="count-chip" style="color:#a855f7"><span id="selDev">0</span> Device</span>
                <span style="flex:1"></span><small class="muted">Total: <span id="selAll">0</span> tests</small>
            </div>
            <div class="picker-tabs">
                <button type="button" class="on" data-pt="identity">Identity tests</button>
                <button type="button" data-pt="devices">Device tests</button>
            </div>
            <input type="text" placeholder="Search tests…" oninput="pickFilter(this.value)" style="margin-bottom:12px">
            @foreach($library as $t)
                <label class="pick-test" data-area="{{ $t['area'] }}" data-q="{{ strtolower($t['id'].' '.$t['name'].' '.$t['category']) }}" @if($t['area'] !== 'identity') hidden @endif>
                    <input type="checkbox" name="tests[]" value="{{ $t['id'] }}" onchange="pickCount()" @checked(in_array($t['id'], old('tests', []), true))>
                    <span class="tid">{{ $t['id'] }}</span> <span>{{ $t['name'] }}</span>
                </label>
            @endforeach
        </div>
        <div class="flyout-foot"><button class="btn btn-primary">Create report</button><button class="btn btn-outline" type="button" onclick="closeAll()">Close</button></div>
    </form>
</aside>
@endif

{{-- ================= executive summary ================= --}}
@if($scan && auth()->user()->can('reports.view'))
<div class="modal" id="execModal" role="dialog" aria-label="Executive report">
    <div class="flyout-head"><h2>Executive report: {{ $tenant->name }}</h2><button class="icon-btn" type="button" onclick="closeAll()">@include('partials.icon', ['name' => 'x'])</button></div>
    <div class="modal-body">
        <div class="modal-side">
            <h3>@include('partials.icon', ['name' => 'cog', 'size' => 16]) Report sections</h3>
            <p class="muted" style="margin-top:0">Choose what the client sees. The preview updates as you go. Branding comes from Settings.</p>
            @foreach($sections as $key => [$label, $desc])
                <label class="sec-toggle on">
                    <span class="switch"><input type="checkbox" class="sec" value="{{ $key }}" @checked($key !== 'controls') onchange="this.closest('.sec-toggle').classList.toggle('on', this.checked); previewReport()"><span class="slider"></span></span>
                    <span><strong>{{ $label }}</strong><small class="muted">{{ $desc }}</small></span>
                </label>
            @endforeach
        </div>
        <div class="modal-preview"><iframe id="execFrame" title="Report preview"></iframe></div>
    </div>
    <div class="flyout-foot">
        <span class="muted" id="secCount"></span><span style="flex:1"></span>
        <button class="btn btn-primary" type="button" onclick="document.getElementById('execFrame').contentWindow.print()">@include('partials.icon', ['name' => 'download', 'size' => 16]) Download PDF</button>
        <button class="btn btn-outline" type="button" onclick="closeAll()">Close</button>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
const TESTS = @json($areaTests);
const STATUS = @json($statusBadge);
let cur = -1;
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function closeAll() {
  document.querySelectorAll('.flyout.open, .modal.open, .scrim.open').forEach((el) => el.classList.remove('open'));
}
function openFlyout(id) { closeAll(); document.getElementById(id).classList.add('open'); document.getElementById('scrim').classList.add('open'); }
function openModal(id) { openFlyout(id); previewReport(); }
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeAll();
  if (document.getElementById('testFly').classList.contains('open') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); stepTest(e.key === 'ArrowDown' ? 1 : -1); }
});

function visibleRows() { return [...document.querySelectorAll('.test-table tbody tr')].filter((r) => !r.hidden); }
function filterTests() {
  const q = document.getElementById('tq').value.toLowerCase(), s = document.getElementById('tstatus').value, r = document.getElementById('trisk').value;
  let n = 0;
  document.querySelectorAll('.test-table tbody tr').forEach((tr) => {
    const ok = (!q || tr.dataset.q.includes(q)) && (!s || tr.dataset.status === s) && (!r || tr.dataset.risk === r);
    tr.hidden = !ok; if (ok) n++;
  });
  document.getElementById('tcount').textContent = n + ' of ' + TESTS.length + ' tests';
}
function openTest(i) {
  cur = i;
  const t = TESTS[i], st = STATUS[t.status] || [t.status, 's-na'];
  document.getElementById('tfName').textContent = t.name;
  const ev = Object.entries(t.evidence || {}).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length)).map(([k, v]) => `<li><b>${esc(k)}</b>: ${esc(typeof v === 'object' ? (Array.isArray(v) ? v.join(', ') : JSON.stringify(v)) : String(v))}</li>`).join('');
  const aff = (t.affected || []).slice(0, 25).map((a) => `<tr><td>${esc(a.name)}</td><td class="muted">${esc(a.id)}</td><td>${esc(a.detail)}</td></tr>`).join('');
  const fw = Object.entries(t.frameworks || {}).map(([k, v]) => `<span class="fw">${esc(k)} <small>${esc(v)}</small></span>`).join(' ');
  document.getElementById('tfBody').innerHTML = `
    <div class="meta4">
      <div><small class="muted">Risk</small><span class="lvl lvl-${t.risk}">${t.risk}</span></div>
      <div><small class="muted">User impact</small><span class="lvl lvl-${t.impact}">${t.impact}</span></div>
      <div><small class="muted">Effort</small><span class="lvl lvl-${t.effort}">${t.effort}</span></div>
      <div><small class="muted">Standard available</small><span class="lvl lvl-${t.standard ? 'Yes' : 'No'}">${t.standard ? 'Yes' : 'No'}</span></div>
    </div>
    <div class="box">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h3 style="margin:0">${esc(t.name)}</h3><span class="badge ${st[1]}">${st[0]}</span></div>
      <p>${esc(t.summary)}</p>
      ${ev ? `<ul>${ev}</ul>` : ''}
      ${aff ? `<div class="affected"><table><thead><tr><th>Name</th><th>ID</th><th>Detail</th></tr></thead><tbody>${aff}</tbody></table></div>${t.affected.length > 25 ? `<p class="help">and ${t.affected.length - 25} more in Security posture.</p>` : ''}` : ''}
    </div>
    <div class="box">
      <h3>What did we check</h3>
      <small class="muted">Category</small><div style="font-weight:600;margin-bottom:10px">${esc(t.category)} <span class="muted" style="font-weight:400">· ${esc(t.id)}</span></div>
      <p>${esc(t.description)}</p>
      <strong>Remediation</strong><p style="margin-top:4px">${esc(t.remediation)}</p>
      ${fw ? `<strong>Frameworks</strong><div class="fw-tags" style="margin-top:6px">${fw}</div>` : ''}
      <p style="margin-top:14px"><a class="btn btn-primary btn-sm" href="${esc(t.link)}">${esc(t.link_label)}</a></p>
    </div>`;
  openFlyout('testFly');
}
function stepTest(d) {
  const rows = visibleRows().map((r) => +r.dataset.i);
  const at = rows.indexOf(cur);
  if (at === -1 || !rows.length) return;
  openTest(rows[(at + d + rows.length) % rows.length]);
}
function exportTests() {
  const head = ['ID', 'Name', 'Category', 'Result', 'Risk', 'User impact', 'Effort', 'Standard available', 'Summary'];
  const rows = visibleRows().map((r) => TESTS[+r.dataset.i]).map((t) => [t.id, t.name, t.category, (STATUS[t.status] || [t.status])[0], t.risk, t.impact, t.effort, t.standard ? 'Yes' : 'No', t.summary]);
  const csv = [head, ...rows].map((r) => r.map((c) => '"' + String(c ?? '').replace(/"/g, '""') + '"').join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = @json(\Illuminate\Support\Str::slug($tenant->name)).concat('-{{ $area }}-tests.csv');
  a.click();
}
if (document.getElementById('tq')) filterTests();

// Custom report picker
document.querySelectorAll('[data-pt]').forEach((b) => b.addEventListener('click', () => {
  document.querySelectorAll('[data-pt]').forEach((x) => x.classList.toggle('on', x === b));
  document.querySelectorAll('.pick-test').forEach((l) => (l.hidden = l.dataset.area !== b.dataset.pt));
}));
function pickFilter(q) {
  q = q.toLowerCase();
  const area = document.querySelector('[data-pt].on').dataset.pt;
  document.querySelectorAll('.pick-test').forEach((l) => (l.hidden = l.dataset.area !== area || (q && !l.dataset.q.includes(q))));
}
function pickCount() {
  const on = [...document.querySelectorAll('.pick-test input:checked')].map((i) => i.closest('.pick-test').dataset.area);
  document.getElementById('selId').textContent = on.filter((a) => a === 'identity').length;
  document.getElementById('selDev').textContent = on.filter((a) => a === 'devices').length;
  document.getElementById('selAll').textContent = on.length;
}
if (document.getElementById('selAll')) pickCount();
@if($errors->has('name') || $errors->has('tests')) openFlyout('reportFly'); @endif

// Executive report preview
let previewTimer;
function previewReport() {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(() => {
    const on = [...document.querySelectorAll('.sec:checked')].map((c) => c.value);
    document.getElementById('secCount').textContent = 'Sections enabled: ' + on.length + ' of ' + document.querySelectorAll('.sec').length;
    const url = new URL(@json(route('reports.show', $tenant)), location.href);
    on.forEach((s) => url.searchParams.append('sections[]', s));
    url.searchParams.set('embed', '1');
    document.getElementById('execFrame').src = url.toString();
  }, 250);
}
</script>
@endpush
