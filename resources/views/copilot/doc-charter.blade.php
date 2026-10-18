@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': Copilot pilot charter')
@use('App\Services\Copilot\Catalog')

@section('doc')
@php
    $r = $project->requirements;
    $users = collect($project->assessment['users'] ?? [])->where('pilot', true);
    $start = \Carbon\Carbon::parse($r['start_date'])->addDays(21);
    $end = $start->copy()->addWeeks(max(2, (int) $r['pilot_weeks']));
@endphp
<div class="cover">
    <div class="muted">Microsoft 365 Copilot · pilot charter</div>
    <h1>{{ $project->tenant->name }}</h1>
    <div class="muted">Pilot {{ $start->toFormattedDateString() }} to {{ $end->toFormattedDateString() }} · sponsor {{ $r['sponsor_name'] ?: 'to be named' }}</div>
</div>

<section>
    <h2>1. Purpose</h2>
    <p>Prove that Microsoft 365 Copilot saves time and improves work quality for {{ $project->tenant->name }}, find any data exposure safely, and decide on the wider rollout with evidence.</p>
    @if($r['goal_text'])<p><em>"{{ $r['goal_text'] }}"</em></p>@endif
</section>

<section>
    <h2>2. Success criteria</h2>
    <table><tbody>
        <tr><th>Weekly active users</th><td>at least {{ $r['target_active'] }}% of pilot users use Copilot every week</td></tr>
        <tr><th>Time saved</th><td>at least {{ $r['target_hours'] }} hours per user per week (self-reported)</td></tr>
        <tr><th>Satisfaction</th><td>at least {{ $r['target_satisfaction'] }}% want to keep Copilot</td></tr>
        <tr><th>Data exposure</th><td>every "Copilot showed me something I should not see" report fixed at the source</td></tr>
    </tbody></table>
</section>

<section>
    <h2>3. Pilot group ({{ $users->count() }})</h2>
    @if($users->isNotEmpty())<p>{{ $users->map(fn ($u) => $u['name'].($u['department'] ? ' ('.$u['department'].')' : ''))->implode(', ') }}.</p>@else<p>To be chosen.</p>@endif
    <p>Champions: {{ $r['champions'] }}. Training: {{ ['live' => 'live sessions', 'self' => 'self-paced', 'both' => 'live kick-off and self-paced content'][$r['training']] }}.</p>
</section>

<section>
    <h2>4. Scenarios and prompt library</h2>
    @foreach($r['goals'] as $g)
        @continue(! isset(Catalog::GOALS[$g]))
        <h3>{{ Catalog::GOALS[$g][0] }} <small class="muted">({{ Catalog::GOALS[$g][1] }})</small></h3>
        <ul>@foreach(Catalog::GOALS[$g][2] as $p)<li>{{ $p }}</li>@endforeach</ul>
    @endforeach
    <p class="note">Good prompts state the goal, the context (reference files with /), the expected format and the audience. Always check the answer and its sources.</p>
</section>

<section>
    <h2>5. Surveys</h2>
    <h3>Baseline (before day one)</h3>
    <ol>
        <li>How many hours a week do you spend on email, meetings follow-up and writing documents?</li>
        <li>Which task would you most like help with?</li>
        <li>How confident are you using AI tools today (1 to 5)?</li>
    </ol>
    <h3>End of pilot</h3>
    <ol>
        <li>How many hours a week did Copilot save you?</li>
        <li>Which three tasks did it help with most?</li>
        <li>Did Copilot show you any content you should not have access to? Where?</li>
        <li>Would you be disappointed if Copilot were taken away (yes / somewhat / no)?</li>
        <li>What would make Copilot more useful for your role?</li>
    </ol>
</section>

<section>
    <h2>6. Ways of working</h2>
    <ul>
        <li>Weekly 15-minute check-in run by a champion; usage reviewed by IT every week.</li>
        <li>Report data exposure to IT immediately; the permission is fixed at the source, not hidden.</li>
        <li>Copilot answers are drafts: the user remains responsible for what is sent or published.</li>
    </ul>
</section>
@endsection
