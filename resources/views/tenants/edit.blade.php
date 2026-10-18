@extends('layouts.app')
@section('title', 'Client settings: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'settings'])

<div class="grid grid-2">
    <div class="card">
        <h2>Client details</h2>
        <form method="post" action="{{ route('tenants.update', $tenant) }}">
            @csrf @method('put')
            <div class="field"><label>Display name</label><input type="text" name="name" value="{{ old('name', $tenant->name) }}" required></div>
            <div class="field"><label>Tags</label><input type="text" name="tags" value="{{ old('tags', implode(', ', $tenant->tags ?? [])) }}" placeholder="Healthcare, Tier 1, AU">
                <div class="help">Comma separated. Use them to filter the portfolio, for example by industry, region, contract tier or technician team.</div></div>
            <div class="grid grid-2">
                <div class="field"><label>Client contact</label><input type="text" name="contact_name" value="{{ old('contact_name', $tenant->contact_name) }}"></div>
                <div class="field"><label>Contact email</label><input type="email" name="contact_email" value="{{ old('contact_email', $tenant->contact_email) }}"></div>
            </div>
            <div class="field"><label>Internal notes</label><textarea name="notes" rows="4" placeholder="Break-glass account names, change windows, known exceptions…">{{ old('notes', $tenant->notes) }}</textarea></div>
            <button class="btn btn-primary">Save</button>
        </form>
    </div>

    <div>
        <div class="card">
            <h2>Connection</h2>
            <dl class="kv">
                <dt>Tenant ID</dt><dd class="mono">{{ $tenant->ms_tenant_id }}</dd>
                <dt>Status</dt><dd>{{ $tenant->isDemo() ? 'Demo (simulated)' : ucfirst($tenant->consent_status) }}</dd>
                <dt>Control plan</dt><dd>{{ $tenant->complianceProfile?->name ?? 'None' }}</dd>
                <dt>Assigned staff</dt><dd>{{ $tenant->users()->pluck('name')->implode(', ') ?: 'Only users with access to all tenants' }}</dd>
            </dl>
            @if(! $tenant->isDemo() && \App\Support\MicrosoftApp::configured())
                <div class="actions" style="margin-top:12px">
                    <a class="btn" href="{{ route('tenants.consent', $tenant) }}" data-ms-popup>Re-grant admin consent</a>
                    <form method="post" action="{{ route('tenants.verify', $tenant) }}" data-busy="Verifying…">@csrf <button class="btn">Verify connection</button></form>
                </div>
                <p class="help">Re-grant consent after app permissions change, for example when new modules are added.</p>
            @endif
        </div>

        <div class="card" style="border-color:var(--fail)">
            <h2>Offboard client</h2>
            <p class="muted">Removes the client and its history from TenantsGuard. Nothing changes in their Microsoft 365 tenant. To revoke access completely, the client deletes the TenantsGuard enterprise application in Entra ID.</p>
            <form method="post" action="{{ route('tenants.destroy', $tenant) }}" data-confirm="Remove {{ $tenant->name }} and all of its scans, plans runs and audit history from TenantsGuard?">
                @csrf @method('delete')
                <div class="field"><input type="text" name="confirm_name" placeholder="Type the client name to confirm" required></div>
                <button class="btn btn-danger">Remove client</button>
            </form>
        </div>
    </div>
</div>
@endsection
