@extends('layouts.app')
@section('title', 'Intune & devices')
@use('App\Services\Intune\IntuneCatalog')
@use('App\Models\IntuneBundle')

@section('content')
<div class="page-head">
    <div>
        <h1>Intune &amp; device management</h1>
        <p>Zero Trust device baselines for Windows, iOS/iPadOS and Android. Combine templates into bundles that fit each customer's plan, device ownership and industry, then preview and deploy them.</p>
    </div>
    <div class="actions">
        @can('intune.manage')
            <a class="btn btn-primary" href="{{ route('intune.bundles.create') }}">New bundle</a>
        @endcan
    </div>
</div>

<nav class="tabs">
    <a href="#bundles" class="tab">Bundles ({{ $bundles->count() }})</a>
    <a href="#library" class="tab">Template library ({{ count($catalog) }})</a>
    <a href="#deployments" class="tab">Deployments</a>
</nav>

{{-- ============================== BUNDLES ============================== --}}
<section id="bundles">
    <p class="muted">Bundles hold your tuned Intune settings. Link a bundle to a control plan (plan > Step 1) and the plan deploys with these settings. You can also deploy a bundle directly.</p>
    @can('intune.manage')
        <form method="post" action="{{ route('intune.bundles.import') }}" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;margin-bottom:12px">
            @csrf <input type="file" name="file" accept="application/json,.json" required style="width:auto"> <button class="btn btn-sm">Import bundle</button>
        </form>
    @endcan
    <div class="grid grid-2">
        @foreach($bundles as $b)
            @php
                $keys = $b->templateKeys();
                $cov = $b->coverage();
                $ctx = $b->context ?? [];
                $byCat = collect($keys)->countBy(fn ($k) => IntuneCatalog::find($k)['category']);
            @endphp
            <div class="card bundle-card">
                <div class="page-head" style="margin-bottom:6px">
                    <div>
                        <h2 style="margin:0">{{ $b->name }}</h2>
                        @if($b->is_system)<span class="badge s-info">Prebuilt</span>@else<span class="badge s-na">Custom</span>@endif
                    </div>
                </div>
                <p class="muted" style="margin:0 0 10px">{{ $b->description }}</p>
                <div class="pills" style="margin-bottom:8px">
                    @if(! empty($ctx['plan']))<span class="chip">{{ IntuneBundle::PLANS[$ctx['plan']] ?? $ctx['plan'] }}</span>@endif
                    @if(! empty($ctx['ownership']))<span class="chip">{{ IntuneBundle::OWNERSHIP[$ctx['ownership']] ?? $ctx['ownership'] }}</span>@endif
                    @if(! empty($ctx['industry']))<span class="chip">{{ IntuneBundle::INDUSTRIES[$ctx['industry']] ?? $ctx['industry'] }}</span>@endif
                    @foreach($b->platforms() as $p)<span class="chip chip-platform">{{ IntuneCatalog::PLATFORMS[$p] }}</span>@endforeach
                </div>
                <div class="muted" style="font-size:13px;margin-bottom:8px">
                    {{ count($keys) }} items:
                    {{ $byCat->map(fn ($n, $c) => "{$n} ".strtolower($c))->implode(' · ') }}
                </div>
                <div class="fw-tags" style="margin-bottom:10px">
                    @foreach(IntuneCatalog::FRAMEWORKS as $fw => $label)
                        @if(isset($cov[$fw]))<span class="fw fw-{{ $fw }}" title="{{ $label }}: {{ $cov[$fw] }} templates">{{ $label }} · {{ $cov[$fw] }}</span>@endif
                    @endforeach
                </div>
                <div class="actions">
                    @can('intune.deploy')
                        <a class="btn btn-primary btn-sm" href="{{ route('intune.bundles.deploy', $b) }}">Deploy…</a>
                    @endcan
                    @can('intune.manage')
                        <a class="btn btn-sm" href="{{ route('intune.bundles.edit', $b) }}">Edit</a>
                        <a class="btn btn-sm" href="{{ route('intune.bundles.create', ['from' => $b->id]) }}">Duplicate</a>
                        <a class="btn btn-sm" href="{{ route('intune.bundles.export', $b) }}" title="Download as JSON">Export</a>
                        <form class="inline" method="post" action="{{ route('intune.bundles.destroy', $b) }}" data-confirm="Delete bundle {{ $b->name }}? Policies already deployed stay in the tenants.">
                            @csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    @endcan
                    <small class="muted">Deployed {{ $b->deployments_count }}×</small>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================== LIBRARY ============================== --}}
