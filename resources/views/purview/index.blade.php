@extends('layouts.app')
@section('title', 'Purview programmes')
@use('App\Services\Purview\Catalog')

@section('content')
<div class="page-head">
    <div>
        <h1>Microsoft Purview programmes</h1>
        <p>Plan and roll out data security for each client: sensitivity labels, data loss prevention, retention and insider risk. Requirements, design, pilot, production and operations in one guided process.</p>
    </div>
    @can('purview.manage')
        <form method="post" action="{{ route('purview.start') }}" class="op-pick" style="margin:0">
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
    @foreach([['1 · Requirements', 'Regulations, sensitive data, locations, protection appetite, retention duties and insider risk concerns.'],
              ['2 · Design', 'Generated label taxonomy, DLP policies, retention schedule and insider risk plan, edited and approved by the compliance owner.'],
              ['3 · Pilot', 'Simulation for everyone, then labels, policy tips and retention labels for a pilot group, with a signed exit gate.'],
              ['4 · Production and operate', 'Enforce for everyone, hand over, and keep a review cadence. Every step verified against the tenant.']] as [$t, $d])
        <div class="card"><strong>{{ $t }}</strong><p class="muted" style="margin:6px 0 0;font-size:13px">{{ $d }}</p></div>
    @endforeach
</div>

<div class="card">
    <table>
        <thead><tr><th>Client</th><th>Stage</th><th>Design</th><th>Progress</th><th>Next gate</th><th></th></tr></thead>
        <tbody>
        @forelse($projects as $p)
            @php
                $prog = $p->progress();
                $gate = collect(\App\Models\PurviewProject::GATES)->keys()->first(fn ($g) => ! $p->signoff($g));
            @endphp
            <tr>
                <td><a href="{{ route('purview.show', $p) }}"><strong>{{ $p->tenant->name }}</strong></a><br><small class="muted">{{ $p->name }}</small></td>
                <td>{{ Catalog::STAGES[$p->stage][0] ?? $p->stage }}</td>
                <td>@if($p->isApproved())<span class="badge s-pass">Approved</span>@elseif($p->design)<span class="badge s-manual">Draft</span>@else<span class="badge s-na">Not started</span>@endif</td>
                <td style="min-width:160px"><div class="bar"><span class="p" style="width:{{ $prog['pct'] }}%"></span></div><small class="muted">{{ $prog['done'] }} of {{ $prog['total'] }} required tasks</small></td>
                <td>{{ $gate ? \App\Models\PurviewProject::GATES[$gate][0] : 'All signed' }}</td>
                <td><a class="btn btn-sm" href="{{ route('purview.show', $p) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No programmes yet. Start one for a client above.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
