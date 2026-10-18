@extends('layouts.app')
@section('title', 'Baselines')

@section('content')
<div class="page-head">
    <div>
        <h1>Baselines</h1>
        <p>Pick exactly which settings to apply to which tenant. Switch on the items you want, leave the rest off, and apply.
            Greyed-out switches are already applied. Conditional Access is only created in report-only mode here. Enforce it on the tenant page after review.</p>
    </div>
</div>

<div class="legend">
    <span><label class="switch is-applied"><input type="checkbox" checked disabled><span class="slider"></span></label> Applied</span>
    <span><label class="switch"><input type="checkbox" disabled><span class="slider"></span></label> Not applied (switch on to apply)</span>
    <span><span class="chip chip-pending">Report-only</span> waiting for enforcement</span>
    <span><span class="chip chip-accepted">Risk accepted</span> trade-off recorded</span>
    <span><span class="chip">Manual / N/A</span> cannot be applied automatically</span>
</div>

@if($report)
    <div class="card" style="margin-bottom:16px">
        <h2>Result: {{ $report['name'] }}</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tenant</th><th>Applied</th><th>Skipped</th><th>Failed</th><th>New score</th></tr></thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        <td><strong>{{ $row['tenant'] }}</strong></td>
                        <td>@foreach($row['applied'] as $m)<div style="color:var(--pass)">{{ $m }}</div>@endforeach @if(! $row['applied'])<span class="muted">–</span>@endif</td>
                        <td>@foreach($row['skipped'] as $m)<div class="muted">{{ $m }}</div>@endforeach</td>
                        <td>@foreach($row['failed'] as $m)<div style="color:var(--fail)">{{ $m }}</div>@endforeach</td>
                        <td>{{ $row['score'] === null ? '–' : $row['score'].'%' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@forelse($baselines as $b)
    @php
        $canApply = auth()->user()->can('baselines.apply');
        $ids = array_values(array_filter($b->check_ids, fn ($id) => isset($catalog[$id])));
        $openCount = 0;
        $appliedCount = 0;
        $cells = 0;
        foreach ($tenants as $t) {
            foreach ($ids as $id) {
                $st = $matrix[$t->id][$id]['state'];
                if ($st !== 'unavailable') { $cells++; }
                if ($st === 'open') { $openCount++; }
                if ($st === 'applied') { $appliedCount++; }
            }
        }
    @endphp
    <div class="card baseline" id="baseline-{{ $b->id }}">
        <div class="page-head" style="margin-bottom:8px">
            <div>
                <h2 style="margin:0">{{ $b->name }}</h2>
                @if($b->description)<p>{{ $b->description }}</p>@endif
                @if($cells)
                    <div class="progress-line">
                        <div class="bar" style="width:220px"><span class="p" style="width:{{ 100 * $appliedCount / $cells }}%"></span></div>
                        <small>{{ $appliedCount }} of {{ $cells }} applied · {{ $openCount }} not applied</small>
                    </div>
                @endif
            </div>
            @can('baselines.manage')
                <form method="post" action="{{ route('baselines.destroy', $b) }}" data-confirm="Delete baseline {{ $b->name }}?">@csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button></form>
            @endcan
        </div>

        @if($tenants->isEmpty())
            <p class="muted">Add a tenant to use this baseline.</p>
        @else
            <form method="post" action="{{ route('baselines.apply', $b) }}" class="matrix-form" data-busy="Applying…"
                  data-confirm="Apply the selected items of &quot;{{ $b->name }}&quot;? Only the switches you turned on are changed.">
                @csrf
                <div class="table-wrap matrix-wrap">
                    <table class="matrix">
                        <thead>
                        <tr>
                            <th class="sticky-col">Setting</th>
                            @foreach($tenants as $t)
                                <th class="tcol">
                                    <a href="{{ route('tenants.show', $t) }}">{{ $t->name }}</a>
                                    @if($t->isReady() && $canApply)
                                        <button type="button" class="linkbtn" data-select-col="{{ $t->id }}" title="Switch on every not-applied item for this tenant">all</button>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($ids as $id)
                            @php
                                $rowStates = collect($tenants)->map(fn ($t) => $matrix[$t->id][$id]['state']);
                                $rowApplied = $rowStates->every(fn ($s) => in_array($s, ['applied', 'unavailable'], true)) && $rowStates->contains('applied');
                            @endphp
                            <tr class="{{ $rowApplied ? 'row-applied' : '' }}">
                                <td class="sticky-col">
                                    <div class="setting">
                                        <span class="mono muted">{{ $id }}</span>
                                        <span>{{ $catalog[$id]['title'] }}</span>
                                        <span class="badge sev-{{ $catalog[$id]['severity'] }}">{{ ucfirst($catalog[$id]['severity']) }}</span>
                                    </div>
                                    @if($canApply && ($rowStates->contains('open') || $rowStates->contains('accepted')))
                                        <button type="button" class="linkbtn" data-select-row="{{ $id }}" title="Switch on this item for every tenant where it is not applied">all tenants</button>
                                    @endif
                                </td>
                                @foreach($tenants as $t)
                                    @php
                                        $c = $matrix[$t->id][$id];
                                    @endphp
                                    <td class="cell-td" title="{{ $c['detail'] }}">
                                        @switch($c['state'])
                                            @case('applied')
                                                <span class="cell applied">
                                                    <label class="switch is-applied"><input type="checkbox" checked disabled><span class="slider"></span></label>
                                                    Applied
                                                </span>
                                                @break
                                            @case('open')
                                            @case('accepted')
                                                <span class="cell">
                                                    @if($canApply)
                                                    <label class="switch">
                                                        <input type="checkbox" class="cell-toggle" name="selection[{{ $t->id }}][]" value="{{ $id }}"
                                                               data-row="{{ $id }}" data-col="{{ $t->id }}" data-state="{{ $c['state'] }}">
                                                        <span class="slider"></span>
                                                    </label>
                                                    @endif
                                                    @if($c['state'] === 'accepted')
                                                        <span class="chip chip-accepted">{{ $c['label'] }}</span>
                                                    @else
                                                        <span class="cell-label">Not applied</span>
                                                    @endif
                                                </span>
                                                @break
                                            @case('pending')
                                                <a class="chip chip-pending" href="{{ route('tenants.show', $t) }}#{{ $id }}">Report-only</a>
                                                @break
                                            @default
                                                <span class="chip">{{ $c['label'] }}</span>
                                        @endswitch
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($canApply)
                <div class="matrix-actions">
                    <button type="button" class="btn btn-sm" data-select-open>Switch on all not applied</button>
                    <button type="button" class="btn btn-sm" data-clear>Clear</button>
                    <span class="spacer"></span>
                    <small class="muted"><span data-count>0</span> selected</small>
                    <button class="btn btn-primary btn-sm" data-submit disabled>Apply selected</button>
                </div>
                @else
                <p class="help" style="margin-top:10px">Read-only view: your role cannot apply baseline items.</p>
                @endif
            </form>
        @endif
    </div>
@empty
    <div class="card empty">No baselines yet. Create one below.</div>
@endforelse

@can('baselines.manage')
<div class="card" style="margin-top:16px">
    <h2>Create a baseline</h2>
    <form method="post" action="{{ route('baselines.store') }}">
        @csrf
        <div class="grid grid-2">
            <div class="field"><label>Name</label><input type="text" name="name" required placeholder="e.g. Dental practices standard"></div>
            <div class="field"><label>Description</label><input type="text" name="description"></div>
        </div>
        <label>Controls (fixable only)</label>
        <div class="checks-list" style="margin-bottom:12px">
            @foreach($fixable as $id => $c)
                <label><input type="checkbox" name="check_ids[]" value="{{ $id }}"> <span><span class="mono">{{ $id }}</span> {{ $c['title'] }}</span></label>
            @endforeach
        </div>
        <button class="btn btn-primary">Create baseline</button>
    </form>
</div>
@endcan
@endsection
