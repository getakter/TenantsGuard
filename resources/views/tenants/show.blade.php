@use('App\Services\Checks\Catalog')
@extends('layouts.app')
@section('title', $tenant->name)

@php
    $statusLabels = Catalog::STATUSES;
    $latestId = $scans->first()?->id;
    $isLatest = $scan && $scan->id === $latestId;
    $drifted = $scan?->drifted ?? [];
    $sevOrder = array_flip(Catalog::SEVERITIES);
    $statusOrder = ['fail' => 0, 'error' => 1, 'manual' => 2, 'pass' => 3, 'info' => 4, 'na' => 5];
    $ordered = collect($catalog)->sortBy(fn ($c, $id) => [
        $statusOrder[$results[$id]->status ?? 'na'] ?? 9,
        $sevOrder[$c['severity']],
        $id,
    ]);
@endphp

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'posture'])

@include('tenants._checklist', ['tenant' => $tenant])

@if($tenant->isReady() && (! $scan || $isLatest))
    @php
        $ssLatest = $tenant->secureScores()->first();
        $ssPrev = $tenant->secureScores()->whereNull('error')->skip(1)->first();
        $plan = $tenant->complianceProfile;
    @endphp
    @include('securescore._lenses', ['tenant' => $tenant, 'snap' => $ssLatest, 'prev' => $ssPrev, 'plan' => $plan, 'compliance' => $plan ? \App\Services\Compliance\ComplianceEngine::evaluate($tenant, $plan) : []])
@endif

<div class="page-head">
    <div style="display:flex;gap:16px;align-items:center">
        @include('partials.ring', ['score' => $scan?->score, 'size' => 84])
        <div>
            <h1 style="margin:0">{{ $tenant->name }}</h1>
            @if($scan)
                <small>{{ $isLatest ? 'Latest scan' : 'Historical scan' }}: {{ $scan->created_at->format('M j, Y H:i') }} ({{ $scan->trigger }}, {{ $scan->duration_ms }} ms)</small>
            @endif
            @if($tenant->notes)<div class="help" style="max-width:600px" title="Internal notes">📝 {{ \Illuminate\Support\Str::limit($tenant->notes, 160) }}</div>@endif
        </div>
    </div>
    <div class="actions">
        @if($tenant->isReady() && auth()->user()->can('tenants.scan'))
            <form method="post" action="{{ route('tenants.scan', $tenant) }}" data-busy="Scanning…">@csrf <button class="btn btn-primary">Scan now</button></form>
        @endif
        @if($scan && auth()->user()->can('reports.view'))
            <a class="btn" href="{{ route('reports.show', [$tenant, 'scan' => $scan->id]) }}" target="_blank">Executive report</a>
            <a class="btn" href="{{ route('reports.excel', [$tenant, 'scan' => $scan->id]) }}">Excel export</a>
        @endif
        @if($tenant->isDemo() && auth()->user()->can('tenants.scan'))
            <form method="post" action="{{ route('tenants.drift', $tenant) }}">@csrf <button class="btn" title="Revert some hardened settings, as if someone changed them in the portal">Simulate drift</button></form>
        @endif
    </div>
</div>

@if(! $tenant->isReady() && ! auth()->user()->can('tenants.manage'))
    <div class="alert alert-warn">This tenant is not connected yet. Someone with permission to manage tenants must complete admin consent.</div>
@endif
@if(! $tenant->isReady() && auth()->user()->can('tenants.manage'))
    <div class="card" style="border-color:var(--warn)">
        <h2>Connect this tenant</h2>
        <p>Status: <span class="badge s-manual">Consent {{ $tenant->consent_status }}</span></p>
        @if($tenant->consent_error)<div class="alert alert-err">{{ $tenant->consent_error }}</div>@endif
        @php
            $msReady = \App\Support\MicrosoftApp::configured();
        @endphp
        @unless($msReady)
            <div class="alert alert-warn">The Microsoft app is not set up yet. Open <a href="{{ route('settings.index') }}">Settings &gt; Microsoft app</a> first. Automatic setup takes one Microsoft sign-in.</div>
        @endunless
        <p>A Global Administrator of <strong>{{ $tenant->domain ?? $tenant->ms_tenant_id }}</strong> must approve the TenantsGuard app in Microsoft's consent popup.</p>
        <div class="actions">
            @include('partials.ms-button', ['href' => route('tenants.consent', $tenant), 'label' => 'Grant admin consent', 'disabled' => ! $msReady])
            <form method="post" action="{{ route('tenants.verify', $tenant) }}" data-busy="Verifying…">@csrf <button class="btn">Verify connection</button></form>
        </div>
        @if($msReady)
            <div class="field" style="margin-top:14px">
                <label>Or send this consent link to the customer's Global Administrator</label>
                <input type="text" readonly value="{{ \App\Services\ConsentService::consentUrl($tenant) }}" onclick="this.select()">
                <div class="help">They open it in their own browser, sign in, and click Accept. TenantsGuard connects and scans the tenant automatically.</div>
            </div>
        @endif
    </div>
@endif

