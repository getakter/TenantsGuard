@extends('layouts.app')
@section('title', 'Copilot readiness: '.$project->tenant->name)
@use('App\Services\Copilot\Catalog')
@use('App\Services\Copilot\Assessor')
@use('App\Models\CopilotProject')

@section('content')
@include('copilot._head')
@php
    $a = $project->assessment;
    $badge = ['pass' => ['Ready', 's-pass'], 'warn' => ['Attention', 's-manual'], 'fail' => ['Blocker', 's-fail'], 'info' => ['Info', 's-info'], 'unknown' => ['Unknown', 's-na']];
    $r = $project->requirements;
@endphp

@if(! $a)
    <div class="card empty-card"><div><h2>Not assessed yet</h2><p>Save the requirements and run the readiness assessment.</p>
        <a class="btn btn-primary" href="{{ route('copilot.requirements', $project) }}">Requirements</a></div></div>
@else
    <div class="dash-grid">
        <div class="card">
            <div class="card-title">@include('partials.icon', ['name' => 'shield']) Readiness</div>
            <div class="assess">
                <div>
                    <div class="big" style="font-size:2.2rem">{{ $a['score'] ?? '–' }}%</div>
                    <small class="muted">Assessed {{ $project->assessed_at?->diffForHumans() }}</small>
                    <div style="margin-top:10px">
                        @foreach(['fail' => 'blockers', 'warn' => 'to improve', 'pass' => 'ready'] as $st => $l)
                            <span class="badge {{ $badge[$st][1] }}">{{ collect($a['checks'])->where('status', $st)->count() }} {{ $l }}</span>
                        @endforeach
                    </div>
                </div>
                @php
                    $ratio = ($a['score'] ?? 0) / 100;
                    $circ = 2 * M_PI * 46;
                @endphp
                <svg width="120" height="120" viewBox="0 0 120 120" role="img" aria-label="Readiness {{ $a['score'] }}%"><circle cx="60" cy="60" r="46" fill="none" stroke="var(--surface-3)" stroke-width="12"/>
                    @if($ratio > 0)<circle cx="60" cy="60" r="46" fill="none" stroke="{{ $ratio >= .8 ? '#13a10e' : ($ratio >= .5 ? '#f7630c' : '#d13438') }}" stroke-width="12" stroke-linecap="round" stroke-dasharray="{{ round($ratio * $circ, 1) }} {{ round($circ, 1) }}" transform="rotate(-90 60 60)"/>@endif</svg>
            </div>
            @can('copilot.manage')<form method="post" action="{{ route('copilot.assess', $project) }}" data-busy="Assessing…" style="margin-top:12px">@csrf <button class="btn btn-sm">@include('partials.icon', ['name' => 'refresh', 'size' => 14]) Re-assess now</button></form>@endcan
        </div>
        <div class="tiles">
            @foreach([['Copilot seats', $a['copilot']['total'], 'licence', 'ti-orange'], ['Assigned', $a['copilot']['used'], 'user', 'ti-sky'],
                      ['Free seats', $a['copilot']['free'], 'plus', 'ti-green'], ['Eligible users', $a['eligible'].' / '.$a['members'], 'users', 'ti-purple'],
                      ['Public groups', count($a['public_groups']), 'group', 'ti-red'], ['Active (7 days)', $project->usage ? $project->usage['active7'].' / '.$project->usage['licensed'] : '–', 'pulse', 'ti-amber']] as [$l, $v, $i, $cls])
                <div class="tile"><span class="ti {{ $cls }}">@include('partials.icon', ['name' => $i, 'size' => 18])</span><span><small class="muted">{{ $l }}</small><b>{{ $v }}</b></span></div>
            @endforeach
        </div>
        <div class="card">
            <div class="card-title">@include('partials.icon', ['name' => 'check']) Gates</div>
            @foreach(CopilotProject::GATES as $g => [$label])
                @php
                    $s = $project->signoff($g);
                @endphp
                <div class="cap-row"><span>{{ $label }}</span>@if($s)<span class="badge s-pass" title="{{ $s['note'] ?? '' }}">{{ $s['name'] }} · {{ \Carbon\Carbon::parse($s['at'])->toFormattedDateString() }}</span>@else<span class="badge s-na">Open</span>@endif</div>
            @endforeach
            <div class="label-sm" style="margin-top:14px">Progress</div>
            @foreach(Catalog::PHASES as $ph)
                @php
                    $pr = $project->progress($ph);
                @endphp
                <div class="cap-row"><span>{{ Catalog::STAGES[$ph][0] }} @unless($project->unlocked($ph))<span class="badge s-na">Locked</span>@endunless</span><span class="muted">{{ $pr['done'] }}/{{ $pr['total'] }}</span></div>
            @endforeach
        </div>
    </div>

    @foreach(Assessor::AREAS as $area)
        @php
            $checks = collect($a['checks'])->where('area', $area);
        @endphp
        @continue($checks->isEmpty())
        <div class="card">
            <div class="card-title">{{ $area }}</div>
            @foreach($checks as $key => $c)
                <div class="tpl-card">
                    <div style="flex:1"><strong>{{ $c['title'] }}</strong><div class="muted" style="font-size:13px">{{ $c['detail'] }}</div>
                        @if($c['fix'] && in_array($c['status'], ['fail', 'warn', 'unknown'], true))<div class="help">Fix: {{ $c['fix'] }}</div>@endif</div>
                    <span class="badge {{ $badge[$c['status']][1] }}">{{ $badge[$c['status']][0] }}</span>
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="dash-grid-2">
        <div class="card">
            <div class="card-title">Data protection approach</div>
            <p><strong>{{ Catalog::DATA_APPROACHES[$r['data_approach']][0] }}.</strong> <span class="muted">{{ Catalog::DATA_APPROACHES[$r['data_approach']][1] }}</span></p>
            <p class="help">Copilot never gives anyone access they do not already have. It makes existing oversharing visible, which is why permissions are fixed before the pilot.</p>
        </div>
        <div class="card">
            <div class="card-title">Documents for the client</div>
            <a class="pick-row" href="{{ route('copilot.document', [$project, 'readiness']) }}" target="_blank"><span><strong>Readiness report</strong><br><small class="muted">Score, findings, fixes and the plan. Print to PDF.</small></span><span class="pick-go">›</span></a>
            <a class="pick-row" href="{{ route('copilot.document', [$project, 'charter']) }}" target="_blank"><span><strong>Pilot charter</strong><br><small class="muted">Cohort, success criteria, timeline, surveys and a prompt library for the chosen scenarios.</small></span><span class="pick-go">›</span></a>
            <a class="pick-row" href="{{ route('copilot.document', [$project, 'announcement']) }}" target="_blank"><span><strong>Announcement email</strong><br><small class="muted">What Copilot is, what it can see, how to get started.</small></span><span class="pick-go">›</span></a>
        </div>
    </div>
@endif
@endsection