<section id="library" style="margin-top:28px">
    <h2>Template library</h2>
    <p class="muted">Every template follows Microsoft security baselines, the CIS Benchmarks and Microsoft's app protection framework, tagged with the controls it supports. Mappings are guidance. Validate them for formal attestations.</p>
    <div class="filters">
        <input type="text" data-lib-filter="q" placeholder="Search templates…">
        <select data-lib-filter="platform">
            <option value="">All platforms</option>
            @foreach(IntuneCatalog::PLATFORMS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
        </select>
        <select data-lib-filter="category">
            <option value="">All categories</option>
            @foreach(IntuneCatalog::CATEGORIES as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
        </select>
        <select data-lib-filter="framework">
            <option value="">All frameworks</option>
            @foreach(IntuneCatalog::FRAMEWORKS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
        </select>
        <select data-lib-filter="ownership">
            <option value="">Corporate and BYOD</option>
            <option value="corporate">Corporate-owned</option>
            <option value="byod">BYOD</option>
        </select>
    </div>
    <div id="library-list">
        @foreach($catalog as $key => $t)
            <details class="finding lib-item" data-platform="{{ $t['platform'] }}" data-category="{{ $t['category'] }}"
                     data-frameworks="{{ implode(' ', array_keys($t['frameworks'])) }}" data-ownership="{{ implode(' ', $t['ownership']) }}"
                     data-search="{{ strtolower($key.' '.$t['name'].' '.$t['summary']) }}">
                <summary>
                    <span class="fid">{{ $key }}</span>
                    <div>
                        <div class="ftitle">{{ $t['name'] }}</div>
                        <div class="fsum">{{ $t['summary'] }}</div>
                    </div>
                    <span class="sevcol"><span class="chip chip-platform">{{ IntuneCatalog::PLATFORMS[$t['platform']] }}</span></span>
                    <span class="badge {{ ['low' => 's-pass', 'medium' => 's-manual', 'high' => 's-fail'][$t['impact']] }}" title="End-user impact">{{ ucfirst($t['impact']) }} impact</span>
                </summary>
                <div class="body">
                    <div>
                        <p style="margin-top:0"><strong>Why:</strong> {{ $t['rationale'] }}</p>
                        @if($t['kind'] === 'manual')
                            <h3>Steps</h3>
                            <ol style="padding-left:18px">@foreach($t['steps'] as $s)<li>{{ $s }}</li>@endforeach</ol>
                            <p class="help">{{ $t['verify'] ? 'TenantsGuard checks this automatically on each tenant.' : 'Confirm manually in the portal.' }}</p>
                        @elseif($t['params'])
                            <h3>Tunable settings</h3>
                            <dl class="kv">
                                @foreach($t['params'] as $pk => $pd)
                                    <dt>{{ $pd['label'] }}</dt>
                                    <dd>{{ $pd['type'] === 'select' ? $pd['options'][$pd['default']] : ($pd['type'] === 'bool' ? ($pd['default'] ? 'Yes' : 'No') : $pd['default']) }} <small class="muted">default</small></dd>
                                @endforeach
                            </dl>
                        @endif
                        <h3 style="margin-top:12px">Framework mapping</h3>
                        @include('intune._tags', ['t' => $t, 'full' => true])
                    </div>
                    <div>
                        <dl class="kv">
                            <dt>Category</dt><dd>{{ $t['category'] }}</dd>
                            <dt>Applies to</dt><dd>{{ implode(', ', array_map(fn ($o) => ['corporate' => 'Corporate-owned', 'byod' => 'BYOD'][$o], $t['ownership'])) }}</dd>
                            <dt>Licences</dt><dd>{{ implode(', ', array_map(fn ($l) => IntuneCatalog::LICENSES[$l], $t['license'])) ?: '–' }}</dd>
                            @if(! empty($t['odata']))<dt>Graph type</dt><dd class="mono">{{ $t['odata'] }}</dd>@endif
                            @if($t['kind'] !== 'manual')<dt>Default assignment</dt><dd>{{ IntuneCatalog::TARGETS[$t['default_target']] }}</dd>@endif
                            @if($t['kind'] === 'ca')<dt>State</dt><dd>Report-only on deployment</dd>@endif
                        </dl>
                    </div>
                </div>
            </details>
        @endforeach
    </div>
</section>

{{-- ============================== DEPLOYMENTS ============================== --}}
<section id="deployments" style="margin-top:28px">
    <h2>Recent deployments</h2>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Bundle</th><th>Tenants</th><th>Mode</th><th>Result</th><th>By</th><th></th></tr></thead>
                <tbody>
                @forelse($runs as $batch => $deps)
                    @php
                        $d0 = $deps->first();
                        $sum = $deps->reduce(function ($c, $d) { foreach ($d->summary ?? [] as $k => $n) { $c[$k] = ($c[$k] ?? 0) + $n; } return $c; }, []);
                    @endphp
                    <tr>
                        <td style="white-space:nowrap">{{ $d0->created_at->diffForHumans() }}</td>
                        <td>{{ $d0->bundle_name }}</td>
                        <td>{{ $deps->pluck('tenant.name')->filter()->implode(', ') }}</td>
                        <td>{{ $d0->dry_run ? 'Preview' : 'Deploy' }}@if($d0->update_existing) · update @endif</td>
                        <td>@if($deps->contains(fn ($d) => $d->isActive()))<span class="badge s-info">In progress</span> @endif @foreach($sum as $k => $n)<span class="badge {{ \App\Models\IntuneDeploymentItem::STATUS_LABELS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(\App\Models\IntuneDeploymentItem::STATUS_LABELS[$k][0] ?? $k) }}</span> @endforeach</td>
                        <td class="muted">{{ $d0->user?->name ?? 'System' }}</td>
                        <td><a href="{{ route('intune.run', $batch) }}">Details</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No deployments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
    (function () {
        const items = document.querySelectorAll('.lib-item');
        const ctl = document.querySelectorAll('[data-lib-filter]');
        function apply() {
            const f = {};
            ctl.forEach((c) => (f[c.dataset.libFilter] = c.value.trim().toLowerCase()));
            items.forEach((el) => {
                const d = el.dataset;
                el.hidden = !((!f.q || d.search.includes(f.q)) && (!f.platform || d.platform === f.platform)
                    && (!f.category || d.category.toLowerCase() === f.category)
                    && (!f.framework || d.frameworks.split(' ').includes(f.framework))
                    && (!f.ownership || d.ownership.split(' ').includes(f.ownership)));
            });
        }
        ctl.forEach((c) => c.addEventListener('input', apply));
    })();
</script>
@endsection
