@extends('layouts.app')
@section('title', $isNew ? 'New Intune bundle' : 'Edit '.$bundle->name)
@use('App\Services\Intune\IntuneCatalog')
@use('App\Models\IntuneBundle')

@section('content')
@php
    $existing = collect($bundle->items ?? [])->keyBy('template');
    $ctx = $bundle->context ?? [];
    $oldItems = old('items');
    $platformsSel = old('platforms', $ctx['platforms'] ?? []);
    $grouped = collect($catalog)->groupBy('platform');
@endphp

<div class="page-head">
    <div>
        <h1>{{ $isNew ? 'New Intune bundle' : 'Edit bundle' }}</h1>
        <p>Pick the templates this customer needs, tune their settings and choose who receives them. Nothing is deployed until you deploy the bundle.</p>
    </div>
    <a class="btn" href="{{ route('intune.index') }}">Back</a>
</div>

<form method="post" action="{{ $isNew ? route('intune.bundles.store') : route('intune.bundles.update', $bundle) }}" id="bundle-form">
    @csrf
    @unless($isNew) @method('put') @endunless

    <div class="card">
        <h2>Bundle and customer context</h2>
        <div class="grid grid-2">
            <div class="field"><label>Name</label><input type="text" name="name" value="{{ old('name', $bundle->name) }}" required></div>
            <div class="field"><label>Licensing plan</label>
                <select name="plan">@foreach(IntuneBundle::PLANS as $k => $v)<option value="{{ $k }}" @selected(old('plan', $ctx['plan'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="field"><label>Device ownership</label>
                <select name="ownership">@foreach(IntuneBundle::OWNERSHIP as $k => $v)<option value="{{ $k }}" @selected(old('ownership', $ctx['ownership'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
            </div>
            <div class="field"><label>Industry / regulation</label>
                <select name="industry">@foreach(IntuneBundle::INDUSTRIES as $k => $v)<option value="{{ $k }}" @selected(old('industry', $ctx['industry'] ?? '') === $k)>{{ $v }}</option>@endforeach</select>
            </div>
        </div>
        <div class="field"><label>Description</label><textarea name="description" rows="2">{{ old('description', $bundle->description) }}</textarea></div>
        <div class="field">
            <label>Platforms in scope</label>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                @foreach(['windows' => 'Windows', 'ios' => 'iOS / iPadOS', 'android' => 'Android'] as $k => $v)
                    <label style="font-weight:400;display:flex;gap:6px;align-items:center"><input type="checkbox" name="platforms[]" value="{{ $k }}" data-platform-toggle @checked(in_array($k, $platformsSel, true))> {{ $v }}</label>
                @endforeach
            </div>
            <div class="help">Unticking a platform hides its templates. Selected templates stay selected.</div>
        </div>
        <div class="help">Recommended rollout: deploy everything to the <strong>TenantsGuard Pilot</strong> group first, add IT staff to it, review for a week, then edit the bundle and assign to all users or devices.</div>
    </div>

    <div class="bundle-summary card" style="margin-top:16px">
        <strong><span id="sel-count">0</span> templates selected</strong>
        <span class="muted" id="sel-cats"></span>
    </div>

    @foreach(IntuneCatalog::PLATFORMS as $pk => $plabel)
        @continue(! isset($grouped[$pk]))
        <div class="card platform-block" data-platform="{{ $pk }}" style="margin-top:16px">
            <div class="page-head" style="margin-bottom:6px">
                <h2 style="margin:0">{{ $plabel }}</h2>
                <button type="button" class="linkbtn" data-select-platform="{{ $pk }}">Select all</button>
            </div>
            @foreach($grouped[$pk]->groupBy('category') as $cat => $templates)
                <h3 class="cat-head">{{ $cat }}</h3>
                @foreach($templates as $t)
                    @php
                        $key = $t['key'];
                        $cur = $oldItems[$key] ?? null;
                        $ex = $existing[$key] ?? null;
                        $included = $cur !== null ? ! empty($cur['include']) : (bool) $ex;
                        $assignment = $cur['assignment'] ?? $ex['assignment'] ?? ($t['default_target'] ?? 'none');
                        $group = $cur['group'] ?? $ex['group'] ?? '';
                        $values = IntuneCatalog::params($t, $cur['params'] ?? $ex['params'] ?? []);
                    @endphp
                    <div class="tpl-row {{ $included ? 'is-on' : '' }}" data-category="{{ $cat }}">
                        <label class="tpl-head">
                            <input type="checkbox" name="items[{{ $key }}][include]" value="1" class="tpl-include" @checked($included)>
                            <span>
                                <span class="mono muted">{{ $key }}</span>
                                <strong>{{ $t['name'] }}</strong>
                                <span class="badge {{ ['low' => 's-pass', 'medium' => 's-manual', 'high' => 's-fail'][$t['impact']] }}">{{ ucfirst($t['impact']) }} impact</span>
                                @foreach($t['ownership'] as $o)<span class="chip">{{ $o === 'byod' ? 'BYOD' : 'Corporate' }}</span>@endforeach
                                <br><small class="muted">{{ $t['summary'] }}</small>
                            </span>
                        </label>
                        @include('intune._tags', ['t' => $t])
                        <div class="tpl-config">
                            @if($t['kind'] === 'manual')
                                <p class="help">Prerequisite or portal step: verified (where possible) and listed as an action item during deployment. Nothing is changed automatically.</p>
                            @else
                                <div class="grid grid-3">
                                    <div class="field">
                                        <label>Assign to</label>
                                        <select name="items[{{ $key }}][assignment]" class="assign-select">
                                            @foreach($t['targets'] as $target)
                                                <option value="{{ $target }}" @selected($assignment === $target)>{{ IntuneCatalog::TARGETS[$target] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field group-field" @if($assignment !== 'group') hidden @endif>
                                        <label>Group name</label>
                                        <input type="text" name="items[{{ $key }}][group]" value="{{ $group }}" placeholder="e.g. Intune - Finance devices">
                                        <div class="help">Created as a security group if it does not exist.</div>
                                    </div>
                                </div>
                                @if($t['params'])
                                    <div class="grid grid-3">
                                        @foreach($t['params'] as $pkey => $pd)
                                            <div class="field">
                                                <label>{{ $pd['label'] }}</label>
                                                @switch($pd['type'])
                                                    @case('select')
                                                        <select name="items[{{ $key }}][params][{{ $pkey }}]">
                                                            @foreach($pd['options'] as $ov => $ol)<option value="{{ $ov }}" @selected((string) $values[$pkey] === (string) $ov)>{{ $ol }}</option>@endforeach
                                                        </select>
                                                        @break
                                                    @case('bool')
                                                        <input type="hidden" name="items[{{ $key }}][params][{{ $pkey }}]" value="0">
                                                        <label style="font-weight:400"><input type="checkbox" name="items[{{ $key }}][params][{{ $pkey }}]" value="1" @checked($values[$pkey])> Enabled</label>
                                                        @break
                                                    @case('int')
                                                        <input type="number" name="items[{{ $key }}][params][{{ $pkey }}]" value="{{ $values[$pkey] }}" min="{{ $pd['min'] ?? '' }}" max="{{ $pd['max'] ?? '' }}">
                                                        @break
                                                    @default
                                                        <input type="text" name="items[{{ $key }}][params][{{ $pkey }}]" value="{{ $values[$pkey] }}">
                                                @endswitch
                                                @if(! empty($pd['help']))<div class="help">{{ $pd['help'] }}</div>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="help" style="margin:0"><strong>Why:</strong> {{ $t['rationale'] }} · Licence: {{ implode(', ', array_map(fn ($l) => IntuneCatalog::LICENSES[$l], $t['license'])) }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    @endforeach

    <div class="sticky-actions">
        <button class="btn btn-primary">{{ $isNew ? 'Create bundle' : 'Save bundle' }}</button>
        <a class="btn" href="{{ route('intune.index') }}">Cancel</a>
    </div>
</form>

<script>
    (function () {
        const form = document.getElementById('bundle-form');
        const rows = [...form.querySelectorAll('.tpl-row')];
        function refresh() {
            const on = rows.filter((r) => r.querySelector('.tpl-include').checked);
            rows.forEach((r) => r.classList.toggle('is-on', r.querySelector('.tpl-include').checked));
            document.getElementById('sel-count').textContent = on.length;
            const cats = {};
            on.forEach((r) => (cats[r.dataset.category] = (cats[r.dataset.category] || 0) + 1));
            document.getElementById('sel-cats').textContent = Object.entries(cats).map(([c, n]) => `${n} ${c.toLowerCase()}`).join(' · ');
        }
        form.addEventListener('change', (e) => {
            if (e.target.classList.contains('assign-select')) {
                e.target.closest('.tpl-config').querySelector('.group-field').hidden = e.target.value !== 'group';
            }
            if (e.target.matches('[data-platform-toggle]')) {
                document.querySelectorAll(`.platform-block[data-platform="${e.target.value}"]`).forEach((b) => (b.hidden = !e.target.checked));
            }
            refresh();
        });
        document.querySelectorAll('[data-select-platform]').forEach((b) => b.addEventListener('click', () => {
            const boxes = [...document.querySelectorAll(`.platform-block[data-platform="${b.dataset.selectPlatform}"] .tpl-include`)];
            const target = !boxes.every((x) => x.checked);
            boxes.forEach((x) => (x.checked = target));
            refresh();
        }));
        document.querySelectorAll('[data-platform-toggle]').forEach((cb) => {
            document.querySelectorAll(`.platform-block[data-platform="${cb.value}"]`).forEach((b) => (b.hidden = !cb.checked));
        });
        refresh();
    })();
</script>
@endsection