@if($scan)
    @if($drifted && $isLatest)
        <div class="alert alert-err">
            <strong>Drift detected:</strong> {{ count($drifted) }} controls passed in the previous scan and fail now:
            @foreach($drifted as $d)<a href="#{{ $d }}" class="tag" style="color:var(--fail)">{{ $d }}</a>@endforeach
        </div>
    @endif

    <div class="grid grid-4" style="margin-bottom:16px">
        @foreach(['critical', 'high', 'medium', 'low'] as $sev)
            <div class="card stat">
                <div class="label">{{ ucfirst($sev) }} failures</div>
                <div class="value" style="color:{{ ($scan->counts['fail_'.$sev] ?? 0) ? ($sev === 'critical' ? 'var(--critical)' : ($sev === 'high' ? 'var(--fail)' : ($sev === 'medium' ? 'var(--warn)' : 'var(--info)'))) : 'var(--pass)' }}">{{ $scan->counts['fail_'.$sev] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-2" style="margin-bottom:16px">
        <div class="card">
            <div class="page-head" style="margin-bottom:6px">
                <h2>Score trend ({{ config('tenantsguard.retention_days') }} days)</h2>
            </div>
            @include('partials.trend', ['points' => $scans->reverse()])
        </div>
        <div class="card">
            <h2>Scan history &amp; delta reports</h2>
            <div class="table-wrap" style="max-height:190px;overflow:auto">
                <table>
                    <thead><tr><th>Date</th><th>Trigger</th><th>Score</th><th></th></tr></thead>
                    <tbody>
                    @foreach($scans as $i => $s)
                        <tr @if($s->id === $scan->id) style="background:var(--accent-soft)" @endif>
                            <td><a href="{{ route('tenants.show', [$tenant, 'scan' => $s->id]) }}">{{ $s->created_at->format('M j, H:i') }}</a></td>
                            <td>{{ $s->trigger }} @if(count($s->drifted ?? []))<span class="badge s-fail">{{ count($s->drifted) }} drift</span>@endif</td>
                            <td>{{ $s->score }}%</td>
                            <td>@if($s->id !== $latestId && auth()->user()->can('reports.view'))<a href="{{ route('reports.show', [$tenant, 'scan' => $latestId, 'compare' => $s->id]) }}" target="_blank">Delta vs latest</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="page-head" style="margin-bottom:10px">
            <div><h2>Findings</h2><small><span id="finding-count">{{ count($catalog) }}</span> controls shown</small></div>
        </div>
        <div class="filters">
            <input type="text" data-filter="q" placeholder="Search controls…">
            <select data-filter="status">
                <option value="">All statuses</option>
                @foreach($statusLabels as $k => $v)<option value="{{ $k }}" @selected($k === 'fail')>{{ $v }}</option>@endforeach
            </select>
            <select data-filter="severity">
                <option value="">All severities</option>
                @foreach(Catalog::SEVERITIES as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select data-filter="category">
                <option value="">All categories</option>
                @foreach(Catalog::CATEGORIES as $c)<option value="{{ strtolower($c) }}">{{ $c }}</option>@endforeach
            </select>
            <select data-filter="framework">
                <option value="">All frameworks</option>
                @foreach(\App\Services\Compliance\FrameworkRegistry::SHORT as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select>
        </div>

        <div id="findings">
        @foreach($ordered as $id => $c)
            @php
                $r = $results[$id] ?? null;
                $status = $r->status ?? 'na';
                $ann = $annotations[$id] ?? null;
                $fws = implode(' ', \App\Services\Compliance\FrameworkRegistry::frameworksForControl($id));
                $outOfScope = $tenant->complianceProfile?->excluded_controls[$id] ?? null;
            @endphp
            <details class="finding" id="{{ $id }}"
                     data-status="{{ $status }}" data-severity="{{ $c['severity'] }}" data-category="{{ strtolower($c['category']) }}"
                     data-frameworks="{{ $fws }}" data-search="{{ strtolower($id.' '.$c['title'].' '.($r->summary ?? '')) }}">
                <summary>
                    <span class="fid">{{ $id }}</span>
                    <div>
                        <div class="ftitle">{{ $c['title'] }}
                            @if(in_array($id, $drifted, true) && $isLatest)<span class="drift">DRIFTED</span>@endif
                            @if($ann)<span class="badge s-na" title="{{ $ann->note }}">{{ \App\Models\Annotation::TYPES[$ann->type] }}</span>@endif
                            @if($outOfScope)<span class="badge s-na" title="{{ $outOfScope }}">Out of scope: {{ $tenant->complianceProfile->name }}</span>@endif
                        </div>
                        <div class="fsum">{{ $r->summary ?? 'Not evaluated in this scan.' }}</div>
                    </div>
                    <span class="sevcol"><span class="badge sev-{{ $c['severity'] }}">{{ ucfirst($c['severity']) }}</span></span>
                    <span class="badge s-{{ $status }}">{{ $statusLabels[$status] ?? $status }}</span>
                </summary>
                <div class="body">
                    <div>
                        <p style="margin-top:0">{{ $c['description'] }}</p>
                        <h3>Remediation</h3>
                        <p>{{ $c['remediation'] }}</p>

                        @if($r && $r->fix && $status === 'fail' && $isLatest)
                            @php
                                $needsEnforce = ($r->fix['step'] ?? null) === 2;
                                $mayFix = auth()->user()->can('fixes.apply') && (! $needsEnforce || auth()->user()->can('fixes.enforce'));
                            @endphp
                            <div class="fix-box">
                                <strong>{{ str_contains($r->fix['label'], 'Step') ? 'Guided workflow' : 'Quick fix' }}:</strong> {{ $r->fix['label'] }}
                                <div class="help" style="color:inherit;margin:4px 0 10px">Impact: {{ $r->fix['impact'] }}</div>
                                @if($mayFix)
                                    <form method="post" action="{{ route('fix.store', [$tenant, $id]) }}" data-busy="Applying…"
                                          data-confirm="{{ $r->fix['label'] }} in {{ $tenant->name }}?&#10;&#10;Impact: {{ $r->fix['impact'] }}">
                                        @csrf <button class="btn btn-primary btn-sm">Apply</button>
                                    </form>
                                @else
                                    <div class="help" style="color:inherit"><em>Your role cannot {{ $needsEnforce ? 'enforce Conditional Access policies' : 'apply fixes' }}. Ask an Administrator.</em></div>
                                @endif
                            </div>
                        @endif

                        @if($ann || auth()->user()->can('annotations.manage'))
                        <div class="ann-box">
                            @if($ann)
                                <strong>{{ \App\Models\Annotation::TYPES[$ann->type] }}</strong>
                                <span class="muted">by {{ $ann->user?->name ?? 'unknown' }}, {{ $ann->updated_at->format('M j, Y') }}@if($ann->review_by) · review by {{ $ann->review_by->format('M j, Y') }}@endif</span>
                                <p style="margin:6px 0">{{ $ann->note }}</p>
                                @if($ann->excludesFromScore())<div class="help">Excluded from the score.</div>@endif
                                @can('annotations.manage')
                                    <form method="post" action="{{ route('annotations.destroy', [$tenant, $id]) }}" style="margin-top:6px">@csrf @method('delete') <button class="btn btn-sm">Remove annotation</button></form>
                                @endcan
                            @else
                                <details>
                                    <summary style="cursor:pointer;font-weight:600">Record a risk decision or note</summary>
                                    <form method="post" action="{{ route('annotations.store', [$tenant, $id]) }}" style="margin-top:10px">
                                        @csrf
                                        <div class="field">
                                            <select name="type">
                                                @foreach(\App\Models\Annotation::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                                            </select>
                                            <div class="help">Accepted risks and compensating controls are excluded from the score and listed in reports.</div>
                                        </div>
                                        <div class="field"><textarea name="note" placeholder="Why, who approved it, what compensates…" required></textarea></div>
                                        <div class="field"><label>Review by (optional)</label><input type="date" name="review_by"></div>
                                        <button class="btn btn-sm">Save annotation</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                        @endif
                    </div>
                    <div>
                        <dl class="kv">
                            <dt>Category</dt><dd>{{ $c['category'] }}</dd>
                            @if($c['license'])<dt>Licence</dt><dd>{{ ['p1' => 'Entra ID P1', 'p2' => 'Entra ID P2', 'intune' => 'Intune'][$c['license']] }}</dd>@endif
                            @if($c['permission'])<dt>Graph permission</dt><dd class="mono">{{ $c['permission'] }}</dd>@endif
                            @foreach(['cis' => 'CIS M365', 'cisv8' => 'CIS v8.1', 'nist' => 'NIST CSF 2.0', 'e8' => 'Essential Eight'] as $k => $label)
                                @if(! empty($c['frameworks'][$k]))<dt>{{ $label }}</dt><dd>{{ $c['frameworks'][$k] }}</dd>@endif
                            @endforeach
                            @if(! empty($c['frameworks']['ins']))<dt>Insurance</dt><dd>Commonly asked on cyber-insurance questionnaires</dd>@endif
                        </dl>
                        @if($r && $r->affected)
                            <h3 style="margin-top:14px">Affected ({{ count($r->affected) }})</h3>
                            <div class="affected">
                                <table>
                                    <thead><tr><th>Name</th><th>Identifier</th><th>Detail</th></tr></thead>
                                    <tbody>
                                    @foreach($r->affected as $a)
                                        <tr><td>{{ $a['name'] ?? '' }}</td><td class="mono">{{ $a['id'] ?? '' }}</td><td>{{ $a['detail'] ?? '' }}</td></tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if($r && $r->evidence)
                            <h3 style="margin-top:14px">Evidence</h3>
                            <pre class="evidence">{{ json_encode($r->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    </div>
                </div>
            </details>
        @endforeach
        </div>
    </div>
@elseif($tenant->isReady())
    <div class="card empty">
        <h2>No scans yet</h2>
        @can('tenants.scan')
            <form method="post" action="{{ route('tenants.scan', $tenant) }}" data-busy="Scanning…">@csrf <button class="btn btn-primary">Run first scan</button></form>
        @endcan
    </div>
@endif
@endsection
