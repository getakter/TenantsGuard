@extends('layouts.app')
@section('title', 'Roll out: '.$plan->name)
@use('App\Models\ControlPlanRun')
@use('App\Services\Compliance\FrameworkRegistry')

@section('content')
<div class="page-head">
    <div>
        <h1>Roll out across clients: {{ $plan->name }}</h1>
        <p>
            @foreach($plan->frameworks as $f)<span class="fw fw-{{ $f['key'] }}">{{ FrameworkRegistry::SHORT[$f['key']] ?? $f['key'] }}@if(! empty($f['target_level'])) ML{{ $f['target_level'] }}@endif</span> @endforeach
            · {{ count($plan->inScopeControls()) }} controls · {{ $tenants->count() }} client(s) on this plan
        </p>
    </div>
    <div class="actions">
        @can('compliance.manage')<a class="btn" href="{{ route('compliance.profiles.edit', $plan) }}">Edit plan</a>@endcan
        <a class="btn" href="{{ route('compliance.index') }}#profiles">All plans</a>
    </div>
</div>

@if($batch->isNotEmpty())
    <div class="card">
        <h2>{{ $batch->first()->dry_run ? 'Preview' : 'Result' }}: {{ $batch->first()->scope }}</h2>
        <table>
            <thead><tr><th>Client</th><th>Result</th><th></th></tr></thead>
            <tbody>
            @foreach($batch as $run)
                <tr>
                    <td><strong>{{ $run->tenant?->name }}</strong></td>
                    <td>@foreach($run->summary ?? [] as $k => $n)<span class="badge {{ ControlPlanRun::RESULTS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(ControlPlanRun::RESULTS[$k][0] ?? $k) }}</span> @endforeach</td>
                    <td><a href="{{ route('plans.rollout', [$run->tenant_id, 'run' => $run->id]) }}">Details</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if($tenants->isEmpty())
    <div class="card empty">
        <h2>No clients use this plan yet</h2>
        @can('compliance.manage')<p><a href="{{ route('compliance.profiles.edit', $plan) }}#tenants">Assign clients to the plan</a></p>@endcan
    </div>
@else
    <form method="post" action="{{ route('compliance.portfolio.apply', $plan) }}">
        @csrf
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:28px"><input type="checkbox" data-toggle-all=".pt"></th>
                        <th>Client</th>
                        @foreach($plan->phaseList() as $n => $ph)<th title="{{ $ph['description'] }}">{{ \Illuminate\Support\Str::limit($ph['name'], 28) }}</th>@endforeach
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($tenants as $t)
                        <tr>
                            <td><input type="checkbox" class="pt" name="tenant_ids[]" value="{{ $t->id }}" @disabled(! $t->isReady())></td>
                            <td><strong>{{ $t->name }}</strong> @foreach($t->tags ?? [] as $tg)<span class="tag">{{ $tg }}</span>@endforeach
                                @unless($t->isReady())<br><span class="badge s-manual">Consent {{ $t->consent_status }}</span>@endunless</td>
                            @foreach($plan->phaseList() as $n => $ph)
                                @php
                                    $m = $matrix[$t->id][$n] ?? ['done' => 0, 'total' => 0];
                                    $pct = $m['total'] ? round(100 * $m['done'] / $m['total']) : 0;
                                @endphp
                                <td>
                                    @if($m['total'])
                                        <div class="bar" style="width:100px"><span class="p" style="width:{{ $pct }}%"></span></div>
                                        <small class="{{ $pct === 100 ? '' : 'muted' }}">{{ $m['done'] }}/{{ $m['total'] }}</small>
                                    @else<span class="muted">–</span>@endif
                                </td>
                            @endforeach
                            <td><a class="btn btn-sm" href="{{ route('plans.rollout', $t) }}">Open</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @can('fixes.apply')
            <div class="sticky-actions">
                <select name="scope" style="width:auto">
                    @foreach($plan->phaseList() as $n => $ph)<option value="{{ $n }}">{{ $ph['name'] }}</option>@endforeach
                    <option value="all">All phases</option>
                </select>
                <button class="btn" name="mode" value="preview">Preview for selected clients</button>
                <button class="btn btn-primary" name="mode" value="apply" data-confirm-btn="Apply the chosen phase to every selected client?">Apply to selected clients</button>
            </div>
        @endcan
    </form>
@endif
@endsection
