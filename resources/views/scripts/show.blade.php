@extends('layouts.app')
@section('title', $script->name)
@use('App\Models\ScriptVersion')

@section('content')
@php
    $me = auth()->user();
    $v = $version;
    $isAuthor = in_array($me->id, array_filter([$v->created_by, $v->submitted_by]), true);
    $canRun = $approved && $me->can($approved->risk === 'change' ? 'scripts.run_change' : 'scripts.run') && ! $script->archived;
@endphp
<div class="page-head">
    <div>
        <h1>{{ $script->name }} @if($script->archived)<span class="badge s-na">Archived</span>@endif</h1>
        <p>{{ $script->description }} <span class="chip">{{ $script->category }}</span></p>
    </div>
    <div class="actions">
        @if($canRun)<a class="btn btn-primary" href="{{ route('scripts.run', $script) }}">@include('partials.icon', ['name' => 'terminal', 'size' => 16]) Run v{{ $approved->version }}</a>@endif
        @can('scripts.manage')
            @unless($script->archived)
                <a class="btn" href="{{ route('scripts.edit', [$script, 'from' => $v->id]) }}">Edit (new version)</a>
                <form method="post" action="{{ route('scripts.archive', $script) }}" class="inline" data-confirm="Archive {{ $script->name }}? It will no longer be able to run.">@csrf <button class="btn btn-danger">Archive</button></form>
            @endunless
        @endcan
    </div>
</div>

<div class="dash-grid-2" style="grid-template-columns:1.6fr 1fr">
    <div>
        <div class="card">
            <div class="card-title" style="flex-wrap:wrap">
                Version {{ $v->version }}
                <span class="badge {{ ScriptVersion::STATUS[$v->status][1] }}">{{ ScriptVersion::STATUS[$v->status][0] }}</span>
                <span class="badge {{ ScriptVersion::RISK[$v->risk][1] }}">{{ ScriptVersion::RISK[$v->risk][0] }}</span>
                @if($v->use_exchange)<span class="chip">Exchange Online</span>@endif
                @if($v->use_graph_module)<span class="chip">Graph PowerShell</span>@endif
                <span class="chip">{{ $v->timeout }} s limit</span>
            </div>
            <dl class="kv">
                <dt>SHA-256</dt><dd class="mono" style="font-size:12px">{{ $v->sha256 }}</dd>
                <dt>Written by</dt><dd>{{ $v->author->name ?? 'TenantsGuard (sample)' }} · {{ $v->created_at->toDayDateTimeString() }}</dd>
                @if($v->submitted_at)<dt>Submitted</dt><dd>{{ $v->submitter->name ?? 'TenantsGuard (sample)' }} · {{ $v->submitted_at->diffForHumans() }}</dd>@endif
                @if($v->reviewed_at)<dt>{{ $v->status === 'rejected' ? 'Rejected' : 'Approved' }} by</dt><dd>{{ $v->reviewer->name ?? '?' }} · {{ $v->reviewed_at->toDayDateTimeString() }}@if($v->review_note) · "{{ $v->review_note }}"@endif</dd>@endif
                @if($v->status === 'approved')<dt>Integrity</dt><dd>@if($v->intact())<span class="badge s-pass">Signature valid</span>@else<span class="badge s-fail">Signature invalid: this version will not run</span>@endif</dd>@endif
                @if($v->change_note)<dt>Change note</dt><dd>{{ $v->change_note }}</dd>@endif
            </dl>
            @if($v->parameters)
                <div class="label-sm" style="margin-top:14px">Parameters</div>
                <table><tbody>@foreach($v->parameters as $p)<tr><td class="mono">${{ $p['name'] }}</td><td>{{ $p['label'] }}</td><td>{{ \App\Services\Scripts\ScriptService::PARAM_TYPES[$p['type']] ?? $p['type'] }}{{ $p['type'] === 'choice' ? ': '.implode(', ', $p['options']) : '' }}</td><td>{{ $p['required'] ? 'Required' : 'Optional' }}</td></tr>@endforeach</tbody></table>
            @endif
        </div>

        @if(! empty($v->lint['blocked']) || ! empty($v->lint['warnings']))
            <div class="card">
                <div class="card-title">Safety checks</div>
                @foreach($v->lint['blocked'] ?? [] as $l)<div class="lint lint-block"><span class="badge s-fail">Blocked</span> Line {{ $l['line'] }}: <code>{{ $l['text'] }}</code>. {{ $l['why'] }}</div>@endforeach
                @foreach($v->lint['warnings'] ?? [] as $l)<div class="lint"><span class="badge s-manual">Review</span> Line {{ $l['line'] }}: <code>{{ $l['text'] }}</code>. {{ $l['why'] }}</div>@endforeach
            </div>
        @else
            <div class="alert alert-ok">Safety checks passed: no blocked commands and no warnings.</div>
        @endif

        <div class="card">
            @if($diff)
                <div class="card-title">Changes from approved v{{ $approved->version }}</div>
                <pre class="code-view diff">@foreach($diff as [$op, $line])<span class="d{{ $op === '+' ? 'add' : ($op === '-' ? 'del' : '') }}">{{ $op }} {{ $line }}</span>
