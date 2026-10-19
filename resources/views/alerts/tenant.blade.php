@extends('layouts.app')
@section('title', 'Security alerts: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'alerts'])
@php
    $status = $tenant->monitor_status ?? [];
    $canManage = auth()->user()->can('alerts.manage');
    $unavailable = collect($status)->reject(fn ($s) => $s === 'ok');
    $ruleRows = collect($rules)->map(fn ($r, $k) => [$r, $status[$k] ?? null]);
@endphp

<div class="page-head" style="margin-bottom:12px">
    <div>
        <h2 style="margin:0">Security alerts</h2>
        <p class="muted" style="margin:2px 0 0">{{ $counts['open'] }} open · {{ $counts['new'] }} new · last monitored {{ $lastRun ? \Carbon\Carbon::parse($lastRun)->diffForHumans() : 'never' }}</p>
    </div>
    @if($canManage)
        <div class="actions">
            @if($tenant->isDemo())
                <form method="post" action="{{ route('alerts.simulate', $tenant) }}">@csrf <button class="btn">Simulate suspicious activity</button></form>
            @endif
            <form method="post" action="{{ route('alerts.run', $tenant) }}" data-busy="Checking…">@csrf <button class="btn btn-primary">Check now</button></form>
        </div>
    @endif
</div>

@if(empty($breakGlass))
    <div class="alert alert-warn"><strong>No break-glass accounts set.</strong> Add this client's emergency access accounts below. Any sign-in by them raises a critical alert, which is one of the strongest signals of compromise or misuse.</div>
@endif

@include('alerts._list', ['showTenant' => false])

<div class="card" style="margin-top:16px" id="monitoring-settings">
    <h2>Monitoring settings</h2>
    <form method="post" action="{{ route('alerts.settings', $tenant) }}">
        @csrf
        <fieldset @disabled(! $canManage) style="border:0;padding:0;margin:0">
            <div class="field" style="max-width:640px">
                <label for="break_glass">Break-glass (emergency access) accounts</label>
                <textarea id="break_glass" name="break_glass" rows="2" placeholder="breakglass01@contoso.onmicrosoft.com, breakglass02@contoso.onmicrosoft.com">{{ old('break_glass', implode(', ', $breakGlass)) }}</textarea>
                <div class="help">Sign-in names, separated by commas. These accounts should never be used day to day, so every sign-in is reported. Needs Entra ID P1 for sign-in logs.</div>
                @error('break_glass')<div class="error-text">{{ $message }}</div>@enderror
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:56px">On</th><th>Detection</th><th>Source</th><th>Status in this client</th></tr></thead>
                    <tbody>
                    @foreach($ruleRows as $key => [[$name, $desc, $sev, $source], $st])
                        <tr>
                            <td><span class="switch"><input type="checkbox" name="rules[]" value="{{ $key }}" @checked(in_array($key, $enabledRules, true)) aria-label="{{ $name }}"><span class="slider"></span></span></td>
                            <td><strong>{{ $name }}</strong><div class="help">{{ $desc }}</div></td>
                            <td class="muted" style="font-size:13px">{{ $source }}</td>
                            <td>
                                @if(! in_array($key, $enabledRules, true))<span class="badge s-neutral">Off</span>
                                @elseif($st === null)<span class="badge s-neutral">Not run yet</span>
                                @elseif($st === 'ok')<span class="badge s-pass">Working</span>
                                @else<span class="badge s-manual">Unavailable</span><div class="help">{{ $st }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($unavailable->isNotEmpty())
                <p class="help">Unavailable detections usually need a licence (Entra ID P1/P2) or the <a href="{{ route('access.tenant', $tenant) }}">Threat alerts and service health</a> capability granted in this client.</p>
            @endif
        </fieldset>
        @if($canManage)<button class="btn btn-primary" style="margin-top:12px">Save monitoring settings</button>@endif
    </form>
</div>
@endsection
