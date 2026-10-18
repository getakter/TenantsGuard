@use('App\Services\Checks\Catalog')
@extends('layouts.app')
@section('title', 'Controls & frameworks')

@section('content')
<div class="page-head">
    <div>
        <h1>Controls &amp; framework mapping</h1>
        <p>{{ count($catalog) }} controls evaluated on every scan, mapped to the frameworks your clients and insurers ask about.</p>
    </div>
</div>

<div class="alert alert-warn">
    Framework references are best-effort mappings. Before you present them as formal compliance evidence, validate them against the exact benchmark versions you report on.
</div>

<div class="grid grid-4" style="margin-bottom:16px">
    @foreach(Catalog::FRAMEWORKS as $k => $label)
        @php($n = collect($catalog)->filter(fn ($c) => ! empty($c['frameworks'][$k]))->count())
        @if($k !== 'ins' || $n)
            <div class="card stat"><div class="label">{{ $label }}</div><div class="value">{{ $n }}</div><small>mapped controls</small></div>
        @endif
    @endforeach
</div>

<div class="card" style="padding:0">
    <div class="table-wrap">
        <table>
            <thead>
            <tr><th>ID</th><th>Control</th><th>Severity</th><th>CIS M365</th><th>CIS v8.1</th><th>NIST CSF 2.0</th><th>Essential Eight</th><th>Insurance</th><th>Automation</th></tr>
            </thead>
            <tbody>
            @foreach($catalog as $id => $c)
                <tr>
                    <td class="mono">{{ $id }}</td>
                    <td><strong>{{ $c['title'] }}</strong><br><small>{{ $c['category'] }}@if($c['license']) · requires {{ ['p1' => 'Entra ID P1', 'p2' => 'Entra ID P2', 'intune' => 'Intune'][$c['license']] }}@endif</small></td>
                    <td><span class="badge sev-{{ $c['severity'] }}">{{ ucfirst($c['severity']) }}</span></td>
                    <td>{{ $c['frameworks']['cis'] ?? '–' }}</td>
                    <td>{{ $c['frameworks']['cisv8'] ?? '–' }}</td>
                    <td>{{ $c['frameworks']['nist'] ?? '–' }}</td>
                    <td>{{ $c['frameworks']['e8'] ?? '–' }}</td>
                    <td>{{ ! empty($c['frameworks']['ins']) ? 'Yes' : '–' }}</td>
                    <td>
                        @if(! empty($c['fix']))<span class="badge s-pass">Fixable</span>
                        @elseif(str_starts_with($c['remediation'], 'Verify') || $c['permission'] === null)<span class="badge s-manual">Manual</span>
                        @else<span class="badge s-na">Guidance</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
