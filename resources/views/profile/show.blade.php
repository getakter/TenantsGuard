@extends('layouts.app')
@section('title', 'My account')

@section('content')
<div class="page-head">
    <div><h1>My account</h1><p>{{ $user->email }}</p></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>Access</h2>
        <dl class="kv">
            <dt>Role</dt><dd><strong>{{ $user->role?->name ?? 'None' }}</strong></dd>
            <dt>Tenants</dt>
            <dd>
                @if($user->seesAllTenants())
                    All tenants
                @else
                    @forelse($user->tenants as $t){{ $t->name }}@if(! $loop->last), @endif @empty None assigned @endforelse
                @endif
            </dd>
            <dt>Sign-in</dt><dd>{{ $user->microsoft_oid ? 'Microsoft account linked' : 'Password' }}</dd>
        </dl>
        <h3 style="margin-top:14px">You can</h3>
        <ul style="margin:0;padding-left:18px">
            @foreach($permissions as $label)<li>{{ $label }}</li>@endforeach
        </ul>
    </div>

    <div class="card">
        <h2>Change password</h2>
        <form method="post" action="{{ route('profile.password') }}">
            @csrf
            <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
            <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password"><div class="help">At least 10 characters with letters and numbers.</div></div>
            <div class="field"><label>Confirm new password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div>
            <button class="btn btn-primary">Change password</button>
        </form>
        @if($user->microsoft_oid)
            <p class="help" style="margin-top:10px">You sign in with Microsoft, so you don't need a password. If you never set one, ask a Global Administrator to set one for you.</p>
        @endif
    </div>
</div>

<div class="card" style="margin-top:16px">
    <h2>Two-step verification (MFA)</h2>
    <dl class="kv" style="margin-bottom:10px">
        <dt>Status</dt><dd><span class="badge s-pass">On</span></dd>
        <dt>Registered device</dt><dd><strong>📱 {{ $user->mfa_device_name ?: 'Authenticator app' }}</strong></dd>
        <dt>Registered</dt><dd>{{ $user->mfa_enabled_at?->format('M j, Y H:i') }}</dd>
        <dt>Recovery codes</dt><dd>{{ $user->remainingRecoveryCodes() }} of 10 left @if($user->remainingRecoveryCodes() <= 3)<span class="badge s-fail">Generate new codes</span>@endif</dd>
    </dl>
    <p class="muted">MFA is required for every TenantsGuard account, including sign-in with Microsoft. Confirm with a current code from your authenticator to:</p>
    @error('mfa_code')<div class="alert alert-err">{{ $message }}</div>@enderror
    <div class="grid grid-2">
        <form method="post" action="{{ route('mfa.regenerate') }}" class="option">
            @csrf
            <h3 style="margin-top:0">New recovery codes</h3>
            <p class="help">Replaces all existing recovery codes.</p>
            <div class="field"><input type="text" name="code" inputmode="numeric" placeholder="6-digit code" required maxlength="8"></div>
            <button class="btn btn-sm">Generate new codes</button>
        </form>
        <form method="post" action="{{ route('mfa.replace') }}" class="option">
            @csrf
            <h3 style="margin-top:0">New phone or app</h3>
            <p class="help">Move MFA to a new device. Your current app works until the new one is confirmed.</p>
            <div class="field"><input type="text" name="code" inputmode="numeric" placeholder="6-digit code" required maxlength="8"></div>
            <button class="btn btn-sm">Replace authenticator</button>
        </form>
    </div>

    <div class="option" style="margin-top:16px;border-color:var(--fail)">
        <h3 style="margin-top:0">Lost your phone?</h3>
        <p class="help">Remove <strong>{{ $user->mfa_device_name ?: 'your registered device' }}</strong> without needing the app. Confirm with your password or one of your recovery codes. All your other sessions are signed out, and you register your new phone straight away.</p>
        @error('remove_confirmation')<div class="alert alert-err">{{ $message }}</div>@enderror
        <form method="post" action="{{ route('mfa.remove') }}" data-confirm="Remove {{ $user->mfa_device_name ?: 'this device' }}? Codes from it will stop working immediately.">
            @csrf
            <div class="field"><input type="password" name="confirmation" placeholder="Password or recovery code" required autocomplete="current-password"></div>
            <button class="btn btn-sm btn-danger">Remove device and register a new one</button>
        </form>
    </div>
</div>
@endsection
