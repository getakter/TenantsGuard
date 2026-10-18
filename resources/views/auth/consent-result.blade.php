@php($brand = \App\Models\Setting::branding())
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Connected' : 'Not connected' }} · {{ $brand['company_name'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>:root { --accent: {{ $brand['accent_color'] }}; }</style>
</head>
<body>
<div class="login-wrap">
    <div class="card login-card" style="text-align:center">
        <h1 style="font-size:1.25rem">{{ $brand['company_name'] }}</h1>
        <div class="alert {{ $ok ? 'alert-ok' : 'alert-err' }}" style="text-align:left">{{ $message }}</div>
        @if($ok)<p class="muted">Your managed service provider can now review your Microsoft 365 security posture. You can revoke access at any time by deleting the app under Entra ID &gt; Enterprise applications.</p>@endif
    </div>
</div>
</body>
</html>
