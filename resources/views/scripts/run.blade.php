@extends('layouts.app')
@section('title', 'Run '.$script->name)
@use('App\Models\ScriptVersion')

@section('content')
@php
    $v = $version;
    $change = $v->risk === 'change';
    $tags = $tenants->flatMap(fn ($t) => $t->tags ?? [])->unique()->sort()->values();
@endphp
<div class="page-head">
    <div>
        <h1>Run {{ $script->name }}</h1>
        <p>Approved version {{ $v->version }} · <span class="badge {{ ScriptVersion::RISK[$v->risk][1] }}">{{ ScriptVersion::RISK[$v->risk][0] }}</span> · approved by {{ $v->reviewer->name ?? '?' }} {{ $v->reviewed_at?->diffForHumans() }}</p>
    </div>
    <a class="btn" href="{{ route('scripts.show', $script) }}">View code</a>
</div>

@unless($enabled)
    <div class="alert alert-warn">PowerShell execution is switched off by a Global Administrator, so this script cannot be queued.</div>
@endunless
@if($change)
    <div class="alert alert-warn"><strong>This script changes client tenants.</strong> {{ ScriptVersion::RISK['change'][2] }}</div>
@endif

<form method="post" action="{{ route('scripts.run.store', $script) }}" id="runform">
    @csrf
    <input type="hidden" name="version_id" value="{{ $v->id }}">

    <div class="card">
        <div class="card-title">1 · Clients <small class="muted" style="font-weight:400" id="tcount">0 selected (max {{ $max }})</small></div>
        <div class="test-toolbar">
            <input type="text" placeholder="Search clients…" oninput="const q=this.value.toLowerCase();document.querySelectorAll('.tenant-pick label').forEach(l=>l.hidden=!l.dataset.q.includes(q))">
            @if($tags->isNotEmpty())
                <select onchange="if(this.value){document.querySelectorAll('.tenant-pick label').forEach(l=>{if(l.dataset.tags.split('|').includes(this.value))l.querySelector('input').checked=true});count();this.value=''}">
                    <option value="">Select by tag…</option>@foreach($tags as $tg)<option>{{ $tg }}</option>@endforeach
                </select>
            @endif
            <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.tenant-pick label:not([hidden]) input').forEach(i=>i.checked=true);count()">Select shown</button>
            <button type="button" class="btn btn-sm" onclick="document.querySelectorAll('.tenant-pick input').forEach(i=>i.checked=false);count()">Clear</button>
        </div>
        @if($tenants->isEmpty())
            <p class="muted">No connected clients are assigned to you.</p>
        @else
            <div class="tenant-pick">
                @foreach($tenants as $t)
                    <label data-q="{{ strtolower($t->name.' '.$t->domain.' '.implode(' ', $t->tags ?? [])) }}" data-tags="{{ implode('|', $t->tags ?? []) }}">
                        <input type="checkbox" name="tenants[]" value="{{ $t->id }}" onchange="count()" @checked(in_array($t->id, array_map('intval', old('tenants', request('tenant') ? [request('tenant')] : [])), true))>
                        <span>{{ $t->name }} <small class="muted">{{ $t->domain }}</small>@if($t->isDemo()) <span class="chip">Demo</span>@endif</span>
                    </label>
                @endforeach
            </div>
        @endif
        @error('tenants')<div class="error-text">{{ $message }}</div>@enderror
    </div>

    @if($v->parameters)
        <div class="card">
            <div class="card-title">2 · Parameters</div>
            <div class="grid grid-2">
                @foreach($v->parameters as $p)
                    <div class="field">
                        <label>{{ $p['label'] }} @if($p['required'])<span class="error-text">*</span>@endif</label>
                        @if($p['type'] === 'bool')
                            <label style="font-weight:400;display:flex;gap:8px"><input type="checkbox" name="params[{{ $p['name'] }}]" value="1" @checked(old('params.'.$p['name'], $p['default']))> Yes</label>
                        @elseif($p['type'] === 'choice')
                            <select name="params[{{ $p['name'] }}]">@unless($p['required'])<option value="">–</option>@endunless @foreach($p['options'] as $o)<option @selected(old('params.'.$p['name'], $p['default']) === $o)>{{ $o }}</option>@endforeach</select>
                        @else
                            <input type="{{ $p['type'] === 'int' ? 'number' : ($p['type'] === 'email' ? 'email' : 'text') }}" name="params[{{ $p['name'] }}]" value="{{ old('params.'.$p['name'], $p['default']) }}" @required($p['required']) maxlength="500">
                        @endif
                        @if(! empty($p['help']))<div class="help">{{ $p['help'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card" @if($change) style="border-color:var(--fail)" @endif>
        <div class="card-title">{{ $v->parameters ? 3 : 2 }} · Reason and confirmation</div>
        <div class="grid grid-3">
            <div class="field" style="grid-column:span 2"><label>Why are you running this?</label><input type="text" name="reason" value="{{ old('reason') }}" required minlength="5" maxlength="500" placeholder="Kept in the audit log, for example: quarterly guest review">
                @error('reason')<div class="error-text">{{ $message }}</div>@enderror</div>
            <div class="field"><label>Ticket / reference</label><input type="text" name="ticket" value="{{ old('ticket') }}" maxlength="80"></div>
        </div>
        @if($change)
            <div class="grid grid-2">
                <div class="field"><label>Type <code id="confirmWord">RUN 0</code> to confirm</label><input type="text" name="confirm" autocomplete="off" required>
                    @error('confirm')<div class="error-text">{{ $message }}</div>@enderror</div>
                <div class="field"><label>Authenticator code</label><input type="text" name="mfa_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    <div class="help">Change scripts need a fresh code from your authenticator app every time.</div>
                    @error('mfa_code')<div class="error-text">{{ $message }}</div>@enderror</div>
            </div>
        @endif
        <button class="btn {{ $change ? 'btn-solid-danger' : 'btn-primary' }}" @disabled(! $enabled)>@include('partials.icon', ['name' => 'terminal', 'size' => 16]) Run on <span id="btncount">0</span> client(s)</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
function count() {
  const n = document.querySelectorAll('.tenant-pick input:checked').length;
  document.getElementById('tcount').textContent = n + ' selected (max {{ $max }})';
  document.getElementById('btncount').textContent = n;
  const w = document.getElementById('confirmWord'); if (w) w.textContent = 'RUN ' + n;
}
count();
</script>
@endpush
