@extends('mfa.layout')
@section('title', 'Set up two-step verification')
@section('heading', $signingIn ? 'Set up two-step verification' : ($user->hasMfa() ? 'Replace your authenticator' : 'Register your new device'))
@section('width', '520px')

@section('body')
@if($signingIn)
    <div class="alert alert-warn" style="margin-top:0">Two-step verification is required for every account. Register your phone once to continue.</div>
@elseif($user->hasMfa())
    <p class="muted" style="margin-top:0">Your current device ({{ $user->mfa_device_name }}) keeps working until you confirm the new one below.</p>
@else
    <div class="alert alert-warn" style="margin-top:0">Your previous device was removed. Register a new device to keep using TenantsGuard.</div>
@endif

<ol style="padding-left:18px;margin:0 0 12px">
    <li>Install <strong>Microsoft Authenticator</strong>, Google Authenticator or any TOTP app on your phone.</li>
    <li>In the app choose <em>Add account &gt; Other (Google, Facebook, etc.)</em> and scan this code:</li>
</ol>
<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <div style="background:#fff;padding:8px;border:1px solid var(--border);border-radius:8px;line-height:0">{!! $qr !!}</div>
    <div style="flex:1;min-width:180px">
        <div class="muted" style="font-size:13px">Can't scan? Enter this key manually:</div>
        <div class="mono" style="font-size:1rem;font-weight:700;word-break:break-all;margin:4px 0">{{ $secret }}</div>
        <div class="help">Account: {{ $user->email }} · Time-based · 6 digits</div>
    </div>
</div>
<form method="post" action="{{ route('mfa.confirm') }}">
    @csrf
    <div class="field">
        <label for="device_name">3. Name this device</label>
        <input id="device_name" type="text" name="device_name" maxlength="80" value="{{ old('device_name') }}" placeholder="e.g. Megan's iPhone 15">
        <div class="help">Helps you and your administrator recognise the device if it is ever lost.</div>
    </div>
    <div class="field">
        <label for="code">4. Enter the 6-digit code shown in the app</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" autofocus required
               placeholder="123456" style="font-size:1.4rem;letter-spacing:.3em;text-align:center">
        @error('code')<div class="error-text">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary btn-block">Confirm and continue</button>
</form>
@if($signingIn)
    <form method="post" action="{{ route('mfa.cancel') }}" style="margin-top:10px;text-align:center">
        @csrf <button class="linkbtn" style="margin:0">Cancel</button>
    </form>
@elseif($user->hasMfa())
    <p style="text-align:center;margin-bottom:0"><a href="{{ route('profile.show') }}">Cancel</a></p>
@else
    <form method="post" action="{{ route('logout') }}" style="margin-top:10px;text-align:center">
        @csrf <button class="linkbtn" style="margin:0">Sign out and register later</button>
    </form>
@endif
@endsection
