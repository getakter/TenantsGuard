@extends('layouts.app')
@section('title', 'Purview: '.$project->tenant->name)
@use('App\Services\Purview\Catalog')
@use('App\Models\PurviewProject')
@use('App\Models\PurviewTask')

@section('content')
@include('purview._head')
@php
    $r = $project->requirements;
@endphp

<div class="dash-grid">
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'licence']) Licence readiness</div>
        @foreach(Catalog::CAPABILITIES as $cap => [$label, , $needs])
            <div class="cap-row"><span>{{ $label }}</span>
                @if(($caps[$cap] ?? null) === true)<span class="badge s-pass">Licensed</span>@elseif(($caps[$cap] ?? null) === false)<span class="badge s-na" title="{{ $needs }}">Not licensed</span>@else<span class="badge s-manual">Unknown</span>@endif
            </div>
        @endforeach
    </div>
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'chart']) Progress</div>
        @foreach(Catalog::PHASES as $ph)
            @php
                $pr = $project->progress($ph);
            @endphp
            <div class="lic-bar">
                <div class="row"><span>{{ Catalog::STAGES[$ph][0] }} @unless($project->unlocked($ph))<span class="badge s-na">Locked</span>@endunless</span><span class="muted">{{ $pr['done'] }}/{{ $pr['total'] }}</span></div>
                <div class="bar"><span style="width:{{ $pr['pct'] }}%"></span></div>
            </div>
        @endforeach
        <div class="label-sm" style="margin-top:14px">Gates</div>
        @foreach(PurviewProject::GATES as $g => [$label])
            @php
                $s = $g === 'design' ? ($project->isApproved() ? $project->signoff('design') : null) : $project->signoff($g);
            @endphp
            <div class="cap-row"><span>{{ $label }}</span>@if($s)<span class="badge s-pass" title="{{ $s['note'] ?? '' }}">{{ $s['name'] }} · {{ \Carbon\Carbon::parse($s['at'])->toFormattedDateString() }}</span>@else<span class="badge s-na">Open</span>@endif</div>
        @endforeach
    </div>
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'user']) Programme</div>
        <dl class="facts">
            <dt>Compliance owner</dt><dd>{{ $r['owner_name'] ?: 'not set' }} @if($r['owner_email'])<small class="muted">{{ $r['owner_email'] }}</small>@endif</dd>
            <dt>Scope</dt><dd>{{ Catalog::INDUSTRIES[$r['industry']] ?? '' }} · {{ Catalog::REGIONS[$r['region']] ?? '' }} · {{ $r['users'] }} users</dd>
            <dt>Regulations</dt><dd>{{ collect($r['regulations'] ?? [])->map(fn ($x) => Catalog::REGULATIONS[$x][0] ?? $x)->implode(', ') ?: 'none selected' }}</dd>
            <dt>Start</dt><dd>{{ \Carbon\Carbon::parse($r['start_date'])->toFormattedDateString() }} · pilot {{ $r['pilot_weeks'] }} weeks · {{ count($r['pilot_users'] ?? []) }} pilot members</dd>
        </dl>
    </div>
</div>

@if($gaps)
    <div class="alert alert-warn"><strong>Licence gaps affect the design.</strong>
        @foreach($gaps as $g)<div>{{ $g['label'] }}: needs {{ $g['needs'] }}.</div>@endforeach
        The design leaves these out or uses a fallback. Buying the add-on and regenerating the design brings them back.
    </div>
@endif

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">What to do next</div>
        @if(! $project->design)
            <p>Complete the requirements, then generate the design.</p><a class="btn btn-primary" href="{{ route('purview.requirements', $project) }}">Requirements</a>
        @elseif(! $project->isApproved())
            <p>Review the generated design with the compliance owner and approve it. Implementation is locked until then.</p><a class="btn btn-primary" href="{{ route('purview.design', $project) }}">Review the design</a>
        @else
            @forelse($next as $t)
                <a class="pick-row" href="{{ route('purview.plan', $project) }}#task-{{ $t->id }}">
                    <span><strong>{{ $t->title }}</strong><br><small class="muted">{{ Catalog::STAGES[$t->phase][0] }} · {{ $t->who }} · due {{ $t->due_date?->toFormattedDateString() }}</small></span>
                    <span class="badge {{ \App\Services\Purview\Planner::METHODS[$t->method][1] }}">{{ \App\Services\Purview\Planner::METHODS[$t->method][0] }}</span>
                    @if($t->overdue())<span class="badge s-fail">Overdue</span>@endif
                </a>
            @empty
                <p class="muted">Nothing open in unlocked phases.</p>
            @endforelse
        @endif
    </div>
    <div class="card">
        <div class="card-title">Documents for the client</div>
        @if($project->design)
            <a class="pick-row" href="{{ route('purview.document', [$project, 'design']) }}" target="_blank"><span><strong>Design document</strong><br><small class="muted">Scope, decisions, labels, DLP, retention, insider risk, rollout plan, RACI. Print to PDF.</small></span><span class="pick-go">›</span></a>
            <a class="pick-row" href="{{ route('purview.document', [$project, 'announcement']) }}" target="_blank"><span><strong>Staff announcement email</strong><br><small class="muted">Why, what changes, what users need to do, where to get help.</small></span><span class="pick-go">›</span></a>
            <a class="pick-row" href="{{ route('purview.document', [$project, 'guide']) }}" target="_blank"><span><strong>Label quick-reference guide</strong><br><small class="muted">One page: which label to choose, with examples.</small></span><span class="pick-go">›</span></a>
        @else
            <p class="muted">Available after the design is generated.</p>
        @endif
    </div>
</div>
@endsection
