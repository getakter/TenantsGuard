@extends('layouts.app')
@section('title', 'Bulk onboard: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])
@php
    $default = $templates->firstWhere('is_default', true);
    $tplId = (int) old('template_id', request('template', $default?->id));
    $tpl = $templates->firstWhere('id', $tplId)?->data ?? [];
@endphp

<div class="page-head">
    <div>
        <h1>Bulk onboard users</h1>
        <p>Create many accounts in {{ $tenant->name }} at once from a CSV list. You see a checked preview before anything is created.</p>
    </div>
    <a class="btn" href="{{ route('operations.tenant', $tenant) }}">Cancel</a>
</div>

<form method="post" action="{{ route('operations.bulk.preview', $tenant) }}" enctype="multipart/form-data" id="bulk">
    @csrf
    <div class="card">
        <h2>1 · People</h2>
        <p class="muted" style="margin-top:0">Paste CSV with a header row, or upload a .csv file. Required columns: <code>FirstName</code>, <code>LastName</code>.
            Optional: <code>DisplayName</code>, <code>Username</code>, <code>JobTitle</code>, <code>Department</code>, <code>Office</code>, <code>Mobile</code>, <code>BusinessPhone</code>, <code>Manager</code> (email or name), <code>UsageLocation</code>, <code>StartDate</code> (YYYY-MM-DD). Up to 50 rows.</p>
        <textarea name="csv" rows="8" style="font-family:ui-monospace,monospace" placeholder="{{ $example }}">{{ old('csv') }}</textarea>
        @error('csv')<div class="error-text">{{ $message }}</div>@enderror
        <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
            <input type="file" name="csv_file" accept=".csv,text/csv" style="width:auto">
            <a class="btn btn-sm" href="data:text/csv;charset=utf-8,{{ rawurlencode($example) }}" download="tenantsguard-bulk-onboard-example.csv">Download example CSV</a>
            <button type="button" class="btn btn-sm" onclick="document.querySelector('[name=csv]').value = {{ json_encode($example) }}">Paste example</button>
        </div>
    </div>

    <div class="card">
        <h2>2 · Shared settings</h2>
        <div class="grid grid-3">
            <div class="field">
                <label>User template</label>
                <select name="template_id" id="tpl">
                    <option value="">None</option>
                    @foreach($templates as $t)<option value="{{ $t->id }}" data-tpl='@json($t->data)' @selected($tplId === $t->id)>{{ $t->name }}{{ $t->is_default ? ' (default)' : '' }}</option>@endforeach
                </select>
                <div class="help">Fills job details, company, address, licences and groups. <a href="{{ route('templates.index') }}">Manage templates</a></div>
            </div>
            <div class="field">
                <label>Domain</label>
                <select name="domain">@foreach($domains as $d)<option value="{{ $d }}" @selected(old('domain', $tpl['domain'] ?? null) === $d)>{{ $d }}</option>@endforeach</select>
            </div>
            <div class="field">
                <label>Username format</label>
                <select name="username_format">@foreach($formats as $k => $l)<option value="{{ $k }}" @selected(old('username_format', $tpl['username_format'] ?? 'first.last') === $k)>{{ $l }}</option>@endforeach</select>
                <div class="help">Used when the CSV has no Username column.</div>
            </div>
            <div class="field">
                <label>Usage location</label>
                <input type="text" name="usage_location" value="{{ old('usage_location', $tpl['usage_location'] ?? $country) }}" maxlength="2" style="text-transform:uppercase" required>
                <div class="help">Two-letter country code. A CSV column overrides it.</div>
            </div>
            <div class="field">
                <label>First sign-in</label>
                <select name="password_mode">
                    <option value="tap" @selected(old('password_mode', $tpl['password_mode'] ?? 'tap') === 'tap')>Temporary Access Pass (recommended)</option>
                    <option value="password" @selected(old('password_mode', $tpl['password_mode'] ?? 'tap') === 'password')>Temporary password</option>
                </select>
            </div>
            <div class="field">
                <label>Start date</label>
                <input type="date" name="start_date" value="{{ old('start_date') }}" min="{{ now()->toDateString() }}">
                <div class="help">Empty means active now. A CSV column overrides it per person.</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>3 · Licences and groups for everyone</h2>
        <div class="grid grid-2">
            <div>
                <div class="label-sm">Licences</div>
                @foreach($licences as $l)
                    <label class="lic-row {{ $l['available'] < 1 ? 'is-full' : '' }}">
                        <input type="checkbox" name="licences[]" value="{{ $l['skuId'] }}" @disabled($l['available'] < 1) @checked(in_array($l['skuId'], old('licences', $tpl['licences'] ?? []), true))>
                        <span><strong>{{ $l['name'] }}</strong></span>
                        <span class="lic-seats {{ $l['available'] < 1 ? 'full' : '' }}">{{ $l['available'] }} free</span>
                    </label>
                @endforeach
                <p class="help">The preview warns when there are not enough free seats for everyone.</p>
            </div>
            <div>
                <div class="label-sm">Groups and Teams</div>
                <div class="checks-list" style="max-height:320px;overflow:auto">
                    @foreach($groups as $g)
                        <label class="grp-row" @unless($g['assignable']) title="{{ $g['why'] }}" style="opacity:.55" @endunless>
                            <input type="checkbox" name="groups[]" value="{{ $g['id'] }}" @disabled(! $g['assignable']) @checked(in_array($g['id'], old('groups', $tpl['groups'] ?? []), true))>
                            <span>{{ $g['name'] }} <small class="chip">{{ $g['kind'] }}</small></span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="grid grid-2" style="margin-top:12px">
            <div class="field"><label>Ticket / reference</label><input type="text" name="ticket" value="{{ old('ticket') }}" placeholder="PSA ticket #"></div>
            <div>
                <div class="label-sm">Hand-over checklist per person</div>
                @foreach($checklist as $key => [$label])
                    <label style="font-weight:400;display:flex;gap:8px;margin-bottom:4px"><input type="checkbox" name="checklist[]" value="{{ $key }}" @checked(in_array($key, old('checklist', $tpl['checklist'] ?? ['device', 'mfa', 'credentials', 'training']), true))> {{ $label }}</label>
                @endforeach
            </div>
        </div>
    </div>

    <div class="sticky-actions">
        <span style="flex:1"></span>
        <button class="btn btn-primary">Preview</button>
    </div>
</form>

<script>
// Apply a template's shared settings without reloading (keeps the pasted CSV).
document.getElementById('tpl').addEventListener('change', function () {
    const opt = this.selectedOptions[0];
    if (!opt || !opt.dataset.tpl) return;
    const d = JSON.parse(opt.dataset.tpl), f = document.getElementById('bulk');
    ['domain', 'username_format', 'usage_location', 'password_mode'].forEach((k) => { if (d[k] && f.elements[k]) f.elements[k].value = d[k]; });
    ['licences', 'groups', 'checklist'].forEach((k) => {
        f.querySelectorAll('input[name="' + k + '[]"]').forEach((el) => { el.checked = !el.disabled && (d[k] || []).includes(el.value); });
    });
});
</script>
@endsection
