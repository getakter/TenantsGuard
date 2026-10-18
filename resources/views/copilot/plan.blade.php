@extends('layouts.app')
@section('title', 'Copilot plan: '.$project->tenant->name)
@use('App\Services\Copilot\Catalog')
@use('App\Services\Purview\Planner')
@use('App\Models\PurviewTask')
@use('App\Models\CopilotProject')

@section('content')
@include('copilot._head')
@php
    $me = auth()->user();
    $gateFor = ['gate_readiness' => 'readiness', 'gate_pilot' => 'pilot', 'gate_rollout' => 'rollout'];
@endphp

@if($project->tasks->isEmpty())
    <div class="card empty-card">Save the requirements and run the assessment first. The plan is built from them.</div>
@endif
@error('task')<div class="alert alert-err">{{ $message }}</div>@enderror
@error('gate')<div class="alert alert-err">{{ $message }}</div>@enderror

<div class="legend">@foreach(Planner::METHODS as $k => [$l, $c])<span><span class="badge {{ $c }}">{{ $l }}</span></span>@endforeach <span>Tasks verified against the tenant show a verification badge.</span></div>

@foreach(Catalog::PHASES as $phase)
    @php
        $tasks = $project->tasks->where('phase', $phase)->where('stale', false);
        $unlocked = $project->unlocked($phase);
        $pr = $project->progress($phase);
    @endphp
    @continue($tasks->isEmpty())
    <div class="phase-head">
        <h2>{{ Catalog::STAGES[$phase][0] }}</h2>
        <span class="muted">{{ Catalog::STAGES[$phase][1] }}</span>
        <span style="flex:1"></span>
        @unless($unlocked)<span class="badge s-na">@include('partials.icon', ['name' => 'lock', 'size' => 12]) Locked until the previous gate</span>@endunless
        <span class="muted">{{ $pr['done'] }}/{{ $pr['total'] }}</span>
    </div>
    @foreach($tasks as $t)
        <details class="task {{ $unlocked ? '' : 'locked' }} {{ $t->overdue() ? 'overdue' : '' }}" id="task-{{ $t->id }}">
            <summary>
                <span><strong>{{ $t->title }}</strong>@unless($t->required) <small class="muted">(recommended)</small>@endunless<br><small class="muted">{{ $t->who }}{{ $t->owner ? ' · '.$t->owner->name : '' }} · <span class="due">due {{ $t->due_date?->toFormattedDateString() }}</span></small></span>
                <span class="badge {{ Planner::METHODS[$t->method][1] }}">{{ Planner::METHODS[$t->method][0] }}</span>
                <span>@if($t->verify_status)<span class="badge {{ PurviewTask::VERIFY[$t->verify_status][1] }}" title="{{ $t->verify_detail }}">{{ PurviewTask::VERIFY[$t->verify_status][0] }}</span>@endif</span>
                <span class="badge {{ PurviewTask::STATUS[$t->status][1] }}">{{ PurviewTask::STATUS[$t->status][0] }}</span>
            </summary>
            <div class="tb">
                <div>
                    <p style="margin-top:0">{{ $t->why }}</p>
                    @if($t->steps)<ol style="padding-left:18px;margin:0">@foreach($t->steps as $s)<li style="margin-bottom:4px">{{ $s }}</li>@endforeach</ol>@endif
                    <div class="actions" style="margin-top:12px">
                        @if($t->link)<a class="btn btn-sm" href="{{ $t->link }}" target="_blank" rel="noopener">Open admin page @include('partials.icon', ['name' => 'external', 'size' => 13])</a>@endif
                        @if($t->method === 'script')<a class="btn btn-sm" href="{{ route('copilot.implement', $project) }}#scripts">Get the {{ Catalog::STAGES[$t->phase][0] }} script</a>@endif
                        @if(str_starts_with($t->key, 'wave_') || $t->key === 'public_groups')
                            <a class="btn btn-sm btn-primary" href="{{ route('copilot.implement', $project) }}#{{ $t->key === 'public_groups' ? 'groups' : 'licences' }}">Open the {{ $t->key === 'public_groups' ? 'public groups' : 'licence assignment' }} tool</a>
                        @endif
                        @if($t->method === 'auto' && ! str_starts_with($t->key, 'wave_') && $me->can('copilot.manage') && $unlocked && $t->status !== 'done')
                            <form method="post" action="{{ route('copilot.task.run', [$project, $t]) }}" class="inline" data-busy="Working…" data-confirm="Let TenantsGuard do this in {{ $project->tenant->name }} now?">@csrf <button class="btn btn-sm btn-primary">Do it now</button></form>
                        @endif
                    </div>
                    @if($t->verify_detail)<p class="help" style="margin-top:10px">{{ $t->verify_detail }}</p>@endif
                    @if($t->completed_at)<p class="help">Completed {{ $t->completed_at->toDayDateTimeString() }}{{ $t->completer ? ' by '.$t->completer->name : '' }}.</p>@endif
                </div>
                <div>
                    @if($t->method === 'gate')
                        @php
                            $gate = $gateFor[$t->key] ?? null;
                        @endphp
                        @if($t->status === 'done')
                            <div class="alert alert-ok">{{ $t->verify_detail }}@if($t->note) · "{{ $t->note }}"@endif</div>
                        @elseif($gate && $me->can('copilot.approve') && $unlocked)
                            <form method="post" action="{{ route('copilot.signoff', [$project, $gate]) }}">
                                @csrf
                                <div class="field"><label>Sign-off note</label><input type="text" name="note" maxlength="1000" placeholder="Evidence, decisions, exceptions"></div>
                                <label style="font-weight:400;display:flex;gap:8px;margin-bottom:8px"><input type="checkbox" name="confirm" value="1" required> I confirm the exit criteria are met.</label>
                                <button class="btn btn-primary btn-sm">Sign off: {{ CopilotProject::GATES[$gate][0] }}</button>
                            </form>
                        @else
                            <p class="muted">Signed off by someone with the Copilot approve permission, once every required task in this phase is done.</p>
                        @endif
                    @elseif($me->can('copilot.manage'))
                        <form method="post" action="{{ route('copilot.task.update', [$project, $t]) }}">
                            @csrf
                            <div class="grid grid-2">
                                <div class="field"><label>Status</label><select name="status">@foreach(PurviewTask::STATUS as $k => [$l])<option value="{{ $k }}" @selected($t->status === $k)>{{ $l }}</option>@endforeach</select></div>
                                <div class="field"><label>Owner</label><select name="owner_id"><option value="">–</option>@foreach($staff as $s)<option value="{{ $s->id }}" @selected($t->owner_id === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                            </div>
                            <div class="field"><label>Evidence / notes</label><textarea name="note" rows="3" maxlength="4000" placeholder="What was done, decisions, links, ticket numbers">{{ $t->note }}</textarea></div>
                            <button class="btn btn-sm">Save</button>
                        </form>
                    @else
                        <p class="muted">{{ $t->note }}</p>
                    @endif
                </div>
            </div>
        </details>
    @endforeach
@endforeach
@endsection

@push('scripts')
<script>
// Open and scroll to the task named in the URL (after saving or from "What to do next").
if (location.hash.startsWith('#task-')) { const el = document.querySelector(location.hash); if (el) { el.open = true; el.scrollIntoView({ block: 'center' }); } }
</script>
@endpush
