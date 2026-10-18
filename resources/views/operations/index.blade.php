@extends('layouts.app')
@section('title', 'Lifecycle activity')
@use('App\Models\LifecycleRun')
@use('App\Models\LifecycleTask')

@section('content')
<div class="page-head">
    <div>
        <h1>Lifecycle activity</h1>
        <p>Identity management across your clients: joiners and leavers, scheduled actions and open checklist items in one place.</p>
    </div>
    <div class="actions">
        @can('staff.onboard')
            <a class="btn" href="{{ route('identity.pick', 'bulk') }}">Bulk onboard users</a>
            <a class="btn" href="{{ route('templates.index') }}">User templates</a>
        @endcan
    </div>
</div>

<div class="grid grid-2" style="margin-bottom:16px">
    <div class="card op-start">
        <h2>👋 Onboard user</h2>
        <p class="muted">Account, manager, licences, groups and Teams, first sign-in (temporary password or Temporary Access Pass), start date and hand-over checklist.</p>
        @can('staff.onboard')
            <form method="get" onsubmit="if(!this.t.value){return false;} this.action=this.t.value; return true;" class="op-pick">
                <select name="t" required>
                    <option value="">Choose client…</option>
                    @foreach($tenants as $t)<option value="{{ route('operations.onboard', $t) }}">{{ $t->name }}</option>@endforeach
                </select>
                <button class="btn btn-primary">Start onboarding</button>
            </form>
        @else
            <p class="help">Your role cannot onboard staff.</p>
        @endcan
    </div>
    <div class="card op-start">
        <h2>🚪 Offboard users</h2>
        <p class="muted">Immediate lock-out, MFA removal, out-of-office reply, access and device removal, delayed licence removal, scheduled deletion and a tracked manual checklist.</p>
        @can('staff.offboard')
            <form method="get" onsubmit="if(!this.t.value){return false;} this.action=this.t.value; return true;" class="op-pick">
                <select name="t" required>
                    <option value="">Choose client…</option>
                    @foreach($tenants as $t)<option value="{{ route('operations.offboard', $t) }}">{{ $t->name }}</option>@endforeach
                </select>
                <button class="btn btn-danger">Start offboarding</button>
            </form>
        @else
            <p class="help">Your role cannot offboard staff.</p>
        @endcan
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>Open checklist items ({{ $open->sum(fn ($r) => count(array_filter($r->steps, fn ($s) => $s['status'] === 'pending'))) }})</h2>
        @forelse($open as $r)
            <div class="op-open">
                <a href="{{ route('operations.run', $r) }}"><strong>{{ $r->type === 'onboard' ? 'Joiner' : 'Leaver' }}: {{ $r->target_name }}</strong></a>
                <small class="muted">{{ $r->tenant->name }} · {{ $r->created_at->diffForHumans() }}</small>
                <ul>@foreach(array_filter($r->steps, fn ($s) => $s['status'] === 'pending') as $s)<li>{{ $s['label'] }}</li>@endforeach</ul>
            </div>
        @empty
            <p class="muted">Nothing open. 🎉</p>
        @endforelse
    </div>
    <div class="card">
        <h2>Scheduled actions</h2>
        <table>
            <tbody>
            @forelse($tasks as $task)
                <tr>
                    <td style="white-space:nowrap">{{ $task->execute_at->format('D j M, H:i') }}<br><small class="muted">{{ $task->execute_at->diffForHumans() }}</small></td>
                    <td>{{ LifecycleTask::ACTIONS[$task->action] }}<br><small><a href="{{ route('operations.run', $task->run) }}">{{ $task->run->target_upn }}</a> · {{ $task->tenant->name }}</small></td>
                    <td>
                        <form method="post" action="{{ route('operations.task.cancel', $task) }}" data-confirm="Cancel this scheduled action?">@csrf <button class="btn btn-sm">Cancel</button></form>
                    </td>
                </tr>
            @empty
                <tr><td class="muted">No scheduled actions.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="help">Scheduled actions run with the scheduler (Settings > System health).</p>
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h2>Recent joiners and leavers</h2>
    @include('operations._runs', ['runs' => $recent, 'showTenant' => true])
</div>
@endsection
