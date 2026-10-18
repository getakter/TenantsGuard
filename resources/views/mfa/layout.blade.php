@php
    $brand = \App\Models\Setting::branding();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $brand['company_name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>:root { --accent: {{ $brand['accent_color'] }}; }</style>
</head>
<body>
<div class="login-wrap">
    <div class="card login-card" style="max-width:@yield('width', '400px')">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <span style="width:36px;height:36px;border-radius:9px;background:var(--accent);display:grid;place-items:center;flex:none">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            </span>
            <div>
                <h1 style="margin:0;font-size:1.2rem">@yield('heading')</h1>
                <div class="muted">{{ $brand['company_name'] }}</div>
            </div>
        </div>
        @if(session('status'))<div class="alert alert-ok">{{ session('status') }}</div>@endif
        @yield('body')
    </div>
</div>
</body>
</html>
