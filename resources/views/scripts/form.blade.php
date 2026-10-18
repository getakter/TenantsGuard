@extends('layouts.app')
@section('title', $script->exists ? 'Edit '.$script->name : 'New script')
@use('App\Models\Script')

@section('content')
@php
    $v = $version;
    $params = old('parameters', $v?->parameters ?? []);
    $body = old('body', $v?->body ?? "param(\n    # [Parameter(Mandatory = \$true)][string]\$UserPrincipalName\n)\n\n# Return objects. TenantsGuard shows them as a table and exports them.\n\$users = Invoke-TGGraph -Uri '/users?\$select=displayName,userPrincipalName,accountEnabled' -All\n\$users | Where-Object { -not \$_.accountEnabled } | Select-Object displayName, userPrincipalName\n");
@endphp
<div class="page-head">
    <div>
        <h1>{{ $script->exists ? 'Edit '.$script->name : 'New script' }}</h1>
        <p>Saving creates a new draft version. Nothing can run until it is submitted and approved.@if($script->exists) The current approved version keeps running until then.@endif</p>
    </div>
    <a class="btn" href="{{ $script->exists ? route('scripts.show', $script) : route('scripts.index') }}">Cancel</a>
</div>

<form method="post" action="{{ $script->exists ? route('scripts.update', $script) : route('scripts.store') }}">
    @csrf
    @if($script->exists) @method('put') @endif
    <div class="dash-grid-2" style="grid-template-columns:1.6fr 1fr">
        <div>
            <div class="card">
                <div class="grid grid-2">
                    <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $script->name) }}" required maxlength="120"></div>
                    <div class="field"><label>Category</label><select name="category">@foreach(Script::CATEGORIES as $c)<option @selected(old('category', $script->category) === $c)>{{ $c }}</option>@endforeach</select></div>
                </div>
                <div class="field"><label>Description</label><input type="text" name="description" value="{{ old('description', $script->description) }}" maxlength="1000" placeholder="What it does and when to use it"></div>

                <label>What can it do?</label>
                <label class="when-opt"><input type="radio" name="risk" value="read" @checked(old('risk', $v?->risk ?? 'read') === 'read')>
                    <span><strong>Read-only</strong><br><small class="muted">Reports and checks. Write requests are blocked at run time. Technicians can run it.</small></span></label>
                <label class="when-opt"><input type="radio" name="risk" value="change" @checked(old('risk', $v?->risk) === 'change')>
                    <span><strong>Makes changes</strong><br><small class="muted">Changes users, mailboxes or settings. Needs the "run change scripts" permission and an authenticator code for every run.</small></span></label>

                <div class="grid grid-3" style="margin-top:12px">
                    <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="use_exchange" value="1" @checked(old('use_exchange', $v?->use_exchange))>
                        <span>Connect Exchange Online<br><small class="muted">Read-only scripts get only Get- cmdlets. Needs Exchange.ManageAsApp.</small></span></label>
                    <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="use_graph_module" value="1" @checked(old('use_graph_module', $v?->use_graph_module))>
                        <span>Connect Microsoft Graph PowerShell<br><small class="muted">Mg cmdlets. Change scripts only.</small></span></label>
                    <div class="field"><label>Time limit per client</label><select name="timeout">@foreach([60, 120, 300, 600, 900, 1800] as $t)<option value="{{ $t }}" @selected((int) old('timeout', $v?->timeout ?? 300) === $t)>{{ $t < 120 ? $t.' seconds' : ($t / 60).' minutes' }}</option>@endforeach</select></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">Parameters <small class="muted" style="font-weight:400">Asked for when the script runs. Values are passed as data, never pasted into the code.</small></div>
                <table id="ptable">
                    <thead><tr><th>Name</th><th>Label</th><th>Type</th><th>Required</th><th>Default / choices</th><th></th></tr></thead>
                    <tbody>
                    @foreach($params as $i => $p)
                        <tr>
                            <td><input type="text" name="parameters[{{ $i }}][name]" value="{{ $p['name'] }}" placeholder="UserPrincipalName" pattern="[A-Za-z][A-Za-z0-9_]*"></td>
                            <td><input type="text" name="parameters[{{ $i }}][label]" value="{{ $p['label'] ?? '' }}"></td>
                            <td><select name="parameters[{{ $i }}][type]">@foreach($types as $k => $l)<option value="{{ $k }}" @selected(($p['type'] ?? 'string') === $k)>{{ $l }}</option>@endforeach</select></td>
                            <td><input type="checkbox" name="parameters[{{ $i }}][required]" value="1" @checked(! empty($p['required']))></td>
                            <td><input type="text" name="parameters[{{ $i }}][default]" value="{{ $p['default'] ?? '' }}" placeholder="default">
                                <input type="text" name="parameters[{{ $i }}][options]" value="{{ is_array($p['options'] ?? null) ? implode(', ', $p['options']) : ($p['options'] ?? '') }}" placeholder="choices: a, b, c" style="margin-top:4px"></td>
                            <td><button type="button" class="btn btn-sm" onclick="this.closest('tr').remove()">Remove</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm" style="margin-top:8px" onclick="addParam()">@include('partials.icon', ['name' => 'plus', 'size' => 14]) Add parameter</button>
            </div>

            <div class="card">
                <div class="card-title">Script</div>
                <textarea name="body" id="body" rows="22" spellcheck="false" class="code-area" required>{{ $body }}</textarea>
                <div class="field" style="margin-top:12px"><label>What changed in this version?</label><input type="text" name="change_note" value="{{ old('change_note') }}" maxlength="500" placeholder="Shown to the approver"></div>
                <button class="btn btn-primary">Save draft</button>
            </div>
        </div>

        <div>
            <div class="card" style="position:sticky;top:80px">
                <div class="card-title">@include('partials.icon', ['name' => 'book']) Writing scripts for TenantsGuard</div>
                <p class="muted" style="margin-top:0">Scripts run once per client in PowerShell 7, Constrained Language Mode, already connected to that client.</p>
                <strong>Call Microsoft Graph</strong>
                <pre class="evidence">Invoke-TGGraph -Uri '/users' -All
