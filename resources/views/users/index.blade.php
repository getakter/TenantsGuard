@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="page-head">
    <div>
        <h1>Users</h1>
        <p>Your staff who can sign in to TenantsGuard. A role sets what someone can do, and tenant access sets which customers they see.</p>
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('roles.index') }}">Roles &amp; permissions</a>
        <a class="btn btn-primary" href="{{ route('users.create') }}">Add user</a>
    </div>
</div>

<div class="card" style="padding:0">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Role</th><th>Tenant access</th><th>Sign-in</th><th>MFA</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($users as $u)
                <tr @unless($u->is_active) style="opacity:.6" @endunless>
                    <td><strong>{{ $u->name }}</strong>@if($u->is(auth()->user())) <span class="badge s-info">You</span>@endif<br><small>{{ $u->email }}</small></td>
                    <td>
                        @if($u->role)
                            <span class="badge {{ $u->role->isGlobalAdmin() ? 'sev-critical' : 's-na' }}">{{ $u->role->name }}</span>
                        @else
                            <span class="badge s-fail">No role</span>
                        @endif
                    </td>
                    <td>{{ $u->seesAllTenants() ? 'All tenants' : $u->tenants_count.' of '.$tenantCount.' tenants' }}</td>
                    <td>{{ $u->microsoft_oid ? 'Microsoft' : 'Password' }}</td>
                    <td>
                        @if($u->hasMfa())
                            <span class="badge s-pass" title="Registered {{ $u->mfa_enabled_at->format('M j, Y') }}, {{ $u->remainingRecoveryCodes() }} recovery codes left">Registered</span>
                            <br><small class="muted">📱 {{ $u->mfa_device_name ?: 'Authenticator app' }}</small>
                        @else
                            <span class="badge s-manual" title="Will be asked to set up an authenticator at next sign-in">Pending setup</span>
                        @endif
                    </td>
                    <td>{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>@if($u->is_active)<span class="badge s-pass">Active</span>@else<span class="badge s-na">Disabled</span>@endif</td>
                    <td style="white-space:nowrap;text-align:right">
                        @if(! $u->isGlobalAdmin() || auth()->user()->isGlobalAdmin())
                            <a class="btn btn-sm" href="{{ route('users.edit', $u) }}">Edit</a>
                            @unless($u->is(auth()->user()))
                                @if($u->hasMfa())
                                    <form class="inline" method="post" action="{{ route('users.mfa.reset', $u) }}" data-confirm="Remove {{ $u->name }}'s MFA device ({{ $u->mfa_device_name ?: 'authenticator' }})?&#10;&#10;Use this when the phone is lost or stolen. They are signed out everywhere immediately and must register a new device at their next sign-in.">
                                        @csrf <button class="btn btn-sm" title="Lost or stolen phone">Remove MFA device</button>
                                    </form>
                                @endif
                                <form class="inline" method="post" action="{{ route('users.destroy', $u) }}" data-confirm="Delete {{ $u->name }}? Their audit history is kept.">
                                    @csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            @endunless
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
