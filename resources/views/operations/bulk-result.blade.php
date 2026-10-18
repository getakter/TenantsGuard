@extends('layouts.app')
@section('title', 'Bulk onboarding result')
@use('App\Models\LifecycleRun')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])
@php
    $created = array_filter($results, fn ($r) => $r['run'] && $r['run']->status !== 'failed');
    $csv = "DisplayName,UserPrincipalName,FirstSignIn\n".implode("\n", array_map(fn ($r) => '"'.$r['person']['display_name'].'","'.$r['credentials']['upn'].'","'.($r['credentials']['tap'] ?? $r['credentials']['password']).'"', array_filter($created, fn ($r) => $r['credentials'])));
@endphp

<div class="page-head">
    <div>
        <h1>Bulk onboarding finished</h1>
        <p>{{ count($created) }} of {{ count($results) }} account(s) created in {{ $tenant->name }}.@if($skipped) {{ $skipped }} row(s) were skipped in the preview.@endif</p>
    </div>
    <a class="btn" href="{{ route('operations.tenant', $tenant) }}">Done</a>
</div>

@if($created)
    <div class="card cred-card">
        <h2>First sign-in details: shown only once</h2>
        <p>Copy or download them now. TenantsGuard does not store passwords or passes. Send each person's details to their manager through a secure channel, never the username and password in the same message.</p>
        <a class="btn btn-sm" href="data:text/csv;charset=utf-8,{{ rawurlencode($csv) }}" download="first-sign-in-{{ now()->format('Ymd-His') }}.csv">Download as CSV</a>
    </div>
@endif

<div class="card">
    <table>
        <thead><tr><th>Person</th><th>Sign-in</th><th>First sign-in</th><th>Result</th></tr></thead>
        <tbody>
        @foreach($results as $r)
            <tr>
                <td><strong>{{ $r['person']['display_name'] }}</strong></td>
                <td>{{ $r['person']['upn'] }}</td>
                <td>
                    @if($r['credentials'])
                        <code class="cred" style="user-select:all">{{ $r['credentials']['tap'] ?? $r['credentials']['password'] }}</code>
                        <br><small class="muted">{{ $r['credentials']['tap'] ? 'Temporary Access Pass, valid until '.$r['credentials']['tap_expires'] : 'Temporary password, must be changed' }}</small>
                    @endif
                </td>
                <td>
                    @if($r['run'])
                        <a href="{{ route('operations.run', $r['run']) }}"><span class="badge {{ LifecycleRun::STATUS[$r['run']->status][1] ?? 's-na' }}">{{ LifecycleRun::STATUS[$r['run']->status][0] ?? $r['run']->status }}</span></a>
                    @else
                        <span class="badge s-fail">Failed</span><br><small class="error-text">{{ $r['error'] ?? '' }}</small>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
