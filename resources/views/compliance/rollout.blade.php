@extends('layouts.app')
@section('title', 'Control plan: '.$tenant->name)
@use('App\Services\Compliance\FrameworkRegistry')
@use('App\Services\Compliance\ComplianceEngine')
@use('App\Services\Compliance\PlanDefaults')
@use('App\Models\ControlPlanRun')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'plan'])
@php
    $done = fn ($s) => in_array($s, ['pass', 'deployed', 'compensating'], true);
    $canApply = auth()->user()->can('fixes.apply');
@endphp
<div class="page-head">
    <div>
        <h1>{{ $plan ? $plan->name : 'Control plan: '.$tenant->name }}</h1>
        <p>{{ $tenant->name }} · roll controls out phase by phase or all at once, then monitor.</p>
    </div>
    <div class="actions">
        @can('compliance.manage')
            @if($plan)<a class="btn" href="{{ route('compliance.profiles.edit', $plan) }}">Edit plan (scope and phases)</a>@endif
            <a class="btn" href="{{ route('compliance.profiles.create', ['tenant' => $tenant->id]) }}">New plan for this tenant</a>
        @endcan
    </div>
</div>

@if(! $plan)
    <div class="card empty">
        <h2>No control plan yet</h2>
        <p>Pick frameworks (for example CIS and HIPAA). TenantsGuard merges their controls, tags the overlaps and lets you roll them out gradually.</p>
        @can('compliance.manage')
            <div class="actions" style="justify-content:center">
                <a class="btn btn-primary" href="{{ route('compliance.profiles.create', ['tenant' => $tenant->id]) }}">Create a control plan for {{ $tenant->name }}</a>
                <form method="post" action="{{ route('compliance.assign', $tenant) }}" style="display:flex;gap:6px">
                    @csrf
                    <select name="compliance_profile_id" style="width:auto">@foreach($plans as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                    <button class="btn">Use existing plan</button>
                </form>
            </div>
        @endcan
    </div>
@else
    @php
        $byPhase = $plan->controlsByPhase();
        $all = $plan->inScopeControls();
        $doneAll = count(array_filter($all, fn ($id) => $done($statuses[$id] ?? '')));
        $drifted = array_filter(array_keys($applied), fn ($id) => in_array($id, $all, true) && ! $done($statuses[$id] ?? ''));
        $fwResults = ComplianceEngine::evaluate($tenant, $plan);
        $ssPoints = $tenant->secureScores()->whereNull('error')->first()?->pointsByControl() ?? [];
    @endphp

    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="card stat">
            <div class="label">Overall progress</div>
            <div class="value">{{ count($all) ? round(100 * $doneAll / count($all)) : 0 }}%</div>
            <div class="bar" style="margin:6px 0"><span class="p" style="width:{{ count($all) ? 100 * $doneAll / count($all) : 0 }}%"></span></div>
            <small>{{ $doneAll }} of {{ count($all) }} controls in place</small>
        </div>
        @foreach($fwResults as $key => $fw)
            <a class="card stat" href="{{ route('compliance.tenant', $tenant) }}#fw-{{ $key }}" style="color:inherit">
                <div class="label">{{ FrameworkRegistry::SHORT[$key] }}</div>
                @if(isset($fw['maturity']))
                    <div class="value">ML{{ collect($fw['maturity'])->min('achieved') }}</div><small>target ML{{ $fw['target'] }}</small>
                @else
                    <div class="value">{{ $fw['score'] === null ? '–' : $fw['score'].'%' }}</div><small>{{ $fw['counts']['manual'] }} need evidence</small>
                @endif
            </a>
        @endforeach
        <div class="card stat">
            <div class="label">Drift</div>
            <div class="value" style="color:{{ $drifted ? 'var(--fail)' : 'var(--pass)' }}">{{ count($drifted) }}</div>
            <small>applied controls that no longer pass</small>
        </div>
    </div>

    @if($lastRun)
        <div class="card" style="border-color:{{ $lastRun->dry_run ? 'var(--accent)' : 'var(--pass)' }}">
            <div class="page-head" style="margin-bottom:6px">
                <div>
                    <h2 style="margin:0">{{ $lastRun->dry_run ? 'Preview' : 'Result' }}: {{ $lastRun->scope }}</h2>
                    <div class="pills">@foreach($lastRun->summary ?? [] as $k => $n)<span class="badge {{ ControlPlanRun::RESULTS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(ControlPlanRun::RESULTS[$k][0] ?? $k) }}</span>@endforeach</div>
                </div>
                @if($lastRun->dry_run && $canApply)
                    <form method="post" action="{{ route('plans.apply', $tenant) }}" data-confirm="Apply {{ $lastRun->scope }} to {{ $tenant->name }} now?" data-busy="Applying…">
                        @csrf
                        <input type="hidden" name="mode" value="apply"><input type="hidden" name="scope" value="selected">
                        @foreach(array_keys($lastRun->results) as $cid)<input type="hidden" name="control_ids[]" value="{{ $cid }}">@endforeach
                        <button class="btn btn-primary">Apply this plan now</button>
                    </form>
                @endif
            </div>
            <div class="table-wrap" style="max-height:340px;overflow:auto">
                <table>
                    <tbody>
                    @foreach($lastRun->results as $cid => $r)
                        <tr>
                            <td class="mono" style="width:90px">{{ $cid }}</td>
                            <td>{{ $r['title'] ?? $cid }}</td>
                            <td><span class="badge {{ ControlPlanRun::RESULTS[$r['result']][1] ?? 's-na' }}">{{ ControlPlanRun::RESULTS[$r['result']][0] ?? $r['result'] }}</span></td>
                            <td style="font-size:13px">{{ $r['message'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <form method="post" action="{{ route('plans.apply', $tenant) }}" id="rollout-form">
        @csrf
        @foreach($plan->phaseList() as $n => $ph)
            @php
                $ids = $byPhase[$n] ?? [];
                $phDone = count(array_filter($ids, fn ($id) => $done($statuses[$id] ?? '')));
            @endphp
            <div class="card phase-card">
                <div class="page-head" style="margin-bottom:6px">
                    <div style="flex:1">
                        <h2 style="margin:0">{{ $ph['name'] }}</h2>
                        <p class="muted" style="margin:2px 0 6px">{{ $ph['description'] }}</p>
                        <div style="display:flex;gap:10px;align-items:center">
                            <div class="bar" style="width:260px"><span class="p" style="width:{{ count($ids) ? 100 * $phDone / count($ids) : 0 }}%"></span></div>
                            <small>{{ $phDone }} of {{ count($ids) }} in place</small>
                        </div>
                    </div>
                    @if($canApply && $ids)
                        <div class="actions">
                            <button class="btn btn-sm" name="cmd" value="preview|phase|{{ $n }}">Preview phase</button>
                            <button class="btn btn-sm btn-primary" name="cmd" value="apply|phase|{{ $n }}" data-confirm-btn="Apply {{ $ph['name'] }} to {{ $tenant->name }}?">Apply phase</button>
                        </div>
                    @endif
                </div>
                @if($ids)
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th style="width:28px"><input type="checkbox" data-toggle-all=".sel-{{ $n }}"></th><th>Control</th><th>Frameworks</th><th>How</th><th>Status</th><th>Last applied</th></tr></thead>
                            <tbody>
                            @foreach($ids as $cid)
                                @php
                                    $st = $statuses[$cid] ?? 'unknown';
                                    $set = $plan->setting($cid);
                                    $c = FrameworkRegistry::control($cid);
                                    $isDrift = in_array($cid, $drifted, true);
                                @endphp
                                <tr @if($done($st)) style="background:var(--surface-2)" @endif>
                                    <td><input type="checkbox" class="sel-{{ $n }}" name="control_ids[]" value="{{ $cid }}" @disabled(! $canApply)></td>
                                    <td>
                                        <span class="mono muted">{{ $cid }}</span>
                                        @if($c['source'] === 'posture')<a href="{{ route('tenants.show', $tenant) }}#{{ $cid }}">{{ $c['title'] }}</a>
                                        @else<a href="{{ route('tenants.intune', $tenant) }}">{{ $c['title'] }}</a>@endif
                                    </td>
                                    <td>@foreach($plan->controlFrameworks($cid) as $fk => $reqs)<span class="fw fw-{{ $fk }}" title="{{ implode(', ', $reqs) }}">{{ FrameworkRegistry::SHORT[$fk] }}</span> @endforeach</td>
                                    <td>
                                        <span class="chip {{ $set['type'] === 'intune' ? 'chip-platform' : '' }}">{{ PlanDefaults::TYPES[$set['type']][0] }}</span>
                                        @if($set['assignment'])<br><small class="muted">{{ \App\Services\Intune\IntuneCatalog::TARGETS[$set['assignment']] ?? $set['assignment'] }}</small>@endif
                                    </td>
                                    <td>
                                        <span class="badge {{ ComplianceEngine::CONTROL_STATUS[$st][1] ?? 's-na' }}">{{ ComplianceEngine::CONTROL_STATUS[$st][0] ?? $st }}</span>
                                        @if(! empty($ssPoints[$cid]))<a class="chip chip-ss" href="{{ route('tenants.securescore', $tenant) }}" title="Microsoft Secure Score points still available from actions this control addresses">+{{ round($ssPoints[$cid], 1) }} Secure Score</a>@endif
                                        @if($isDrift)<span class="badge s-fail" title="Was applied by this plan but no longer passes">Drifted</span>@endif
                                    </td>
                                    <td class="muted" style="font-size:13px">{{ isset($applied[$cid]) ? $applied[$cid]->diffForHumans() : '–' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="muted">No controls in this phase.</p>
                @endif
            </div>
        @endforeach

        @if($canApply)
            <div class="sticky-actions">
                <button class="btn" name="cmd" value="preview|selected|">Preview selected</button>
                <button class="btn btn-primary" name="cmd" value="apply|selected|" data-confirm-btn="Apply the selected controls?">Apply selected</button>
                <span class="spacer" style="flex:1"></span>
                <button class="btn" name="cmd" value="preview|all|">Preview all phases</button>
                <button class="btn btn-danger" name="cmd" value="apply|all|" data-confirm-btn="Apply ALL phases of this plan to {{ $tenant->name }} at once?">Apply all at once</button>
            </div>
        @else
            <p class="help">Read-only: your role cannot apply controls.</p>
        @endif
    </form>

    <div class="card" style="margin-top:16px">
        <h2>Run history</h2>
        <table>
            <thead><tr><th>When</th><th>Scope</th><th>Mode</th><th>Result</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse($runs as $r)
                <tr>
                    <td>{{ $r->created_at->diffForHumans() }}</td>
                    <td>{{ $r->scope }}</td>
                    <td>{{ $r->dry_run ? 'Preview' : 'Apply' }}</td>
                    <td>@foreach($r->summary ?? [] as $k => $n)<span class="badge {{ ControlPlanRun::RESULTS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(ControlPlanRun::RESULTS[$k][0] ?? $k) }}</span> @endforeach</td>
                    <td class="muted">{{ $r->user?->name }}</td>
                    <td><a href="{{ route('plans.rollout', [$tenant, 'run' => $r->id]) }}">Details</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">Nothing applied yet. Start with a preview of Phase 1.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif
@endsection
