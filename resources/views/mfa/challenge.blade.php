@extends('mfa.layout')
@section('title', 'Verify sign-in')
@section('heading', 'Two-step verification')

@section('body')
<p style="margin-top:0">Signing in as <strong>{{ $user->email }}</strong>. Open your authenticator app and enter the 6-digit code for {{ \App\Models\Setting::branding()['company_name'] }}.</p>
<form method="post" action="{{ route('mfa.verify') }}">
    @csrf
    <div class="field">
        <label for="code">Authentication code</label>
        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32" autofocus required
               placeholder="123456" style="font-size:1.4rem;letter-spacing:.3em;text-align:center">
        @error('code')<div class="error-text">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary btn-block">Verify</button>
</form>
<details style="margin-top:14px">
    <summary class="muted" style="cursor:pointer">Lost your phone?</summary>
    <p class="help">Enter one of your recovery codes (format <code>abcde-fghij</code>) in the box above. Each code works once. No codes? Ask a Global Administrator to reset your MFA.</p>
</details>
<form method="post" action="{{ route('mfa.cancel') }}" style="margin-top:10px;text-align:center">
    @csrf <button class="linkbtn" style="margin:0">Cancel and sign in as someone else</button>
</form>
@endsection
