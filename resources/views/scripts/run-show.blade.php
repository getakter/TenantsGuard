@extends('layouts.app')
@section('title', 'Script run #'.$run->id)
@use('App\Models\ScriptRun')
@use('App\Models\ScriptRunTarget')
@use('App\Models\ScriptVersion')

@section('content')
@php
    $v = $run->version;
    $me = auth()->user();
    $hasResults = $targets->contains(fn ($t) => ! empty($t->results));
    $cellText = fn ($cell) => is_bool($cell) ? ($cell ? 'True' : 'False') : (is_scalar($cell) || $cell === null ? $cell : json_encode($cell));
@endphp
<div class="page-head">
    <div>
        <h1>{{ $v->script->name }} <small class="muted">run #{{ $run->id }}</small></h1>
        <p>
            <span class="badge {{ ScriptRun::STATUS[$run->status][1] }}" id="runStatus">@if($run->isActive())<span class="spin"></span> @endif{{ ScriptRun::STATUS[$run->status][0] }}</span>
            v{{ $v->version }} · <span class="badge {{ ScriptVersion::RISK[$v->risk][1] }}">{{ ScriptVersion::RISK[$v->risk][0] }}</span>
            · started by {{ $run->user->name ?? '?' }} {{ $run->created_at->diffForHumans() }}
        </p>
    </div>
    <div class="actions">
        @if($hasResults)
            <a class="btn" href="{{ route('scripts.runs.export', [$run, 'csv']) }}">@include('partials.icon', ['name' => 'download', 'size' => 15]) CSV</a>
            <a class="btn" href="{{ route('scripts.runs.export', [$run, 'json']) }}">JSON</a>
        @endif
        @if($run->isActive() && ($run->user_id === $me->id || $me->can('scripts.approve')))
            <form method="post" action="{{ route('scripts.runs.cancel', $run) }}" class="inline" data-confirm="Cancel this run? Clients still queued are skipped and a running client is stopped.">@csrf <button class="btn btn-danger">Cancel run</button></form>
        @endif
    </div>
</div>

<div class="card">
    <dl class="kv">
        <dt>Reason</dt><dd>{{ $run->reason }}@if($run->ticket) · ticket {{ $run->ticket }}@endif</dd>
        <dt>Parameters</dt><dd>@forelse($run->parameters ?? [] as $k => $val)<span class="chip">{{ $k }} = {{ is_bool($val) ? ($val ? 'true' : 'false') : $val }}</span> @empty<span class="muted">none</span>@endforelse</dd>
        <dt>Code</dt><dd class="mono" style="font-size:12px"><a href="{{ route('scripts.show', [$v->script, 'v' => $v->id]) }}">v{{ $v->version }}</a> · SHA-256 {{ $v->sha256 }}</dd>
        <dt>Security</dt><dd>{{ $run->mfa_verified ? 'Authenticator code verified · ' : '' }}from {{ $run->ip }}</dd>
        <dt>Progress</dt><dd>{{ $targets->filter->isDone()->count() }} of {{ $targets->count() }} client(s) finished
            @if($hidden) · {{ $hidden }} more client(s) in this run are not assigned to you @endif</dd>
    </dl>
</div>

@foreach($targets as $t)
    <details class="target" @if($loop->count <= 3 || in_array($t->status, ['failed', 'timeout'], true)) open @endif>
        <summary>
            <strong style="flex:1">{{ $t->tenant->name }}</strong>
            @if($t->results !== null)<small class="muted">{{ count($t->results) }} result(s)</small>@endif
            @if($t->duration_ms !== null)<small class="muted">{{ number_format($t->duration_ms / 1000, 1) }} s</small>@endif
            <span class="badge {{ ScriptRunTarget::STATUS[$t->status][1] }}" data-target="{{ $t->id }}">@if($t->status === 'running')<span class="spin"></span> @endif{{ ScriptRunTarget::STATUS[$t->status][0] }}</span>
        </summary>
        <div class="tbody">
            @if($t->error)<div class="alert alert-err" style="margin-bottom:12px">{{ $t->error }}</div>@endif
            @if(! $t->isDone())
                <p class="muted">{{ $t->status === 'queued' ? 'Waiting for its turn. Each client runs in its own session, one after another.' : 'Running now…' }}</p>
            @endif
            @if(! empty($t->results))
                @php
                    $rows = array_slice($t->results, 0, 200);
                    $cols = collect($rows)->filter(fn ($r) => is_array($r))->flatMap(fn ($r) => array_keys($r))->unique()->take(12)->values();
                @endphp
                <div class="table-wrap" style="max-height:420px;overflow:auto">
                    <table>
                        @if($cols->isNotEmpty())
                            <thead><tr>@foreach($cols as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                            <tbody>@foreach($rows as $r)<tr>@foreach($cols as $c)<td>{{ $cellText(is_array($r) ? ($r[$c] ?? null) : null) }}</td>@endforeach</tr>@endforeach</tbody>
                        @else
                            <tbody>@foreach($rows as $r)<tr><td>{{ is_scalar($r) ? $r : json_encode($r) }}</td></tr>@endforeach</tbody>
                        @endif
                    </table>
                </div>
                @if(count($t->results) > 200)<p class="help">Showing 200 of {{ count($t->results) }}. Export for the full list.</p>@endif
            @elseif($t->isDone() && $t->status === 'succeeded')
                <p class="muted">The script finished and returned no objects.</p>
            @endif
            @if($t->output)
                <div class="label-sm" style="margin-top:12px">Log</div>
                <pre class="log">{{ $t->output }}</pre>
            @endif
            @if($t->truncated)<p class="help">Output was cut at the size limit.</p>@endif
        </div>
    </details>
@endforeach
@endsection

@push('scripts')
@if($run->isActive())
<script>
const LABELS = {!! json_encode(array_map(fn ($s) => $s[0], ScriptRunTarget::STATUS)) !!};
// Poll until finished, then reload to show results. Status only: no output travels over this endpoint.
(function poll() {
  setTimeout(async () => {
    try {
      const res = await fetch(@json(route('scripts.runs.status', $run)), { headers: { Accept: 'application/json' } });
      const data = await res.json();
      const changed = data.targets.some((t) => { const el = document.querySelector('[data-target="' + t.id + '"]'); return el && !el.textContent.includes(LABELS[t.status]); });
      if (!data.active || changed) { location.reload(); return; }
    } catch (e) {}
    poll();
  }, 3000);
})();
</script>
@endif
@endpush
