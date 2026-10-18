@extends('layouts.app')
@section('title', 'Bulk onboard preview: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])
@php
    $ok = array_values(array_filter($people, fn ($p) => ! $p['errors']));
    $bad = count($people) - count($ok);
    $short = collect($licences)->filter(fn ($l) => in_array($l['skuId'], $shared['licences'], true) && $l['available'] < count($ok));
@endphp

<div class="page-head">
    <div>
        <h1>Check before creating</h1>
        <p>{{ count($ok) }} of {{ count($people) }} people are ready for {{ $tenant->name }}.@if($bad) Rows with problems are skipped. Fix them in the CSV and run them again later.@endif</p>
    </div>
    <a class="btn" href="{{ route('operations.bulk', $tenant) }}">Back</a>
</div>

@foreach($short as $l)
    <div class="alert alert-warn">{{ $l['name'] }} has {{ $l['available'] }} free seat(s) for {{ count($ok) }} people. Accounts after that are created without this licence.</div>
@endforeach

<div class="card">
    <div class="table-wrap">
        <table class="bulk-table">
            <thead><tr><th>Line</th><th>Name</th><th>Sign-in</th><th>Job</th><th>Manager</th><th>Start</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($people as $p)
                <tr class="{{ $p['errors'] ? 'row-err' : '' }}">
                    <td>{{ $p['line'] }}</td>
                    <td><strong>{{ $p['display_name'] }}</strong></td>
                    <td>{{ $p['upn'] }}</td>
                    <td>{{ $p['job_title'] }}{{ $p['department'] ? ' · '.$p['department'] : '' }}</td>
                    <td>{{ $p['manager_name'] ?? '' }}</td>
                    <td>{{ $p['start_date'] ?: 'Now' }}</td>
                    <td>@if($p['errors'])<span class="badge s-fail">Skipped</span><br><small class="error-text">{{ implode(' ', $p['errors']) }}</small>@else<span class="badge s-pass">Ready</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Everyone gets</h2>
    <div class="grid grid-3">
        <div><div class="label-sm">Licences</div>@forelse($shared['licences'] as $s)<div>{{ $licNames[$s] ?? $s }}</div>@empty<span class="muted">None</span>@endforelse</div>
        <div><div class="label-sm">Groups and Teams</div>@forelse($shared['groups'] as $g)<div>{{ $grpNames[$g] ?? $g }}</div>@empty<span class="muted">None</span>@endforelse</div>
        <div><div class="label-sm">First sign-in</div>{{ $shared['password_mode'] === 'tap' ? 'Temporary Access Pass' : 'Temporary password' }}@if($template)<div class="label-sm" style="margin-top:10px">Template</div>{{ $template->name }}@endif</div>
    </div>
</div>

@if($ok)
    <form method="post" action="{{ route('operations.bulk.run', $tenant) }}" data-confirm="Create {{ count($ok) }} account(s) in {{ $tenant->name }}?">
        @csrf
        <div class="sticky-actions">
            <span class="muted">Credentials are shown once on the next page. Have a secure way to hand them over ready.</span>
            <span style="flex:1"></span>
            <button class="btn btn-primary">Create {{ count($ok) }} account(s)</button>
        </div>
    </form>
@endif
@endsection
