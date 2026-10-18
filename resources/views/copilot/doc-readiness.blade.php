@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': Microsoft 365 Copilot readiness')
@use('App\Services\Copilot\Catalog')
@use('App\Services\Copilot\Assessor')

@section('doc')
@php
    $a = $project->assessment;
    $r = $project->requirements;
    $label = ['pass' => 'Ready', 'warn' => 'Attention', 'fail' => 'Blocker', 'info' => 'Info', 'unknown' => 'Unknown'];
@endphp
<div class="cover">
    @if($branding['logo_url'])<img src="{{ $branding['logo_url'] }}" alt="" style="height:40px">@endif
    <div class="muted">Microsoft 365 Copilot · readiness report</div>
    <h1>{{ $project->tenant->name }}</h1>
    <div class="muted">Sponsor: {{ $r['sponsor_name'] ?: 'to be named' }} · assessed {{ $project->assessed_at?->toFormattedDateString() ?? 'not yet' }}</div>
</div>

@if(! $a)
    <p>The readiness assessment has not been run yet.</p>
@else
<section>
    <h2>1. Summary</h2>
    <p><strong>Readiness score: {{ $a['score'] }}%.</strong> {{ collect($a['checks'])->where('status', 'fail')->count() }} blocker(s) and {{ collect($a['checks'])->where('status', 'warn')->count() }} item(s) needing attention.
        {{ $a['copilot']['total'] }} Copilot seat(s) in the tenant ({{ $a['copilot']['used'] }} assigned); {{ $a['eligible'] }} of {{ $a['members'] }} members have an eligible base licence.</p>
    <p>Microsoft 365 Copilot only uses data each person can already access. Its main risk is therefore <strong>existing oversharing</strong>, which Copilot makes easy to find. This plan fixes permissions before the pilot, measures value in the pilot, and rolls out in waves.</p>
    <p><strong>Goals:</strong> {{ collect($r['goals'])->map(fn ($g) => Catalog::GOALS[$g][0] ?? $g)->implode(', ') }}.{{ $r['goal_text'] ? ' "'.$r['goal_text'].'"' : '' }}</p>
</section>

<section>
    <h2>2. Findings</h2>
    @foreach(Assessor::AREAS as $area)
        @php
            $checks = collect($a['checks'])->where('area', $area);
        @endphp
        @continue($checks->isEmpty())
        <h3>{{ $area }}</h3>
        <table>
            <thead><tr><th>Check</th><th>Result</th><th>Detail</th><th>Recommended action</th></tr></thead>
            <tbody>@foreach($checks as $c)<tr><td>{{ $c['title'] }}</td><td><strong>{{ $label[$c['status']] }}</strong></td><td>{{ $c['detail'] }}</td><td>{{ in_array($c['status'], ['pass', 'info'], true) ? '–' : $c['fix'] }}</td></tr>@endforeach</tbody>
        </table>
    @endforeach
</section>

<section>
    <h2>3. Data protection approach</h2>
    <p><strong>{{ Catalog::DATA_APPROACHES[$r['data_approach']][0] }}.</strong> {{ Catalog::DATA_APPROACHES[$r['data_approach']][1] }}</p>
    @if($sites = Catalog::lines($r['sensitive_sites']))<p>Sites hidden from Copilot discovery: {{ implode(', ', $sites) }}.</p>@endif
    <p>Features: web search {{ $r['web_search'] ? 'allowed' : 'off' }}; agents: {{ ['none' => 'none during the pilot', 'approved' => 'IT-approved only', 'all' => 'Microsoft and approved third-party'][$r['agents']] }}; Teams meetings: {{ ['with_transcript' => 'with transcription', 'without_transcript' => 'without transcript', 'off' => 'off'][$r['meetings']] }}; Copilot Chat for unlicensed users: {{ $r['copilot_chat'] ? 'yes' : 'no' }}.</p>
</section>

<section>
    <h2>4. Plan</h2>
    <table>
        <thead><tr><th>Phase</th><th>Task</th><th>Owner</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>@foreach($project->tasks->where('stale', false) as $t)<tr><td>{{ Catalog::STAGES[$t->phase][0] }}</td><td>{{ $t->title }}</td><td>{{ $t->who }}</td><td style="white-space:nowrap">{{ $t->due_date?->format('j M Y') }}</td><td>{{ \App\Models\PurviewTask::STATUS[$t->status][0] }}</td></tr>@endforeach</tbody>
    </table>
</section>
@endif
@endsection
