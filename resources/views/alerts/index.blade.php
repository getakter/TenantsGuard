@extends('layouts.app')
@section('title', 'Security alerts')

@section('content')
<div class="page-head">
    <div><h1>Security alerts</h1><p>Security events across your clients that need a person to look at them: admin role changes, Conditional Access changes, app consent, break-glass sign-ins, risky users, Defender alerts and Microsoft 365 incidents.</p></div>
    <div class="muted" style="font-size:13px">Monitoring runs every 15 minutes{{ $lastRun ? ' · last run '.\Carbon\Carbon::parse($lastRun)->diffForHumans() : ' · not run yet' }}</div>
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    <div class="card stat"><div class="label">New</div><div class="value {{ $counts['new'] ? 'text-fail' : '' }}">{{ $counts['new'] }}</div><div class="help">not yet looked at</div></div>
    <div class="card stat"><div class="label">Critical open</div><div class="value {{ $counts['critical'] ? 'text-fail' : '' }}">{{ $counts['critical'] }}</div></div>
    <div class="card stat"><div class="label">High open</div><div class="value {{ $counts['high'] ? 'text-warn' : '' }}">{{ $counts['high'] }}</div></div>
    <div class="card stat"><div class="label">Resolved (7 days)</div><div class="value">{{ $counts['resolved_7d'] }}</div></div>
</div>

@include('alerts._list', ['showTenant' => true])
@endsection
