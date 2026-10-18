@extends('layouts.app')
@section('title', 'Deploy '.$bundle->name)
@use('App\Services\Intune\IntuneCatalog')

@section('content')
<div class="page-head">
    <div>
        <h1>Deploy: {{ $bundle->name }}</h1>
        <p>{{ count($bundle->templateKeys()) }} templates. Run a <strong>preview</strong> first: it shows exactly what will be created, updated or skipped in each tenant, without changing anything.</p>
    </div>
    <a class="btn" href="{{ route('intune.index') }}">Back</a>
</div>

<form method="post" action="{{ route('intune.bundles.deploy.run', $bundle) }}" id="deploy-form">
    @csrf
    <div class="card">
        <h2>1. Choose tenants</h2>
        @if($tenants->isEmpty())
            <p class="muted">You have no tenants.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:40px"><input type="checkbox" data-toggle-all=".dep-tenant"></th><th>Tenant</th><th>Licences</th><th>Fit for this bundle</th></tr></thead>
                    <tbody>
                    @foreach($tenants as $t)
                        @php
                            $f = $fit[$t->id];
                        @endphp
                        <tr>
                            <td><input type="checkbox" class="dep-tenant" name="tenant_ids[]" value="{{ $t->id }}" @disabled(! $f['ready'])></td>
                            <td><strong>{{ $t->name }}</strong><br><small>{{ $t->domain }}</small></td>
                            <td>
                                @if($f['ready'])
                                    @foreach(['intune' => 'Intune', 'p1' => 'Entra P1', 'mde' => 'Defender'] as $lk => $ll)
                                        <span class="badge {{ ($f['licenses'][$lk] ?? false) ? 's-pass' : 's-na' }}">{{ $ll }}</span>
                                    @endforeach
                                @else
                                    <span class="badge s-manual">Not connected</span>
                                @endif
                            </td>
                            <td>
                                @if(! $f['ready'])
                                    <span class="muted">Grant admin consent first.</span>
                                @elseif(! $f['missing'])
                                    <span style="color:var(--pass)">✓ All templates can be deployed</span>
                                @else
                                    @foreach($f['missing'] as $lic => $keys)
                                        <div class="muted" style="font-size:13px">No {{ IntuneCatalog::LICENSES[$lic] }}: {{ count($keys) }} template(s) will be skipped ({{ implode(', ', $keys) }})</div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>2. Options</h2>
        <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start">
            <input type="hidden" name="update_existing" value="0">
            <input type="checkbox" name="update_existing" value="1">
            <span><strong>Update policies that already exist</strong><br><small class="muted">Off: anything previously deployed by TenantsGuard is left unchanged. On: settings and assignments are re-applied from this bundle.</small></span>
        </label>
        <ul class="muted" style="font-size:13px;margin:12px 0 0;padding-left:18px">
            <li>Policies are named <code>{{ IntuneCatalog::PREFIX }}…</code> and never overwrite policies you created yourself.</li>
            <li>Pilot assignments go to the <code>TenantsGuard Pilot</code> group, created empty if missing. Add IT users and devices to it.</li>
            <li>Conditional Access policies are always created in report-only mode.</li>
            <li>Prerequisites (Apple push certificate, Managed Google Play, Defender connector, LAPS) are verified and listed as action items.</li>
        </ul>
    </div>

    <div class="sticky-actions">
        <button class="btn" name="mode" value="preview" data-busy="Previewing…">Preview (no changes)</button>
        <button class="btn btn-primary" name="mode" value="deploy" data-confirm-btn="Deploy {{ $bundle->name }} to the selected tenants? This creates Intune policies in those tenants.">Deploy</button>
    </div>
</form>
@endsection