@endforeach</pre>
            @else
                <div class="card-title">Code</div>
                <pre class="code-view">@foreach(preg_split('/\r\n|\r|\n/', rtrim($v->body)) as $i => $line)<span class="ln">{{ $i + 1 }}</span>{{ $line }}
@endforeach</pre>
            @endif
        </div>
    </div>

    <div>
        @if($v->status === 'draft')
            @can('scripts.manage')
                <div class="card">
                    <div class="card-title">Submit for approval</div>
                    @if($v->blocked())
                        <p class="error-text">Fix the blocked items first.</p>
                    @else
                        <p class="muted" style="margin-top:0">Another approver checks the code, the permissions it needs and the safety findings.</p>
                        <form method="post" action="{{ route('scripts.submit', [$script, $v]) }}">@csrf <button class="btn btn-primary">Submit v{{ $v->version }}</button></form>
                    @endif
                </div>
            @endcan
        @endif

        @if($v->status === 'pending')
            <div class="card" style="border-color:var(--warn)">
                <div class="card-title">Review</div>
                @can('scripts.approve')
                    @if($isAuthor && ! $canSelfApprove)
                        <p class="muted">You wrote or submitted this version, so another approver must review it.</p>
                    @else
                        <ul class="sec-list">
                            <li>Does it only do what the description says?</li>
                            <li>Is "{{ ScriptVersion::RISK[$v->risk][0] }}" the right type?</li>
                            <li>Could it leak data (outputs, parameters) or change more clients than intended?</li>
                        </ul>
                        <form method="post" action="{{ route('scripts.review', [$script, $v]) }}">
                            @csrf
                            <input type="hidden" name="confirm_hash" value="{{ $v->sha256 }}">
                            <div class="field"><label>Note (required to reject)</label><textarea name="note" rows="2" maxlength="1000"></textarea></div>
                            <div class="actions">
                                <button class="btn btn-primary" name="decision" value="approve" @disabled($v->blocked()) data-confirm-btn="Approve v{{ $v->version }}? It can then run against client tenants.">Approve</button>
                                <button class="btn btn-danger" name="decision" value="reject">Reject</button>
                            </div>
                            @if($isAuthor)<p class="help">Self-approval is allowed by policy and is marked in the audit log.</p>@endif
                        </form>
                    @endif
                @else
                    <p class="muted">Waiting for someone with the approve permission.</p>
                @endcan
            </div>
        @endif

        <div class="card">
            <div class="card-title">Versions</div>
            @foreach($versions as $x)
                <a class="pick-row" href="{{ route('scripts.show', [$script, 'v' => $x->id]) }}" @if($x->id === $v->id) style="border-color:var(--accent)" @endif>
                    <span>v{{ $x->version }} <small class="muted">{{ $x->created_at->diffForHumans() }} · {{ $x->author->name ?? 'sample' }}</small>@if($x->change_note)<br><small class="muted">{{ \Illuminate\Support\Str::limit($x->change_note, 60) }}</small>@endif</span>
                    <span class="badge {{ ScriptVersion::STATUS[$x->status][1] }}">{{ ScriptVersion::STATUS[$x->status][0] }}</span>
                </a>
            @endforeach
        </div>

        <div class="card">
            <div class="card-title">Recent runs</div>
            @forelse($runs as $r)
                <div style="padding:6px 0;border-bottom:1px solid var(--border)"><a href="{{ route('scripts.runs.show', $r) }}">#{{ $r->id }}</a> · {{ $r->targets_count }} client(s) · {{ $r->user->name ?? '?' }} · <span class="badge {{ \App\Models\ScriptRun::STATUS[$r->status][1] }}">{{ \App\Models\ScriptRun::STATUS[$r->status][0] }}</span> <small class="muted">{{ $r->created_at->diffForHumans() }}</small></div>
            @empty
                <p class="muted">Not run yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection

