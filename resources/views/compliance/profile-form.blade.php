@extends('layouts.app')
@section('title', $profile->exists ? 'Edit '.$profile->name : 'New control plan')
@use('App\Services\Compliance\FrameworkRegistry')
@use('App\Services\Compliance\PlanDefaults')
@use('App\Services\Intune\IntuneCatalog')

@section('content')
@php
    $selected = $profile->frameworkKeys();
    $excluded = $profile->excluded_controls ?? [];
    $candidates = $profile->exists ? $profile->candidateControls() : [];
    sort($candidates, SORT_NATURAL);
    $controls = FrameworkRegistry::controls();
    $phases = $profile->phaseList();
    $forTenant = $forTenant ?? null;
    $copyFrom = $copyFrom ?? null;
@endphp
<div class="page-head">
    <div>
        <h1>{{ $profile->exists ? $profile->name : 'New control plan' }}</h1>
        <p>A control plan combines one or more frameworks into one de-duplicated control list, which you then roll out phase by phase or all at once.</p>
    </div>
    <a class="btn" href="{{ route('compliance.index') }}#profiles">Back</a>
</div>

<div class="steps">
    <span class="step {{ $profile->exists ? 'done' : 'current' }}">1 · Frameworks</span>
    <span class="step {{ $profile->exists ? 'current' : '' }}">2 · Controls, scope and phases</span>
    <span class="step">3 · Apply and monitor (tenant page)</span>
</div>

<form method="post" action="{{ $profile->exists ? route('compliance.profiles.update', $profile) : route('compliance.profiles.store') }}" id="plan-form">
    @csrf
    @if($profile->exists) @method('put') @endif
    @if($forTenant)<input type="hidden" name="tenant_id" value="{{ $forTenant->id }}">@endif
    @if($copyFrom)<input type="hidden" name="copy_from" value="{{ $copyFrom->id }}">@endif

    {{-- ============================== STEP 1 ============================== --}}
    <div class="card">
        <h2>Step 1: Name and frameworks</h2>
        <div class="grid grid-2">
            <div class="field">
                <label>Control plan name</label>
                <input type="text" name="name" id="plan-name" value="{{ old('name', $profile->name) }}" required placeholder="e.g. Contoso - CIS and HIPAA" data-tenant="{{ $forTenant?->name }}">
                @if($forTenant)<div class="help">Will be assigned to <strong>{{ $forTenant->name }}</strong>. The name follows the frameworks you pick until you edit it.</div>@endif
            </div>
            <div class="field"><label>Description</label><input type="text" name="description" value="{{ old('description', $profile->description) }}" placeholder="Customer context, drivers, deadlines"></div>
        </div>
        <div class="grid grid-3">
            @foreach(FrameworkRegistry::all() as $key => $fw)
                <label class="option fw-pick" style="font-weight:400;cursor:pointer;display:block">
                    <input type="checkbox" name="frameworks[]" value="{{ $key }}" data-short="{{ FrameworkRegistry::SHORT[$key] }}" @checked(in_array($key, old('frameworks', $selected), true))>
                    <strong>{{ $fw['name'] }}</strong> <span class="fw fw-{{ $key }}">{{ $fw['region'] }}</span>
                    <div class="help">{{ $fw['publisher'] }} · {{ count($fw['requirements']) }} requirements</div>
                    @if($fw['levels'])
                        <div style="margin-top:6px">Target:
                            <select name="{{ $key }}_level" style="width:auto">
                                @foreach($fw['levels'] as $lvl)<option value="{{ $lvl }}" @selected((int) old($key.'_level', $profile->targetLevel($key) ?? 2) === $lvl)>Maturity Level {{ $lvl }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                </label>
            @endforeach
        </div>
        <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-top:12px">
            <input type="hidden" name="exclude_from_score" value="0">
            <input type="checkbox" name="exclude_from_score" value="1" @checked(old('exclude_from_score', $profile->exclude_from_score ?? true))>
            Remove out-of-scope Microsoft 365 checks from the tenant's security score
        </label>
        <div class="field" style="margin-top:12px;max-width:520px">
            <label>Intune settings from bundle (optional)</label>
            <select name="intune_bundle_id">
                <option value="">Template defaults (recommended baseline values)</option>
                @foreach(\App\Models\IntuneBundle::orderBy('name')->get() as $b)
                    <option value="{{ $b->id }}" @selected((int) old('intune_bundle_id', $profile->intune_bundle_id) === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
            <div class="help">Intune controls use the tuned parameters and groups from this bundle, for example a HIPAA bundle with a 10-minute screen lock. Per-control assignments below still take priority.</div>
        </div>
        @if($profile->exists)
            <p class="help">Changing frameworks updates the control list below after you save.</p>
        @endif
    </div>

    @if($profile->exists)
        {{-- ============================== STEP 2 ============================== --}}
        <div class="card">
            <div class="page-head" style="margin-bottom:6px">
                <div>
                    <h2 style="margin:0">Step 2: Controls, scope and phases</h2>
                    <p>{{ count($candidates) }} unique controls cover {{ implode(' + ', array_map(fn ($k) => FrameworkRegistry::SHORT[$k], $selected)) }}. Tags show which framework each control satisfies. Controls tagged with more than one framework are shared.</p>
                </div>
            </div>

            <div class="grid grid-4" style="margin-bottom:12px">
                @foreach($selected as $k)
                    @php
                        $n = collect($candidates)->filter(fn ($id) => isset($profile->controlFrameworks($id)[$k]))->count();
                    @endphp
                    <div class="stat option"><div class="label">{{ FrameworkRegistry::SHORT[$k] }}</div><div class="value" style="font-size:1.4rem">{{ $n }}</div><small>controls</small></div>
                @endforeach
                @if(count($selected) > 1)
                    @php
                        $shared = collect($candidates)->filter(fn ($id) => count($profile->controlFrameworks($id)) > 1)->count();
                    @endphp
                    <div class="stat option"><div class="label">Shared</div><div class="value" style="font-size:1.4rem">{{ $shared }}</div><small>satisfy 2+ frameworks</small></div>
                @endif
            </div>

            <div class="filters" id="ctl-filters">
                <input type="text" data-f="q" placeholder="Search controls…">
                <select data-f="framework"><option value="">All selected frameworks</option>@foreach($selected as $k)<option value="{{ $k }}">Only {{ FrameworkRegistry::SHORT[$k] }}</option>@endforeach</select>
                @if(count($selected) > 1)<select data-f="overlap"><option value="">Shared and unique</option><option value="shared">Shared (2+ frameworks)</option><option value="unique">Unique to one framework</option></select>@endif
                <select data-f="type"><option value="">All types</option>@foreach(PlanDefaults::TYPES as $tk => [$tl])<option value="{{ $tk }}">{{ $tl }}</option>@endforeach</select>
                <select data-f="phase"><option value="">All phases</option>@foreach($phases as $n => $ph)<option value="{{ $n }}">Phase {{ $n }}</option>@endforeach</select>
                <select data-f="scope"><option value="">In and out of scope</option><option value="in">In scope</option><option value="out">Out of scope</option></select>
            </div>
            <div class="matrix-actions" style="margin:0 0 10px">
                <small class="muted"><span id="vis-count">0</span> shown ·</small>
                <label style="font-weight:400;display:flex;gap:6px;align-items:center"><input type="checkbox" id="sel-all"> select shown</label>
                <select id="bulk-phase" style="width:auto"><option value="">Move selected to…</option>@foreach($phases as $n => $ph)<option value="{{ $n }}">{{ $ph['name'] }}</option>@endforeach</select>
                <button type="button" class="btn btn-sm" id="bulk-in">Include selected</button>
                <button type="button" class="btn btn-sm" id="bulk-out">Exclude selected</button>
            </div>

            <div class="table-wrap">
                <table id="ctl-table">
                    <thead><tr><th style="width:28px"></th><th style="width:56px">In scope</th><th>Control</th><th>Frameworks</th><th>Type / impact</th><th style="width:170px">Phase</th><th style="width:22%">Assignment / reason</th></tr></thead>
                    <tbody>
                    @foreach($candidates as $cid)
                        @php
                            $c = $controls[$cid];
                            $isOut = array_key_exists($cid, $excluded);
                            $set = $profile->setting($cid);
                            $fwmap = $profile->controlFrameworks($cid);
                            $tpl = IntuneCatalog::find($cid);
                        @endphp
                        <tr class="ctl-row {{ $isOut ? 'is-out' : '' }}" data-q="{{ strtolower($cid.' '.$c['title']) }}" data-fw="{{ implode(' ', array_keys($fwmap)) }}"
                            data-n="{{ count($fwmap) }}" data-type="{{ $set['type'] }}" data-phase="{{ $set['phase'] }}">
                            <td><input type="checkbox" class="row-sel"></td>
                            <td>
                                <input type="hidden" name="offered[]" value="{{ $cid }}">
                                <label class="switch"><input type="checkbox" class="scope-cb" name="in_scope[]" value="{{ $cid }}" @checked(! $isOut)><span class="slider"></span></label>
                            </td>
                            <td><span class="mono muted">{{ $cid }}</span> {{ $c['title'] }}</td>
                            <td>
                                @foreach($fwmap as $fk => $reqs)
                                    <span class="fw fw-{{ $fk }}" title="{{ implode(', ', $reqs) }}">{{ FrameworkRegistry::SHORT[$fk] }} · {{ count($reqs) }}</span>
                                @endforeach
                                @if(count($fwmap) > 1)<span class="badge s-pass" title="Satisfies several selected frameworks at once">Shared</span>@endif
                            </td>
                            <td>
                                <span class="chip {{ $set['type'] === 'intune' ? 'chip-platform' : '' }}">{{ PlanDefaults::TYPES[$set['type']][0] }}</span>
                                <span class="badge {{ ['low' => 's-pass', 'medium' => 's-manual', 'high' => 's-fail'][$set['impact']] }}">{{ ucfirst($set['impact']) }}</span>
                            </td>
                            <td>
                                <select name="phase[{{ $cid }}]" class="phase-select">
                                    @foreach($phases as $n => $ph)<option value="{{ $n }}" @selected($set['phase'] === $n)>Phase {{ $n }}</option>@endforeach
                                </select>
                            </td>
                            <td>
                                @if($tpl && $tpl['kind'] !== 'manual')
                                    <select name="assignment[{{ $cid }}]" class="assign-in" @if($isOut) hidden @endif>
                                        @foreach($tpl['targets'] as $target)
                                            @continue($target === 'group' && empty($set['group']))
                                            <option value="{{ $target }}" @selected($set['assignment'] === $target)>{{ $target === 'group' ? 'Group: '.$set['group'] : IntuneCatalog::TARGETS[$target] }}</option>
                                        @endforeach
                                    </select>
                                    @if($set['from_bundle'])<small class="muted" title="Parameters come from the linked Intune bundle">tuned in bundle</small>@endif
                                @endif
                                <input type="text" class="reason" name="reason[{{ $cid }}]" value="{{ $excluded[$cid] ?? '' }}" placeholder="Why out of scope?" @unless($isOut) hidden @endunless>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ============================== STEP 3 ============================== --}}
        <div class="card">
            <h2>Step 3: Phase names and tenants</h2>
            <div class="grid grid-2">
                @foreach($phases as $n => $ph)
                    <div class="option">
                        <label>Phase {{ $n }}</label>
                        <input type="text" name="phase_names[{{ $n }}]" value="{{ $ph['name'] }}">
                        <input type="text" name="phase_descriptions[{{ $n }}]" value="{{ $ph['description'] }}" style="margin-top:6px" placeholder="What this phase achieves">
                    </div>
                @endforeach
            </div>
            <h3 style="margin-top:16px">Tenants using this plan</h3>
            <p class="muted">A tenant follows one control plan. Selecting a tenant here moves it from its previous plan.</p>
            <div class="checks-list">
                @forelse($tenants as $t)
                    <label><input type="checkbox" name="tenant_ids[]" value="{{ $t->id }}" @checked(in_array($t->id, $assigned, true))>
                        <span>{{ $t->name }} @if($t->compliance_profile_id && $t->compliance_profile_id !== $profile->id)<small class="muted">(currently {{ $t->complianceProfile?->name }})</small>@endif
                        @if(in_array($t->id, $assigned, true)) · <a href="{{ route('plans.rollout', $t) }}">rollout</a>@endif</span></label>
                @empty
                    <p class="muted">No tenants.</p>
                @endforelse
            </div>
        </div>
    @endif

    <div class="sticky-actions">
        <button class="btn btn-primary">{{ $profile->exists ? 'Save control plan' : 'Next: review controls' }}</button>
        <a class="btn" href="{{ route('compliance.index') }}#profiles">Cancel</a>
    </div>
</form>

<script>
(function () {
    // Auto-name "Tenant - CIS v8.1 and HIPAA" until the admin edits the name.
    const name = document.getElementById('plan-name');
    let touched = !!name.value && !name.value.endsWith(' - ');
    name.addEventListener('input', () => (touched = true));
    document.querySelectorAll('.fw-pick input[type=checkbox]').forEach((cb) => cb.addEventListener('change', () => {
        if (touched || !name.dataset.tenant) return;
        const picks = [...document.querySelectorAll('.fw-pick input[type=checkbox]:checked')].map((c) => c.dataset.short);
        name.value = name.dataset.tenant + ' - ' + (picks.length > 1 ? picks.slice(0, -1).join(', ') + ' and ' + picks.slice(-1) : (picks[0] || ''));
    }));

    const table = document.getElementById('ctl-table');
    if (!table) return;
    const rows = [...table.querySelectorAll('.ctl-row')];
    const f = {};
    function syncRow(r) {
        const on = r.querySelector('.scope-cb').checked;
        r.classList.toggle('is-out', !on);
        r.querySelector('.reason').hidden = on;
        const a = r.querySelector('.assign-in'); if (a) a.hidden = !on;
        r.dataset.phase = r.querySelector('.phase-select').value;
    }
    function apply() {
        document.querySelectorAll('#ctl-filters [data-f]').forEach((c) => (f[c.dataset.f] = c.value.toLowerCase()));
        let n = 0;
        rows.forEach((r) => {
            const d = r.dataset, out = r.classList.contains('is-out');
            const ok = (!f.q || d.q.includes(f.q)) && (!f.framework || d.fw.split(' ').includes(f.framework))
                && (!f.overlap || (f.overlap === 'shared' ? +d.n > 1 : +d.n === 1)) && (!f.type || d.type === f.type)
                && (!f.phase || d.phase === f.phase) && (!f.scope || (f.scope === 'out') === out);
            r.hidden = !ok; if (ok) n++;
        });
        document.getElementById('vis-count').textContent = n;
    }
    table.addEventListener('change', (e) => { const r = e.target.closest('.ctl-row'); if (r) syncRow(r); });
    document.querySelectorAll('#ctl-filters [data-f]').forEach((c) => c.addEventListener('input', apply));
    const selected = () => rows.filter((r) => !r.hidden && r.querySelector('.row-sel').checked);
    document.getElementById('sel-all').addEventListener('change', (e) => rows.forEach((r) => { if (!r.hidden) r.querySelector('.row-sel').checked = e.target.checked; }));
    document.getElementById('bulk-phase').addEventListener('change', (e) => {
        if (!e.target.value) return;
        selected().forEach((r) => { r.querySelector('.phase-select').value = e.target.value; syncRow(r); });
        e.target.value = ''; apply();
    });
    document.getElementById('bulk-in').addEventListener('click', () => { selected().forEach((r) => { r.querySelector('.scope-cb').checked = true; syncRow(r); }); apply(); });
    document.getElementById('bulk-out').addEventListener('click', () => { selected().forEach((r) => { r.querySelector('.scope-cb').checked = false; syncRow(r); }); apply(); });
    apply();
})();
</script>
@endsection
