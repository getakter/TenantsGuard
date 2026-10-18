@extends('layouts.app')
@section('title', 'Onboard: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])
@php
    $selectedTpl = request()->has('template') ? $templates->firstWhere('id', (int) request('template')) : $templates->firstWhere('is_default', true);
    $countries = ['AU' => 'Australia', 'NZ' => 'New Zealand', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'US' => 'United States', 'CA' => 'Canada', 'BD' => 'Bangladesh', 'IN' => 'India', 'SG' => 'Singapore', 'AE' => 'United Arab Emirates', 'DE' => 'Germany', 'FR' => 'France', 'NL' => 'Netherlands', 'ZA' => 'South Africa'];
@endphp

<div class="page-head">
    <div>
        <h1>Onboard user</h1>
        <p>Creates the account in {{ $tenant->name }} with everything the person needs on day one. Choose only the items that apply.</p>
    </div>
    <a class="btn" href="{{ route('operations.tenant', $tenant) }}">Cancel</a>
</div>

<form method="post" action="{{ route('operations.onboard.run', $tenant) }}" id="onb" data-confirm="Create this account in {{ $tenant->name }}?">
    @csrf
    <div class="card">
        <h2>1 · Start from</h2>
        <div class="grid grid-2">
            <div class="field">
                <label>Role template (optional)</label>
                <select id="tpl">
                    <option value="">Blank</option>
                    @foreach($templates as $t)<option value="{{ $t->id }}" data-tpl='@json($t->data)' @selected($selectedTpl?->id === $t->id)>{{ $t->name }}{{ $t->is_default ? ' (default)' : '' }}</option>@endforeach
                </select>
                <div class="help">Pre-fills username format, job, company, address, licences, groups and sign-in method. <a href="{{ route('templates.index') }}">Manage templates</a></div>
            </div>
            <div class="field">
                <label>Copy access from a colleague (optional)</label>
                <select name="copy_from">
                    <option value="">Nobody</option>
                    @foreach($users as $u)<option value="{{ $u['id'] }}" @selected(old('copy_from') === $u['id'])>{{ $u['displayName'] }} · {{ $u['jobTitle'] ?? $u['userPrincipalName'] }}</option>@endforeach
                </select>
                <div class="help">Their licences and group memberships are added on top of what you tick below. Typical for "same job as…".</div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>2 · Identity</h2>
        <div class="grid grid-3">
            <div class="field"><label>First name</label><input type="text" name="first_name" id="fn" value="{{ old('first_name') }}" required autocomplete="off"></div>
            <div class="field"><label>Last name</label><input type="text" name="last_name" id="ln" value="{{ old('last_name') }}" required autocomplete="off"></div>
            <div class="field"><label>Display name</label><input type="text" name="display_name" id="dn" value="{{ old('display_name') }}" required></div>
        </div>
        <div class="field">
            <label>Username (sign-in and email)</label>
            <div style="display:flex;gap:6px;align-items:center;max-width:620px">
                <input type="text" name="username" id="un" value="{{ old('username') }}" required pattern="[A-Za-z0-9._\-]+" style="flex:1">
                <span>@</span>
                <select name="domain" id="dom" style="flex:1">@foreach($domains as $d)<option value="{{ $d }}" @selected(old('domain', $selectedTpl?->data['domain'] ?? null) === $d)>{{ $d }}</option>@endforeach</select>
            </div>
            <div class="help">Suggested from the template's username format (<span id="fmtlabel">firstname.lastname</span>). TenantsGuard checks it is free before creating.</div>
            @error('username')<div class="error-text">{{ $message }}</div>@enderror
        </div>
        <div class="grid grid-3">
            <div class="field"><label>Job title</label><input type="text" name="job_title" data-k="job_title" value="{{ old('job_title') }}"></div>
            <div class="field"><label>Department</label><input type="text" name="department" data-k="department" value="{{ old('department') }}"></div>
            <div class="field"><label>Office / location</label><input type="text" name="office" data-k="office" value="{{ old('office') }}"></div>
            <div class="field"><label>Mobile (optional)</label><input type="text" name="mobile" value="{{ old('mobile') }}" placeholder="+61 …"></div>
            <div class="field"><label>Business phone</label><input type="text" name="business_phone" data-k="business_phone" value="{{ old('business_phone') }}"></div>
            <div class="field"><label>Company</label><input type="text" name="company" data-k="company" value="{{ old('company') }}"></div>
            <div class="field">
                <label>Usage location</label>
                <select name="usage_location" data-k="usage_location">
                    @foreach($countries + [$country => $country] as $code => $name)<option value="{{ $code }}" @selected(old('usage_location', $country) === $code)>{{ $name }} ({{ $code }})</option>@endforeach
                </select>
                <div class="help">Required by Microsoft before licences can be assigned.</div>
            </div>
            <div class="field">
                <label>Manager</label>
                <select name="manager_id" data-k="manager_id">
                    <option value="">No manager</option>
                    @foreach($users as $u)<option value="{{ $u['id'] }}" @selected(old('manager_id') === $u['id'])>{{ $u['displayName'] }}</option>@endforeach
                </select>
            </div>
        </div>
        <details @if(old('street') || old('city')) open @endif>
            <summary class="muted" style="cursor:pointer">Address (optional)</summary>
            <div class="grid grid-3" style="margin-top:8px">
                <div class="field"><label>Street address</label><input type="text" name="street" data-k="street" value="{{ old('street') }}"></div>
                <div class="field"><label>City</label><input type="text" name="city" data-k="city" value="{{ old('city') }}"></div>
                <div class="field"><label>State / region</label><input type="text" name="state" data-k="state" value="{{ old('state') }}"></div>
                <div class="field"><label>Postal code</label><input type="text" name="postal_code" data-k="postal_code" value="{{ old('postal_code') }}"></div>
                <div class="field"><label>Country</label><input type="text" name="country_name" data-k="country_name" value="{{ old('country_name') }}"></div>
            </div>
        </details>
    </div>

    <div class="card">
        <h2>3 · First sign-in</h2>
        <label class="option" style="display:block;font-weight:400;margin-bottom:8px;cursor:pointer">
            <input type="radio" name="password_mode" value="tap" data-k="password_mode" @checked(old('password_mode', 'tap') === 'tap')>
            <strong>Temporary Access Pass</strong> <span class="badge s-pass">Recommended</span>
            <div class="help">A time-limited pass to set up Microsoft Authenticator or a passkey on day one. No password to send or forget. Requires the Temporary Access Pass method to be enabled in Entra.
                Valid for <select name="tap_hours" data-k="tap_hours" style="width:auto;display:inline-block">@foreach([1, 2, 4, 8] as $h)<option value="{{ $h }}" @selected((int) old('tap_hours', 8) === $h)>{{ $h }} hour(s)</option>@endforeach</select></div>
        </label>
        <label class="option" style="display:block;font-weight:400;cursor:pointer">
            <input type="radio" name="password_mode" value="password" data-k="password_mode" @checked(old('password_mode') === 'password')>
            <strong>Temporary password</strong>
            <div class="help">A strong random password that must be changed at first sign-in.</div>
        </label>
    </div>

    <div class="card">
        <h2>4 · Licences</h2>
        @forelse($licences as $l)
            <label class="lic-row {{ $l['available'] < 1 ? 'is-full' : '' }}">
                <input type="checkbox" name="licences[]" value="{{ $l['skuId'] }}" data-k="licences" @disabled($l['available'] < 1) @checked(in_array($l['skuId'], old('licences', []), true))>
                <span><strong>{{ $l['name'] }}</strong> <small class="muted">{{ $l['part'] }}</small></span>
                <span class="lic-seats {{ $l['available'] < 1 ? 'full' : '' }}">{{ $l['available'] }} of {{ $l['total'] }} free</span>
            </label>
        @empty
            <p class="muted">No licences found.</p>
        @endforelse
        <p class="help">Licences with no free seats are disabled. Buy more seats in the Microsoft 365 admin center or your distributor portal.</p>
    </div>

    <div class="card">
        <h2>5 · Groups and Teams</h2>
        <div class="filters"><input type="text" placeholder="Filter groups…" oninput="const q=this.value.toLowerCase();document.querySelectorAll('.grp-row').forEach(r=>r.hidden=!r.dataset.q.includes(q))"></div>
        <div class="checks-list">
            @foreach($groups as $g)
                <label class="grp-row" data-q="{{ strtolower($g['name'].' '.$g['kind']) }}" @unless($g['assignable']) title="{{ $g['why'] }}" style="opacity:.55" @endunless>
                    <input type="checkbox" name="groups[]" value="{{ $g['id'] }}" data-k="groups" @disabled(! $g['assignable']) @checked(in_array($g['id'], old('groups', []), true))>
                    <span>{{ $g['name'] }} <small class="chip">{{ $g['kind'] }}</small>@unless($g['assignable'])<br><small class="muted">{{ $g['why'] }}</small>@endunless</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="card">
        <h2>6 · Start date, ticket and hand-over</h2>
        <div class="grid grid-3">
            <div class="field"><label>Start date</label><input type="date" name="start_date" value="{{ old('start_date') }}" min="{{ now()->toDateString() }}">
                <div class="help">Leave empty to activate now. A future date creates the account disabled and enables it at 07:00 on the day.</div></div>
            <div class="field"><label>Ticket / reference</label><input type="text" name="ticket" value="{{ old('ticket') }}" placeholder="PSA ticket #"></div>
            <div class="field"><label>Notes</label><input type="text" name="notes" value="{{ old('notes') }}"></div>
        </div>
        <label>Hand-over checklist (tracked until done)</label>
        @foreach($checklist as $key => [$label, $hint])
            <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin-bottom:6px">
                <input type="checkbox" name="checklist[]" value="{{ $key }}" data-k="checklist" @checked(in_array($key, old('checklist', ['device', 'mfa', 'credentials', 'training']), true))>
                <span>{{ $label }}<br><small class="muted">{{ $hint }}</small></span>
            </label>
        @endforeach
    </div>

    <div class="sticky-actions">
        <input type="text" name="save_template" placeholder="Save as role template (optional)…" style="max-width:280px">
        <span style="flex:1"></span>
        <button class="btn btn-primary">Create account</button>
    </div>
</form>

<script>
(function () {
    const fn = document.getElementById('fn'), ln = document.getElementById('ln'), dn = document.getElementById('dn'), un = document.getElementById('un');
    let dnTouched = !!dn.value, unTouched = !!un.value;
    dn.addEventListener('input', () => (dnTouched = true));
    un.addEventListener('input', () => (unTouched = true));
    const slug = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    const formats = @json(\App\Models\LifecycleTemplate::USERNAME_FORMATS);
    let format = 'first.last', suffix = '';
    function username(f, l) {
        switch (format) {
            case 'firstlast': return f + l;
            case 'f.last': return f.slice(0, 1) + '.' + l;
            case 'flast': return f.slice(0, 1) + l;
            case 'first.l': return f + '.' + l.slice(0, 1);
            case 'first': return f;
            default: return [f, l].filter(Boolean).join('.');
        }
    }
    function sync() {
        if (!dnTouched) dn.value = [fn.value.trim(), ln.value.trim(), suffix].filter(Boolean).join(' ');
        if (!unTouched) un.value = username(slug(fn.value), slug(ln.value));
    }
    fn.addEventListener('input', sync); ln.addEventListener('input', sync);

    // Role template prefill.
    const tpl = document.getElementById('tpl');
    function applyTemplate() {
        const opt = tpl.selectedOptions[0];
        if (!opt || !opt.dataset.tpl) return;
        const d = JSON.parse(opt.dataset.tpl);
        document.querySelectorAll('#onb [data-k]').forEach((el) => {
            const v = d[el.dataset.k];
            if (v === undefined) return;
            if (el.type === 'checkbox') el.checked = Array.isArray(v) && v.includes(el.value) && !el.disabled;
            else if (el.type === 'radio') el.checked = el.value === v;
            else el.value = v ?? '';
        });
        if (d.domain) document.getElementById('dom').value = d.domain;
        format = d.username_format || 'first.last';
        suffix = d.display_suffix || '';
        document.getElementById('fmtlabel').textContent = formats[format] || format;
        sync();
    }
    tpl.addEventListener('change', applyTemplate);
    if (tpl.value) applyTemplate();
})();
</script>
@endsection
