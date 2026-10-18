@extends('layouts.app')
@section('title', 'Copilot programmes')
@use('App\Services\Copilot\Catalog')
@use('App\Models\CopilotProject')

@section('content')
<div class="page-head">
    <div>
        <h1>Microsoft 365 Copilot programmes</h1>
        <p>Assess readiness, fix prerequisites and oversharing, run a measured pilot, roll out in waves and keep licences earning their cost.</p>
    </div>
    @can('copilot.manage')
        <form method="post" action="{{ route('copilot.start') }}" class="op-pick" style="margin:0">
            @csrf
            <select name="tenant_id" required>
                <option value="">Start a programme for…</option>
                @foreach($tenants->filter->isReady()->reject(fn ($t) => $projects->has($t->id)) as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
            <button class="btn btn-primary">Start</button>
        </form>
    @endcan
</div>

<div class="dash-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    @foreach([['Assess', 'Live check of licences, user services, MFA, posture, sharing, public groups and labels with a readiness score.'],
              ['Prepare', 'Prerequisite checklist: apps channel, network, oversharing reports, Restricted Content Discovery, Teams policy, Copilot settings.'],
              ['Pilot', 'Pilot group, licences, kick-off, baseline survey, weekly usage against targets and a signed exit.'],
              ['Rollout and adopt', 'Licence waves with seat and eligibility checks, usage tracking and reclaim of inactive licences.']] as [$t, $d])
        <div class="card"><strong>{{ $t }}</strong><p class="muted" style="margin:6px 0 0;font-size:13px">{{ $d }}</p></div>
    @endforeach
</div>

<div class="card">
    <table>
        <thead><tr><th>Client</th><th>Stage</th><th>Readiness</th><th>Copilot seats</th><th>Active (7 days)</th><th>Progress</th><th></th></tr></thead>
        <tbody>
        @forelse($projects as $p)
            @php
                $prog = $p->progress();
                $c = $p->assessment['copilot'] ?? null;
            @endphp
            <tr>
                <td><a href="{{ route('copilot.show', $p) }}"><strong>{{ $p->tenant->name }}</strong></a><br><small class="muted">{{ $p->name }}</small></td>
                <td>{{ Catalog::STAGES[$p->stage][0] ?? $p->stage }}</td>
                <td>{{ isset($p->assessment['score']) ? $p->assessment['score'].'%' : '–' }}</td>
                <td>{{ $c ? $c['used'].' of '.$c['total'].' assigned' : '–' }}</td>
                <td>{{ $p->usage ? $p->usage['active7'].' ('.$p->usage['active7_pct'].'%)' : '–' }}</td>
                <td style="min-width:150px"><div class="bar"><span class="p" style="width:{{ $prog['pct'] }}%"></span></div><small class="muted">{{ $prog['done'] }} of {{ $prog['total'] }}</small></td>
                <td><a class="btn btn-sm" href="{{ route('copilot.show', $p) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No programmes yet. Start one for a client above.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
