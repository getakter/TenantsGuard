@extends('layouts.app')
@section('title', 'Compliance')
@use('App\Services\Compliance\FrameworkRegistry')

@section('content')
<div class="page-head">
    <div>
        <h1>Compliance frameworks</h1>
        <p>Essential Eight, ASD ISM, HIPAA, CIS, NIST, ISO 27001, PCI DSS and Cyber Essentials, mapped to TenantsGuard's automated controls. Choose frameworks and in-scope controls per customer with a control plan, then roll the controls out phase by phase.</p>
    </div>
    @can('compliance.manage')
        <a class="btn btn-primary" href="{{ route('compliance.profiles.create') }}">New control plan</a>
    @endcan
</div>

<nav class="tabs">
    <a href="#tenants" class="tab">Tenant scorecards</a>
    <a href="#profiles" class="tab">Control plans ({{ $profiles->count() }})</a>
    <a href="#frameworks" class="tab">Framework library ({{ $frameworks->count() }})</a>
</nav>

<section id="tenants">
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tenant</th><th>Control plan</th><th>Framework results</th><th></th></tr></thead>
                <tbody>
                @forelse($tenants as $t)
                    <tr>
                        <td><strong>{{ $t->name }}</strong></td>
                        <td>{{ $t->complianceProfile?->name ?? '–' }}</td>
                        <td>
                            @foreach($summary[$t->id] ?? [] as $key => $fw)
                                <span class="fw fw-{{ $key }}" style="margin:2px">
                                    {{ FrameworkRegistry::SHORT[$key] }}:
                                    @if(isset($fw['maturity']))
                                        @php
                                            $min = collect($fw['maturity'])->min('achieved');
                                        @endphp
                                        ML{{ $min }} of ML{{ $fw['target'] }}
                                    @else
                                        {{ $fw['score'] === null ? 'n/a' : $fw['score'].'%' }}
                                    @endif
                                </span>
                            @endforeach
                            @unless($t->complianceProfile)<span class="muted">No control plan</span>@endunless
                        </td>
                        <td><a class="btn btn-sm" href="{{ route('compliance.tenant', $t) }}">Scorecard</a> <a class="btn btn-sm btn-primary" href="{{ route('plans.rollout', $t) }}">Rollout</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No tenants.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="help">Essential Eight maturity is the lowest level achieved across the eight strategies, which is how ASD reports overall maturity.</p>
</section>

<section id="profiles" style="margin-top:28px">
    <div class="page-head" style="margin-bottom:8px">
        <div>
            <h2 style="margin:0">Control plans</h2>
            <p>A plan = frameworks + chosen controls + rollout phases. Start from a prebuilt plan, duplicate it per client or segment, and roll it out across clients.</p>
        </div>
        @can('compliance.manage')
            <form method="post" action="{{ route('compliance.profiles.import') }}" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center">
                @csrf <input type="file" name="file" accept="application/json,.json" required style="width:auto"> <button class="btn btn-sm">Import plan</button>
            </form>
        @endcan
    </div>
    <div class="grid grid-2">
        @foreach($profiles as $p)
            <div class="card bundle-card">
                <h3 style="margin:0 0 4px">{{ $p->name }} @if($p->is_system)<span class="badge s-info">Prebuilt</span>@endif</h3>
                <p class="muted" style="margin:0 0 8px">{{ $p->description }}</p>
                <div class="fw-tags" style="margin-bottom:8px">
                    @foreach($p->frameworks as $f)
                        <span class="fw fw-{{ $f['key'] }}">{{ FrameworkRegistry::SHORT[$f['key']] ?? $f['key'] }}@if(! empty($f['target_level'])) · target ML{{ $f['target_level'] }}@endif</span>
                    @endforeach
                </div>
                <div class="muted" style="font-size:13px;margin-bottom:8px">
                    {{ count($p->candidateControls()) }} mapped controls · {{ count($p->excluded_controls ?? []) }} out of scope · {{ $p->tenants_count }} tenant(s)
                </div>
                <div class="actions">
                    <a class="btn btn-sm btn-primary" href="{{ route('compliance.portfolio', $p) }}">Roll out across clients</a>
                    @can('compliance.manage')
                        <a class="btn btn-sm" href="{{ route('compliance.profiles.edit', $p) }}">Edit</a>
                        <a class="btn btn-sm" href="{{ route('compliance.profiles.create', ['from' => $p->id]) }}">Duplicate</a>
                    @endcan
                    <a class="btn btn-sm" href="{{ route('compliance.profiles.export', $p) }}" title="Download as JSON">Export</a>
                    @can('compliance.manage')
                        <form class="inline" method="post" action="{{ route('compliance.profiles.destroy', $p) }}" data-confirm="Delete plan {{ $p->name }}? Its clients will have no plan.">@csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button></form>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
</section>

<section id="frameworks" style="margin-top:28px">
    <h2>Framework library</h2>
    <div class="grid grid-3">
        @foreach($frameworks as $key => $fw)
            <a class="card tenant-card" href="{{ route('compliance.framework', $key) }}" style="display:block">
                <div class="fw-tags" style="margin-bottom:6px"><span class="fw fw-{{ $key }}">{{ $fw['region'] }}</span> <span class="chip">{{ $fw['kind'] === 'curated' ? 'Curated requirements' : 'Mapped from control tags' }}</span></div>
                <div class="name" style="white-space:normal">{{ $fw['name'] }}</div>
                <small>{{ $fw['publisher'] }} · {{ $fw['version'] }}</small>
                <p class="muted" style="font-size:13px">{{ $fw['about'] }}</p>
                <div class="bar"><span class="p" style="width:{{ $fw['total'] ? 100 * $fw['automated_count'] / $fw['total'] : 0 }}%"></span></div>
                <small>{{ $fw['automated_count'] }} of {{ $fw['total'] }} requirements have automated controls. The rest use attestation.</small>
            </a>
        @endforeach
    </div>
    <p class="help">Mappings are expert guidance to speed up assessments, not a certification. Validate them against the framework edition you attest to.</p>
</section>
@endsection
