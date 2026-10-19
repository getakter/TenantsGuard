@php($brand = \App\Models\Setting::branding())
<!doctype html>
<html lang="en">
<head>
@include('partials.favicon')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ $brand['company_name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>:root { --accent: {{ $brand['accent_color'] }}; }</style>
</head>
<body>
<div class="login-wrap">
    <div class="card login-card">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px">
            <span class="brand-mark" style="width:36px;height:36px;border-radius:9px;background:var(--accent);display:grid;place-items:center">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
            </span>
            <div>
                <h1 style="margin:0;font-size:1.25rem">{{ $brand['company_name'] }}</h1>
                <div class="muted">Microsoft 365 security posture console</div>
            </div>
        </div>
        @error('email')<div class="alert alert-err">{{ $message }}</div>@enderror

        @php($msReady = \App\Support\MicrosoftApp::configured())
        @include('partials.ms-button', [
            'href' => route('auth.microsoft'),
            'label' => 'Sign in with Microsoft',
            'block' => true,
            'disabled' => ! $msReady,
            'title' => $msReady ? null : 'Set up the Microsoft app in Settings first',
        ])
        @unless($msReady)
            <div class="help" style="text-align:center;margin-top:6px">Available after the Microsoft app is set up (Settings &gt; Microsoft app).</div>
        @endunless

        <div class="divider">or sign in with email</div>

        <form method="post" action="{{ url('/login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            </div>
            <button class="btn btn-primary" style="width:100%;justify-content:center">Sign in</button>
        </form>
    </div>
</div>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
