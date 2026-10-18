@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')

@section('content')
@php
    $access = old('access', $user->all_tenants ? 'all' : 'selected');
    $selected = array_map('intval', old('tenant_ids', $assigned));
    $currentRole = (int) old('role_id', $user->role_id ?? $roles->firstWhere('key', 'technician')?->id);
@endphp
<div class="page-head">
    <div>
        <h1>{{ $user->exists ? 'Edit '.$user->name : 'Add user' }}</h1>
        <p>Staff sign in with the email and password set here, or with "Sign in with Microsoft" using the same email.</p>
    </div>
    <a class="btn" href="{{ route('users.index') }}">Back to users</a>
</div>

<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
    @csrf
    @if($user->exists) @method('put') @endif

    <div class="grid grid-2">
        <div class="card">
            <h2>Account</h2>
            <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $user->name) }}" required></div>
            <div class="field"><label>Email</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
            <div class="field">
                <label>{{ $user->exists ? 'New password (leave empty to keep)' : 'Password' }}</label>
                <input type="password" name="password" autocomplete="new-password">
                <div class="help">At least 10 characters with letters and numbers.
                    @unless($user->exists) Leave empty if the user will only use "Sign in with Microsoft". @endunless</div>
            </div>
            <div class="field">
                <label style="font-weight:400;display:flex;gap:8px;align-items:center">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))> Account is active (can sign in)
                </label>
            </div>
        </div>

        <div class="card">
            <h2>Role</h2>
            @foreach($roles as $role)
                <label class="option" style="display:block;margin-bottom:8px;font-weight:400;cursor:pointer">
                    <input type="radio" name="role_id" value="{{ $role->id }}" @checked($currentRole === $role->id) required>
                    <strong>{{ $role->name }}</strong> @if(! $role->is_system)<span class="badge s-info">Custom</span>@endif
                    <div class="help">{{ $role->description }}</div>
                </label>
            @endforeach
            <p class="help"><a href="{{ route('roles.index') }}" target="_blank">See exactly what each role can do</a></p>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Tenant access</h2>
        <p class="muted">Global Administrators always see every tenant.</p>
        <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:6px">
            <input type="radio" name="access" value="all" @checked($access === 'all')> <strong>All tenants</strong>, including tenants added later
        </label>
        <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:10px">
            <input type="radio" name="access" value="selected" @checked($access === 'selected')> <strong>Only selected tenants</strong>
        </label>
        <div class="checks-list" id="tenant-picker">
            @forelse($tenants as $t)
                <label><input type="checkbox" name="tenant_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, $selected, true))> <span>{{ $t->name }} <small>{{ $t->domain }}</small></span></label>
            @empty
                <p class="muted">No tenants yet.</p>
            @endforelse
        </div>
        @error('tenant_ids')<div class="error-text">{{ $message }}</div>@enderror
    </div>

    <div style="margin-top:16px" class="actions">
        <button class="btn btn-primary">{{ $user->exists ? 'Save user' : 'Create user' }}</button>
        <a class="btn" href="{{ route('users.index') }}">Cancel</a>
    </div>
</form>

@if($user->exists)
    <div class="card" style="margin-top:16px">
        <h2>Two-step verification (MFA)</h2>
        @if($user->hasMfa())
            <dl class="kv" style="margin-bottom:12px">
                <dt>Registered device</dt><dd><strong>📱 {{ $user->mfa_device_name ?: 'Authenticator app' }}</strong></dd>
                <dt>Registered</dt><dd>{{ $user->mfa_enabled_at->format('M j, Y H:i') }}</dd>
                <dt>Recovery codes left</dt><dd>{{ $user->remainingRecoveryCodes() }} of 10</dd>
            </dl>
            @if($user->is(auth()->user()))
                <p class="muted">Manage your own device on <a href="{{ route('profile.show') }}">My account</a>.</p>
            @else
                <form method="post" action="{{ route('users.mfa.reset', $user) }}"
                      data-confirm="Remove {{ $user->name }}'s MFA device ({{ $user->mfa_device_name ?: 'authenticator' }})?&#10;&#10;They are signed out everywhere immediately and must register a new device at their next sign-in.">
                    @csrf
                    <button class="btn btn-danger">Remove MFA device (lost or stolen phone)</button>
                    <div class="help">Codes from the old device stop working immediately. The user registers a new device the next time they sign in with their password or Microsoft account.</div>
                </form>
            @endif
        @else
            <p><span class="badge s-manual">No device registered</span> {{ $user->name }} must register an authenticator at their next sign-in.</p>
        @endif
    </div>
@endif

<script>
    (function () {
        const picker = document.getElementById('tenant-picker');
        const sync = () => {
            const selected = document.querySelector('input[name=access]:checked')?.value === 'selected';
            picker.style.opacity = selected ? 1 : .45;
            picker.querySelectorAll('input').forEach((i) => (i.disabled = !selected));
        };
        document.querySelectorAll('input[name=access]').forEach((r) => r.addEventListener('change', sync));
        sync();
    })();
</script>
@endsection
