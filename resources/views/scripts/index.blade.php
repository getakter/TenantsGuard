@extends('layouts.app')
@section('title', 'PowerShell scripts')
@use('App\Models\ScriptVersion')
@use('App\Models\ScriptRun')

@section('content')
@php
    $me = auth()->user();
@endphp
<div class="page-head">
    <div>
        <h1>PowerShell scripts</h1>
        <p>Run approved scripts against one client or a chosen group of clients. Each client runs in its own isolated, time-limited session, and every run is audited.</p>
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('scripts.runs') }}">Runs and results</a>
        @can('scripts.manage')<a class="btn btn-primary" href="{{ route('scripts.create') }}">@include('partials.icon', ['name' => 'plus', 'size' => 16]) New script</a>@endcan
    </div>
</div>

@unless($enabled)
    <div class="alert alert-warn">PowerShell execution is <strong>switched off</strong>. Scripts can be written and reviewed, but nothing runs.@if($me->isGlobalAdmin()) Turn it on in the execution policy below.@else A Global Administrator can turn it on.@endif</div>
@endunless
@unless($pwsh)
    <div class="alert alert-err">PowerShell 7 (pwsh) was not found on this server. Install it from Microsoft, or set TG_PWSH in .env to its full path.</div>
@endunless

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'lock']) How scripts are protected</div>
        <ul class="sec-list">
            <li><b>Approved code only.</b> Every version is reviewed and approved by a second person. Approval is tied to the code's SHA-256 hash and signed, so a changed script cannot run.</li>
            <li><b>Constrained sandbox.</b> Scripts run in PowerShell Constrained Language Mode, in a separate process with no profile, a clean environment, a private temp folder, a time limit and an output limit.</li>
            <li><b>No credentials in scripts.</b> TenantsGuard gives each run a short-lived token over a private channel. Scripts call Microsoft Graph through <code>Invoke-TGGraph</code> and never see the token. Read-only scripts cannot write.</li>
            <li><b>Least privilege.</b> Running read-only and change scripts are separate permissions. Change scripts need an authenticator code every time. People only see and target the clients assigned to them.</li>
            <li><b>Full audit.</b> Who ran which version, why, against which clients, with which parameters, and what came back.</li>
        </ul>
    </div>
    <div class="card">
        <div class="card-title">@include('partials.icon', ['name' => 'cog']) Execution policy</div>
        <dl class="kv">
            <dt>Execution</dt><dd><span class="badge {{ $enabled ? 's-pass' : 's-fail' }}">{{ $enabled ? 'On' : 'Off' }}</span></dd>
            <dt>Approval</dt><dd>{{ $selfApproval ? 'Authors may approve their own scripts (single-person team)' : 'Four-eyes: someone other than the author must approve' }}</dd>
            <dt>PowerShell</dt><dd>{{ $pwsh ?: 'not found' }}</dd>
            <dt>Limits</dt><dd>{{ config('tenantsguard.scripts.max_tenants') }} clients per run · {{ config('tenantsguard.scripts.output_max_kb') }} KB output per client · up to {{ config('tenantsguard.scripts.timeout_max') / 60 }} min per client</dd>
        </dl>
        @if($me->isGlobalAdmin())
            <form method="post" action="{{ route('scripts.policy') }}" style="margin-top:14px" data-confirm="Change the PowerShell execution policy? This is recorded in the audit log.">
                @csrf
                <label style="font-weight:400;display:flex;gap:10px;align-items:center"><span class="switch"><input type="checkbox" name="enabled" value="1" @checked($enabled)><span class="slider"></span></span> Allow approved scripts to run</label>
                <label style="font-weight:400;display:flex;gap:10px;align-items:center;margin-top:8px"><span class="switch"><input type="checkbox" name="self_approval" value="1" @checked($selfApproval)><span class="slider"></span></span> Allow authors to approve their own scripts <small class="muted">(only if you work alone)</small></label>
                <button class="btn btn-sm" style="margin-top:10px">Save policy</button>
            </form>
        @endif
    </div>
</div>

@if($pending->isNotEmpty())
    <div class="card" style="margin-bottom:20px;border-color:var(--warn)">
        <div class="card-title">Waiting for approval ({{ $pending->count() }})</div>
        @foreach($pending as $v)
            <div class="tpl-card">
                <div><strong>{{ $v->script->name }}</strong> <small class="muted">v{{ $v->version }}</small> <span class="badge {{ ScriptVersion::RISK[$v->risk][1] }}">{{ ScriptVersion::RISK[$v->risk][0] }}</span>
                    <div class="leaver-facts"><span>Submitted {{ $v->submitted_at?->diffForHumans() }} by {{ $v->submitter->name ?? 'TenantsGuard (sample)' }}</span>@if($v->change_note)<span>{{ $v->change_note }}</span>@endif</div></div>
                <a class="btn btn-sm {{ $me->can('scripts.approve') ? 'btn-primary' : '' }}" href="{{ route('scripts.show', [$v->script, 'v' => $v->id]) }}">{{ $me->can('scripts.approve') ? 'Review' : 'View' }}</a>
            </div>
        @endforeach
    </div>
@endif

<div class="card" style="margin-bottom:20px">
    <div class="test-toolbar"><input type="text" placeholder="Search scripts…" oninput="const q=this.value.toLowerCase();document.querySelectorAll('.script-row').forEach(r=>r.hidden=!r.dataset.q.includes(q))"></div>
    <table>
        <thead><tr><th>Script</th><th>Category</th><th>Type</th><th>Approved version</th><th></th></tr></thead>
        <tbody>
        @forelse($scripts as $s)
            @php
                $a = $s->approved;
                $canRun = $a && $me->can($a->risk === 'change' ? 'scripts.run_change' : 'scripts.run');
            @endphp
            <tr class="script-row" data-q="{{ strtolower($s->name.' '.$s->category.' '.$s->description) }}">
                <td><a href="{{ route('scripts.show', $s) }}"><strong>{{ $s->name }}</strong></a><br><small class="muted">{{ $s->description }}</small></td>
                <td>{{ $s->category }}</td>
                <td>@if($a)<span class="badge {{ ScriptVersion::RISK[$a->risk][1] }}">{{ ScriptVersion::RISK[$a->risk][0] }}</span>@if($a->use_exchange) <span class="chip">Exchange</span>@endif @else <span class="muted">–</span>@endif</td>
                <td>@if($a)v{{ $a->version }} <small class="muted">by {{ $a->reviewer->name ?? '?' }}</small>@else<span class="badge s-na">Not approved yet</span>@endif</td>
                <td style="text-align:right">
                    @if($canRun && $enabled)<a class="btn btn-sm btn-primary" href="{{ route('scripts.run', $s) }}">@include('partials.icon', ['name' => 'terminal', 'size' => 15]) Run</a>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No scripts yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <div class="card-title">Recent runs</div>
    @include('scripts._runs', ['runs' => $recent])
</div>
@endsection
