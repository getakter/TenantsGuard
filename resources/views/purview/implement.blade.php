@extends('layouts.app')
@section('title', 'Purview implementation: '.$project->tenant->name)
@use('App\Services\Purview\Catalog')
@use('App\Models\PurviewTask')

@section('content')
@include('purview._head')
@php
    $v = $project->verification;
    $verifiable = $project->tasks->where('stale', false)->whereNotNull('verify')->where('method', '!=', 'gate');
@endphp

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'terminal']) Deployment scripts</div>
        <p class="muted" style="margin-top:0">Policies are deployed with Security &amp; Compliance PowerShell generated from the approved design. Run each script yourself with an administrator account of {{ $project->tenant->name }} and MFA, so TenantsGuard never holds standing compliance rights.</p>
        <ul class="sec-list">
            <li>Preview with <code>-WhatIf</code> first. Scripts are safe to re-run: existing items are skipped or updated.</li>
            <li>Each script refuses to run against any other tenant and writes a transcript log next to itself.</li>
            <li>Sensitive information types that do not exist in the tenant are skipped with a warning instead of failing.</li>
        </ul>
        @error('script')<div class="alert alert-err">{{ $message }}</div>@enderror
        @foreach(['foundations', 'discover', 'pilot', 'production'] as $ph)
            @php
                $ok = $project->isApproved() && $project->unlocked($ph);
            @endphp
            <div class="cap-row" style="align-items:center">
                <span><strong>{{ Catalog::STAGES[$ph][0] }}</strong><br><small class="muted">{{ Catalog::STAGES[$ph][1] }}</small></span>
                @if($ok && auth()->user()->can('purview.manage'))
                    <a class="btn btn-sm btn-primary" href="{{ route('purview.script', [$project, $ph]) }}">@include('partials.icon', ['name' => 'download', 'size' => 14]) Download</a>
                @else
                    <span class="badge s-na">{{ $project->isApproved() ? 'Locked until the previous gate' : 'Needs an approved design' }}</span>
                @endif
            </div>
        @endforeach
        <p class="help" style="margin-top:10px">Requires PowerShell 7 and <code>Install-Module ExchangeOnlineManagement</code>. Optional: SharePoint Online Management Shell and Microsoft.Graph.Authentication for the Foundations script.</p>
    </div>

    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'check']) Verify against the tenant</div>
        <ol style="padding-left:18px;margin-top:0">
            <li>Run the read-only verification script. It exports labels, label policies, DLP and auto-labelling modes, retention policies and labels, audit and co-authoring settings.</li>
            <li>Upload the JSON file it creates. TenantsGuard checks every task against the design and marks matching tasks as done.</li>
        </ol>
        @can('purview.manage')
            <div class="actions" style="margin-bottom:12px">
                @if($project->design)<a class="btn btn-sm" href="{{ route('purview.script', [$project, 'verify']) }}">@include('partials.icon', ['name' => 'download', 'size' => 14]) Verification script</a>@endif
                <form method="post" action="{{ route('purview.reverify', $project) }}" class="inline">@csrf <button class="btn btn-sm">Re-check through Microsoft Graph</button></form>
            </div>
            <form method="post" action="{{ route('purview.verify', $project) }}" enctype="multipart/form-data" class="op-pick">
                @csrf
                <input type="file" name="export" accept=".json,application/json" required>
                <button class="btn btn-primary btn-sm">Upload export</button>
            </form>
            @error('export')<div class="error-text">{{ $message }}</div>@enderror
        @endcan
        <dl class="kv" style="margin-top:14px">
            <dt>Last export</dt><dd>{{ $project->verified_at ? $project->verified_at->diffForHumans().' (generated '.($v['generatedAt'] ?? '?').')' : 'none uploaded' }}</dd>
            @if($v)
                <dt>In the tenant</dt><dd>{{ count($v['labels']) }} sensitivity labels, {{ count($v['dlpPolicies']) }} DLP policies, {{ count($v['retentionPolicies']) }} retention policies, {{ count($v['retentionLabels']) }} retention labels · audit {{ $v['auditEnabled'] ? 'on' : 'OFF' }}</dd>
            @endif
            <dt>Read through Graph</dt><dd>{{ $existing['sensitivity'] !== null ? count($existing['sensitivity']).' sensitivity labels' : 'sensitivity labels not readable (InformationProtectionPolicy.Read.All)' }} · {{ $existing['retention'] !== null ? count($existing['retention']).' retention labels' : 'retention labels not readable (RecordsManagement.Read.All)' }}</dd>
        </dl>
    </div>
</div>

<div class="card">
    <div class="card-title">Verification results</div>
    <table>
        <thead><tr><th>Task</th><th>Phase</th><th>Status</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        @forelse($verifiable as $t)
            <tr>
                <td><a href="{{ route('purview.plan', $project) }}#task-{{ $t->id }}">{{ $t->title }}</a></td>
                <td>{{ Catalog::STAGES[$t->phase][0] }}</td>
                <td><span class="badge {{ PurviewTask::STATUS[$t->status][1] }}">{{ PurviewTask::STATUS[$t->status][0] }}</span></td>
                <td>@if($t->verify_status)<span class="badge {{ PurviewTask::VERIFY[$t->verify_status][1] }}">{{ PurviewTask::VERIFY[$t->verify_status][0] }}</span>@else<span class="muted">not checked</span>@endif</td>
                <td><small class="muted">{{ $t->verify_detail }}</small></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">Nothing to verify yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
