@extends('mfa.layout')
@section('title', 'Recovery codes')
@section('heading', 'Save your recovery codes')
@section('width', '460px')

@section('body')
<p style="margin-top:0">If you lose your phone, each of these codes lets you sign in <strong>once</strong>. Store them in your password manager. They are shown only now.</p>
<div id="codes" class="mono" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;padding:14px;font-size:15px">
    @foreach($codes as $c)<span>{{ $c }}</span>@endforeach
</div>
<div class="actions" style="margin:12px 0">
    <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(@js(implode("\n", $codes))); this.textContent='Copied'">Copy</button>
    <button type="button" class="btn btn-sm" onclick="const a=document.createElement('a');a.href='data:text/plain;charset=utf-8,'+encodeURIComponent(@js("TenantsGuard recovery codes\n".implode("\n", $codes)));a.download='tenantsguard-recovery-codes.txt';a.click()">Download</button>
</div>
<a class="btn btn-primary btn-block" href="{{ route('dashboard') }}">I saved them, continue</a>
@endsection