Invoke-TGGraph -Uri '/groups?$filter=...' -Beta
Invoke-TGGraph -Method PATCH -Uri "/users/$id" `
  -Body @{ accountEnabled = $false }</pre>
                <p class="help">Relative paths only. Write methods are blocked in read-only scripts. Use single quotes around $select and $filter.</p>
                <strong>Return results</strong>
                <p class="help" style="margin-top:2px">Output objects (Select-Object, or [ordered]@{ Name = ... } because [pscustomobject] is not available in Constrained Language Mode). TenantsGuard shows them per client and exports them as CSV or JSON. Write-Host and Write-Information lines appear in the log.</p>
                <strong>Current client</strong>
                <pre class="evidence">(Get-TGTenant).Name   # .TenantId .Domain .Demo</pre>
                <strong>Not allowed</strong>
                <p class="help" style="margin-top:2px">Invoke-Expression, Add-Type, Start-Process, direct web requests, remote sessions, jobs, file system access, environment variables, Import-Module and Connect-* (the runner connects for you). These are blocked before approval and by the sandbox at run time.</p>
                <strong>Demo clients</strong>
                <p class="help" style="margin-top:2px">Return sample data for common Graph collections. Changes are simulated, not applied.</p>
            </div>
        </div>
    </div>
</form>

<template id="prow">
    <tr>
        <td><input type="text" name="parameters[__][name]" placeholder="UserPrincipalName" pattern="[A-Za-z][A-Za-z0-9_]*"></td>
        <td><input type="text" name="parameters[__][label]"></td>
        <td><select name="parameters[__][type]">@foreach($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
        <td><input type="checkbox" name="parameters[__][required]" value="1"></td>
        <td><input type="text" name="parameters[__][default]" placeholder="default"><input type="text" name="parameters[__][options]" placeholder="choices: a, b, c" style="margin-top:4px"></td>
        <td><button type="button" class="btn btn-sm" onclick="this.closest('tr').remove()">Remove</button></td>
    </tr>
</template>
@endsection

@push('scripts')
<script>
let pIndex = {{ count($params) + 1 }};
function addParam() {
  const html = document.getElementById('prow').innerHTML.replaceAll('__', pIndex++);
  document.querySelector('#ptable tbody').insertAdjacentHTML('beforeend', html);
}
// Tab inserts four spaces in the code editor.
document.getElementById('body').addEventListener('keydown', (e) => {
  if (e.key !== 'Tab') return;
  e.preventDefault();
  const t = e.target, s = t.selectionStart;
  t.setRangeText('    ', s, t.selectionEnd, 'end');
});
</script>
@endpush
