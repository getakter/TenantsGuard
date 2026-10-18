@extends('layouts.app')
@section('title', ($template->exists ? 'Edit' : 'New').' user template')

@section('content')
@php
    $d = $template->data ?? [];
    $v = fn (string $k, $default = null) => old($k, $d[$k] ?? $default);
@endphp
<div class="page-head">
    <div>
        <h1>{{ $template->exists ? 'Edit user template' : 'New user template' }}</h1>
        <p>{{ $tenant->name }}. Everything here is optional except the name and username format. Values pre-fill the onboarding forms and can still be changed per person.</p>
    </div>
    <a class="btn" href="{{ route('templates.index') }}">Cancel</a>
</div>

@unless($template->exists)
    <div class="card">
        <h2>Start from an existing user (optional)</h2>
        <form method="get" action="{{ route('templates.create', $tenant) }}" class="op-pick" style="max-width:620px">
            <select name="from_user" required>
                <option value="">Choose a person in the same role…</option>
                @foreach($users as $u)<option value="{{ $u['id'] }}" @selected(request('from_user') === $u['id'])>{{ $u['displayName'] }}{{ ! empty($u['jobTitle']) ? ' · '.$u['jobTitle'] : '' }}</option>@endforeach
            </select>
            <button class="btn">Copy their setup</button>
        </form>
        <p class="help">Copies job details, company, address, manager, licences and group memberships into the form below.</p>
    </div>
@endunless

<form method="post" action="{{ $template->exists ? route('templates.update', [$tenant, $template]) : route('templates.store', $tenant) }}">
    @csrf
    @if($template->exists) @method('put') @endif

    <div class="card">
        <h2>Template</h2>
        <div class="grid grid-2">
            <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $template->name) }}" required placeholder="Dental nurse">
                @error('name')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="field"><label>&nbsp;</label>
                <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $template->is_default))> Default template for {{ $tenant->name }}</label></div>
        </div>
    </div>

    <div class="card">
        <h2>Naming</h2>
        <div class="grid grid-3">
            <div class="field"><label>Username format</label>
                <select name="username_format">@foreach($formats as $k => $l)<option value="{{ $k }}" @selected($v('username_format', 'first.last') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label>Domain</label>
                <select name="domain"><option value="">Tenant default</option>@foreach($domains as $dm)<option value="{{ $dm }}" @selected($v('domain') === $dm)>{{ $dm }}</option>@endforeach</select></div>
            <div class="field"><label>Display name suffix</label><input type="text" name="display_suffix" value="{{ $v('display_suffix') }}" placeholder="(Contractor)">
                <div class="help">Added after "First Last".</div></div>
        </div>
    </div>

    <div class="card">
        <h2>Job and company</h2>
        <div class="grid grid-3">
            <div class="field"><label>Job title</label><input type="text" name="job_title" value="{{ $v('job_title') }}"></div>
            <div class="field"><label>Department</label><input type="text" name="department" value="{{ $v('department') }}"></div>
            <div class="field"><label>Office</label><input type="text" name="office" value="{{ $v('office') }}"></div>
            <div class="field"><label>Company</label><input type="text" name="company" value="{{ $v('company', $tenant->name) }}"></div>
            <div class="field"><label>Business phone</label><input type="text" name="business_phone" value="{{ $v('business_phone') }}"></div>
            <div class="field"><label>Manager</label>
                <select name="manager_id"><option value="">None</option>@foreach($users as $u)<option value="{{ $u['id'] }}" @selected($v('manager_id') === $u['id'])>{{ $u['displayName'] }}</option>@endforeach</select></div>
            <div class="field"><label>Street address</label><input type="text" name="street" value="{{ $v('street') }}"></div>
            <div class="field"><label>City</label><input type="text" name="city" value="{{ $v('city') }}"></div>
            <div class="field"><label>State / region</label><input type="text" name="state" value="{{ $v('state') }}"></div>
            <div class="field"><label>Postal code</label><input type="text" name="postal_code" value="{{ $v('postal_code') }}"></div>
            <div class="field"><label>Country</label><input type="text" name="country_name" value="{{ $v('country_name') }}"></div>
            <div class="field"><label>Usage location</label><input type="text" name="usage_location" value="{{ $v('usage_location', $country) }}" maxlength="2" style="text-transform:uppercase">
                <div class="help">Two-letter code. Required before licences can be assigned.</div></div>
        </div>
    </div>

    <div class="card">
        <h2>First sign-in</h2>
        <div class="grid grid-2">
            <div class="field"><label>Method</label>
                <select name="password_mode">
                    <option value="tap" @selected($v('password_mode', 'tap') === 'tap')>Temporary Access Pass (recommended)</option>
                    <option value="password" @selected($v('password_mode') === 'password')>Temporary password</option>
                </select></div>
            <div class="field"><label>Temporary Access Pass valid for</label>
                <select name="tap_hours">@foreach([1, 2, 4, 8] as $h)<option value="{{ $h }}" @selected((int) $v('tap_hours', 8) === $h)>{{ $h }} hour(s)</option>@endforeach</select></div>
        </div>
    </div>

    <div class="card">
        <h2>Licences and groups</h2>
        <div class="grid grid-2">
            <div>
                @foreach($licences as $l)
                    <label class="lic-row"><input type="checkbox" name="licences[]" value="{{ $l['skuId'] }}" @checked(in_array($l['skuId'], $v('licences', []), true))>
                        <span><strong>{{ $l['name'] }}</strong></span><span class="lic-seats {{ $l['available'] < 1 ? 'full' : '' }}">{{ $l['available'] }} free</span></label>
                @endforeach
            </div>
            <div class="checks-list" style="max-height:360px;overflow:auto">
                @foreach($groups as $g)
                    <label class="grp-row" @unless($g['assignable']) style="opacity:.55" title="{{ $g['why'] }}" @endunless>
                        <input type="checkbox" name="groups[]" value="{{ $g['id'] }}" @disabled(! $g['assignable']) @checked(in_array($g['id'], $v('groups', []), true))>
                        <span>{{ $g['name'] }} <small class="chip">{{ $g['kind'] }}</small></span>
                    </label>
                @endforeach
            </div>
        </div>
        <div class="label-sm" style="margin-top:12px">Hand-over checklist</div>
        @foreach($checklist as $key => [$label])
            <label style="font-weight:400;display:flex;gap:8px;margin-bottom:4px"><input type="checkbox" name="checklist[]" value="{{ $key }}" @checked(in_array($key, $v('checklist', ['device', 'mfa', 'credentials', 'training']), true))> {{ $label }}</label>
        @endforeach
    </div>

    <div class="sticky-actions">
        <span style="flex:1"></span>
        <button class="btn btn-primary">Save template</button>
    </div>
</form>
@endsection
