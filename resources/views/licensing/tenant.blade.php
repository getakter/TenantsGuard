@extends('layouts.app')
@section('title', 'Licences: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'licensing'])
@php
    $cur = $r['currency'] ?? \App\Services\Licensing\LicenseInsights::currency();
    $money = fn ($v) => $cur.' '.number_format((float) $v, 2);
    $t = $r['totals'] ?? null;
    $canOffboard = auth()->user()->can('staff.offboard');
@endphp

<div class="page-head" style="margin-bottom:12px">
    <div>
        <h2 style="margin:0">Licence optimisation</h2>
        @if($r)<p class="muted" style="margin:2px 0 0">Read {{ \Carbon\Carbon::parse($r['read_at'])->diffForHumans() }} · estimates in {{ $cur }} per month</p>@endif
    </div>
    <div class="actions">
        <form method="get" class="actions">
            <label for="days" class="muted" style="font-size:13px">Idle after</label>
            <select id="days" name="days" onchange="this.form.submit()">@foreach(\App\Services\Licensing\LicenseInsights::INACTIVE_DAYS as $d)<option value="{{ $d }}" @selected($days === $d)>{{ $d }} days</option>@endforeach</select>
        </form>
        @if($r)<a class="btn" href="{{ route('licensing.export', [$tenant, 'days' => $days]) }}">Export CSV</a>@endif
        <a class="btn btn-primary" href="{{ route('licensing.tenant', [$tenant, 'days' => $days, 'refresh' => 1]) }}">Refresh</a>
    </div>
</div>

@if($error)
    <div class="alert alert-err">{{ $error }}</div>
@elseif($r)
    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="card stat"><div class="label">Monthly spend</div><div class="value">{{ $money($t['monthly']) }}</div></div>
        <div class="card stat"><div class="label">Potential savings</div><div class="value {{ $t['savings'] > 0 ? 'text-warn' : '' }}">{{ $money($t['savings']) }}</div><div class="help">{{ $money($t['savings'] * 12) }} per year</div></div>
        <div class="card stat"><div class="label">Unassigned seats</div><div class="value">{{ $t['unused_seats'] }}</div><div class="help">{{ $money($t['unused_cost']) }}</div></div>
        <div class="card stat"><div class="label">People to review</div><div class="value">{{ count($r['disabled']) + count($r['inactive']) + count($r['overlaps']) }}</div><div class="help">disabled, idle or duplicate</div></div>
    </div>

    @if(! $r['signins'])
        <div class="alert alert-warn">Sign-in activity is not available in this client (needs Entra ID P1 and AuditLog.Read.All), so idle users are not shown.</div>
    @endif
    @if($r['unpriced'])
        <p class="help">No price set for {{ implode(', ', $r['unpriced']) }}; they count as {{ $cur }} 0. Add prices under <a href="{{ route('licensing.index') }}">Licence optimisation</a>.</p>
    @endif

    <div class="card" style="padding:0">
        <div class="card-title" style="padding:16px 16px 0">Subscriptions</div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Licence</th><th class="num">Purchased</th><th class="num">Assigned</th><th class="num">Unassigned</th><th class="num">Price</th><th class="num">Monthly</th><th class="num">Unassigned cost</th></tr></thead>
                <tbody>
                @foreach($r['skus'] as $s)
                    <tr class="{{ $s['free'] ? 'is-off' : '' }}">
                        <td>{{ $s['name'] }} @if($s['free'])<span class="chip">Free or trial</span>@endif @if($s['suspended'])<span class="chip chip-pending">{{ $s['suspended'] }} expiring/suspended</span>@endif<div class="help mono">{{ $s['part'] }}</div></td>
                        <td class="num">{{ $s['free'] ? '–' : $s['total'] }}</td>
                        <td class="num">{{ $s['used'] }}</td>
                        <td class="num">{{ $s['unused'] ?: '–' }}</td>
                        <td class="num">{{ $s['priced'] ? $money($s['price']) : '–' }}</td>
                        <td class="num">{{ $s['monthly'] ? $money($s['monthly']) : '–' }}</td>
                        <td class="num">@if($s['waste'] > 0)<strong class="text-warn">{{ $money($s['waste']) }}</strong>@else – @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @foreach([
        ['disabled', 'Disabled accounts that still hold licences', 'Sign-in is blocked, but the licences are still paid for. Remove them, or convert the mailbox to a shared mailbox first.', $r['disabled']],
        ['inactive', 'No sign-in for '.$days.'+ days', 'Check with the client whether these people left or changed role. Shared and service mailboxes usually do not need a paid licence.', $r['inactive']],
    ] as [$key, $title, $help, $list])
        @if($list)
            <div class="card" style="padding:0;margin-top:16px">
                <div class="card-title" style="padding:16px 16px 0">{{ $title }} <small class="muted" style="font-weight:400">{{ count($list) }} · {{ $money(array_sum(array_column($list, 'cost'))) }} per month</small></div>
                <p class="help" style="padding:0 16px">{{ $help }}</p>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Person</th><th>Licences</th>@if($key === 'inactive')<th>Last sign-in</th>@endif<th class="num">Monthly</th><th></th></tr></thead>
                        <tbody>
                        @foreach($list as $u)
                            <tr>
                                <td><a href="{{ route('operations.user', [$tenant, $u['id']]) }}">{{ $u['name'] }}</a><div class="help">{{ $u['upn'] }}</div></td>
                                <td>@foreach($u['licences'] as $l)<span class="chip">{{ $l }}</span> @endforeach</td>
                                @if($key === 'inactive')<td>{{ $u['last_signin'] ? \Carbon\Carbon::parse($u['last_signin'])->toFormattedDateString() : 'Never' }}</td>@endif
                                <td class="num">{{ $money($u['cost']) }}</td>
                                <td>@if($canOffboard)<a class="btn btn-sm" href="{{ route('operations.offboard', [$tenant, 'users' => [$u['id']]]) }}">Offboard</a>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach

    @if($r['overlaps'])
        <div class="card" style="padding:0;margin-top:16px">
            <div class="card-title" style="padding:16px 16px 0">Duplicate licences <small class="muted" style="font-weight:400">{{ count($r['overlaps']) }} · {{ $money($t['overlap_cost']) }} per month</small></div>
            <p class="help" style="padding:0 16px">Every service in the first licence is already included in the second one. Remove the first licence; check that no feature depends on it first.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Person</th><th>Remove</th><th>Already included in</th><th class="num">Monthly</th></tr></thead>
                    <tbody>
                    @foreach($r['overlaps'] as $o)
                        <tr><td><a href="{{ route('operations.user', [$tenant, $o['id']]) }}">{{ $o['name'] }}</a><div class="help">{{ $o['upn'] }}</div></td><td>{{ $o['redundant'] }}</td><td>{{ $o['covered_by'] }}</td><td class="num">{{ $money($o['cost']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if(! $r['disabled'] && ! $r['inactive'] && ! $r['overlaps'] && ! $t['unused_seats'])
        <div class="card empty-state"><strong>No waste found.</strong><p class="muted">Every paid seat is assigned to an active person.</p></div>
    @endif
@endif
@endsection
