<!doctype html>
<html lang="en">
<head>
@include('partials.favicon')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('doctitle')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        :root { --accent: {{ $branding['accent_color'] }}; }
        body { background: #fff; color: #0f172a; }
        .report { max-width: 980px; margin: 0 auto; padding: 32px 24px 60px; }
        .cover { border-bottom: 4px solid var(--accent); padding-bottom: 18px; margin-bottom: 24px; }
        .cover h1 { font-size: 1.9rem; margin: 6px 0; }
        section { margin-bottom: 28px; }
        section h2 { border-bottom: 1px solid var(--border); padding-bottom: 6px; margin-top: 8px; }
        table { font-size: 13px; }
        th { background: #f1f5f9; }
        .note { font-size: 12px; color: var(--muted); }
        .footer { margin-top: 40px; color: var(--muted); font-size: 12px; border-top: 1px solid var(--border); padding-top: 10px; }
        @media print { .no-print { display: none; } .report { padding: 0; } section { break-inside: auto; } h2 { break-after: avoid; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<div class="report">
    <div class="no-print" style="text-align:right;margin-bottom:12px"><button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button></div>
    @yield('doc')
    <div class="footer">{{ $branding['report_footer'] }} · Prepared by {{ $branding['company_name'] }} for {{ $project->tenant->name }} · {{ now()->format('F j, Y') }}</div>
</div>
</body>
</html>
