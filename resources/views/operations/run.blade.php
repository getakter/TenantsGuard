@extends('layouts.app')
@section('title', ($run->type === 'onboard' ? 'Joiner: ' : 'Leaver: ').$run->target_name)
@use('App\Models\LifecycleRun')
@use('App\Models\LifecycleTask')

@section('content')
@include('tenants._nav', ['tenant' => $run->tenant, 'active' => 'people'])

<div class="page-head">
    <div>
        <h1>{{ $run->type === 'onboard' ? 'Joiner' : 'Leaver' }}: {{ $run->target_name }}</h1>
        <p>{{ $run->target_upn }} · {{ $run->created_at->format('D j M Y, H:i') }} by {{ $run->user?->name ?? 'unknown' }}@if($run->ticket) · Ticket {{ $run->ticket }}@endif</p>
    </div>
    <span class="badge {{ LifecycleRun::STATUS[$run->status][1] }}" style="font-size:14px">{{ LifecycleRun::STATUS[$run->status][0] }}</span>
</div>

@if($credentials && ($credentials['password'] || $credentials['tap']))
    <div class="card cred-card">
        <h2>🔑 First sign-in details. Shown once, not stored.</h2>
        <dl class="kv">
            <dt>Sign-in name</dt><dd class="mono">{{ $credentials['upn'] }}</dd>
            @if($credentials['tap'])
                <dt>Temporary Access Pass</dt><dd class="mono cred">{{ $credentials['tap'] }}</dd>
                <dt>Valid until</dt><dd>{{ $credentials['tap_expires'] }}</dd>
            @else
                <dt>Temporary password</dt><dd class="mono cred">{{ $credentials['password'] }}</dd>
                <dt>Note</dt><dd>Must be changed at first sign-in.</dd>
            @endif
        </dl>
        <details style="margin-top:10px">
            <summary style="cursor:pointer;font-weight:600">Welcome message for the manager (without the secret)</summary>
            <textarea rows="8" readonly onclick="this.select()" style="margin-top:8px">Hi,

{{ $run->target_name }}'s Microsoft 365 account is ready.

Sign-in name: {{ $credentials['upn'] }}
@if($credentials['tap'])On their first day they open https://aka.ms/mysecurityinfo, sign in with the Temporary Access Pass we send separately, and set up Microsoft Authenticator or a passkey.
@else They sign in at https://portal.office.com with the temporary password we send separately, choose a new password and set up Microsoft Authenticator.
@endif

Please never share passwords by email. Contact us if anything doesn't work.</textarea>
        </details>
        <p class="help">Send the secret through a different channel from the sign-in name, for example a one-time secret link or a phone call.</p>
    </div>
@endif

<div class="card">
    <h2>Steps</h2>
    <table>
        <tbody>
        @foreach($run->steps as $s)
            <tr>
                <td style="width:120px"><span class="badge {{ LifecycleRun::STEP_STATUS[$s['status']][1] ?? 's-na' }}">{{ LifecycleRun::STEP_STATUS[$s['status']][0] ?? $s['status'] }}</span></td>
                <td>
                    <strong>{{ $s['label'] }}</strong> @if($s['kind'] === 'manual')<span class="chip">Checklist</span>@elseif($s['kind'] === 'scheduled')<span class="chip chip-platform">Scheduled</span>@endif
                    <div class="muted" style="font-size:13px;white-space:pre-line">{{ $s['message'] }}</div>
                    @if(! empty($s['by']))<small class="muted">{{ $s['status'] === 'done' ? 'Done' : 'Updated' }} by {{ $s['by'] }} · {{ \Carbon\Carbon::parse($s['at'])->diffForHumans() }}</small>@endif
                </td>
                <td style="width:150px;text-align:right">
                    @if($s['kind'] === 'manual' && auth()->user()->can($run->type === 'onboard' ? 'staff.onboard' : 'staff.offboard'))
                        <form method="post" action="{{ route('operations.step', [$run, $s['key']]) }}">@csrf
                            <button class="btn btn-sm {{ $s['status'] === 'done' ? '' : 'btn-primary' }}">{{ $s['status'] === 'done' ? 'Reopen' : 'Mark done' }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if($run->tasks->isNotEmpty())
    <div class="card">
        <h2>Scheduled actions</h2>
        <table>
            <tbody>
            @foreach($run->tasks as $t)
                <tr>
                    <td>{{ LifecycleTask::ACTIONS[$t->action] }}</td>
                    <td>{{ $t->execute_at->format('D j M Y, H:i') }} <small class="muted">({{ $t->execute_at->diffForHumans() }})</small></td>
                    <td><span class="badge {{ ['pending' => 's-info', 'done' => 's-pass', 'failed' => 's-fail', 'cancelled' => 's-na'][$t->status] }}">{{ ucfirst($t->status) }}</span> <small class="muted">{{ $t->message }}</small></td>
                    <td>@if($t->status === 'pending')<form method="post" action="{{ route('operations.task.cancel', $t) }}" data-confirm="Cancel this scheduled action?">@csrf <button class="btn btn-sm">Cancel</button></form>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if($run->type === 'offboard' && $run->snapshot)
    <details class="card">
        <summary style="cursor:pointer;font-weight:700">Access before offboarding (audit record)</summary>
        <div class="grid grid-4" style="margin-top:12px">
            <div><div class="label-sm">Licences</div>@forelse($run->snapshot['licences'] as $l)<div>{{ $l['name'] }}</div>@empty<span class="muted">None</span>@endforelse</div>
            <div><div class="label-sm">Groups</div>@forelse($run->snapshot['groups'] as $g)<div>{{ $g['name'] }}</div>@empty<span class="muted">None</span>@endforelse</div>
            <div><div class="label-sm">Admin roles</div>@forelse($run->snapshot['roles'] as $r)<div>{{ $r['name'] }}</div>@empty<span class="muted">None</span>@endforelse</div>
            <div><div class="label-sm">Devices</div>@forelse($run->snapshot['devices'] as $d)<div>{{ $d['name'] }} <small class="muted">{{ $d['os'] }}</small></div>@empty<span class="muted">None</span>@endforelse</div>
        </div>
    </details>
@endif

@if($run->notes)<div class="card"><div class="label-sm">Notes</div>{{ $run->notes }}</div>@endif
@endsection
