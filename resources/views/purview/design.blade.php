@extends('layouts.app')
@section('title', 'Purview design: '.$project->tenant->name)

@section('content')
@include('purview._head')
@php
    $d = $project->design;
    $can = auth()->user()->can('purview.manage');
    $labelsIncluded = collect($d['labels'])->where('include', true);
@endphp

@if($project->isApproved())
    <div class="alert alert-ok">Approved by {{ $project->approvedBy->name ?? '?' }} on {{ $project->design_approved_at->toDayDateTimeString() }}. Design hash <code>{{ substr($project->design_hash, 0, 16) }}</code>. Any change needs a new approval.</div>
@endif

<div class="card">
    <div class="card-title">@include('partials.icon', ['name' => 'book']) Architecture decisions</div>
    @foreach($d['decisions'] as [$t, $text])
        <div style="padding:8px 0;border-bottom:1px dashed var(--border)"><strong>{{ $t }}.</strong> <span class="muted">{{ $text }}</span></div>
    @endforeach
</div>

<form method="post" action="{{ route('purview.design.update', $project) }}">
    @csrf
    <fieldset @disabled(! $can) style="border:0;padding:0;margin:0">

    <div class="card">
        <div class="card-title">Sensitivity labels <small class="muted" style="font-weight:400">In priority order, least to most sensitive. Sub-labels are indented.</small></div>
        <div class="table-wrap">
            <table class="design-table">
                <thead><tr><th>Use</th><th>Label</th><th>Tooltip shown to users</th><th>Protection</th><th>Header</th><th>Watermark</th><th>Teams / sites</th></tr></thead>
                <tbody>
                @foreach($d['labels'] as $i => $l)
                    <tr>
                        <td><input type="checkbox" name="labels[{{ $i }}][include]" value="1" @checked($l['include']) @disabled($l['key'] === 'General')></td>
                        <td class="{{ $l['parent'] ? 'sub-label' : '' }}" style="min-width:190px"><span class="swatch" style="background:{{ $l['color'] }}"></span><input type="text" name="labels[{{ $i }}][display]" value="{{ $l['display'] }}" maxlength="64" style="width:calc(100% - 20px)"><br><small class="muted">{{ $l['key'] }}</small></td>
                        <td style="min-width:320px"><textarea name="labels[{{ $i }}][tooltip]" rows="2" maxlength="500" style="min-height:0">{{ $l['tooltip'] }}</textarea></td>
                        <td><select name="labels[{{ $i }}][encryption]">
                            <option value="none" @selected($l['encryption'] === 'none')>Marking only</option>
                            <option value="org" @selected($l['encryption'] === 'org')>Encrypt: organisation only</option>
                            <option value="user" @selected($l['encryption'] === 'user')>Encrypt: user chooses people</option>
                        </select></td>
                        <td><input type="text" name="labels[{{ $i }}][header]" value="{{ $l['header'] }}" maxlength="80" placeholder="none"></td>
                        <td><input type="text" name="labels[{{ $i }}][watermark]" value="{{ $l['watermark'] }}" maxlength="80" placeholder="none"></td>
                        <td><small>{{ $l['container'] ? $l['container']['privacy'].', guests '.($l['container']['guests'] ? 'allowed' : 'blocked').', sharing: '.(\App\Services\Purview\Catalog::SHARING[$l['container']['sharing']] ?? $l['container']['sharing']) : '–' }}</small></td>
                    </tr>
                    @if($l['recommend'])
                        <tr><td></td><td colspan="6"><small class="muted">{{ $l['auto_mode'] === 'automatic' ? 'Apply automatically' : 'Recommend' }} in Office when content contains: {{ implode(', ', $l['recommend']) }}</small></td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="grid grid-3" style="margin-top:14px">
            <div class="field"><label>Default label (documents and email)</label>
                <select name="policy[default_document]">@foreach($labelsIncluded as $l)<option value="{{ $l['key'] }}" @selected($d['label_policy']['default_document'] === $l['key'])>{{ $l['parent'] ? $l['parent'].' \ ' : '' }}{{ $l['display'] }}</option>@endforeach</select></div>
            <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="policy[mandatory]" value="1" @checked($d['label_policy']['mandatory'])> Mandatory labelling (production)</label>
            <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="policy[justification]" value="1" @checked($d['label_policy']['justification'])> Ask for a reason when lowering a label</label>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Data loss prevention <small class="muted" style="font-weight:400">Simulation, then pilot tips, then enforcement.</small></div>
        @forelse($d['dlp'] as $i => $x)
            <div class="tpl-row {{ $x['include'] ? 'is-on' : '' }}">
                <label class="tpl-head"><input type="checkbox" name="dlp[{{ $i }}][include]" value="1" @checked($x['include'])>
                    <span><strong>{{ $x['name'] }}</strong><br><small class="muted">{{ $x['purpose'] }}</small><br><small>Detects: {{ implode(', ', $x['sits']) }}</small></span></label>
                <div class="grid grid-3" style="margin-top:8px">
                    <div class="field"><label>Locations</label>
                        @foreach(['Exchange', 'SharePoint', 'OneDrive', 'Teams', 'Devices'] as $loc)
                            @php
                                $lic = match ($loc) { 'Teams' => ($caps['dlp_teams'] ?? null) !== false, 'Devices' => ($caps['dlp_endpoint'] ?? null) !== false, default => true };
                            @endphp
                            <label style="font-weight:400;display:inline-flex;gap:4px;margin-right:10px" @unless($lic) title="Not licensed" @endunless><input type="checkbox" name="dlp[{{ $i }}][locations][]" value="{{ $loc }}" @checked(in_array($loc, $x['locations'], true)) @disabled(! $lic)> {{ $loc }}</label>
                        @endforeach
                    </div>
                    <div class="field"><label>High volume from (instances)</label><input type="number" name="dlp[{{ $i }}][high_threshold]" value="{{ $x['high_threshold'] }}" min="1" max="500" style="max-width:120px">
                        <div class="help">Below this: policy tip only. From this: the response below.</div></div>
                    <div class="field"><label>Response to high volume shared externally</label>
                        <select name="dlp[{{ $i }}][style]">
                            <option value="notify" @selected($x['style'] === 'notify')>Tip and report only</option>
                            <option value="block_override" @selected($x['style'] === 'block_override')>Block, override with reason</option>
                            <option value="block" @selected($x['style'] === 'block')>Block, no override</option>
                        </select></div>
                </div>
            </div>
        @empty
            <p class="muted">No sensitive data types with built-in detection were selected in the requirements.</p>
        @endforelse
    </div>

    @if($d['autolabel'])
        <div class="card">
            <div class="card-title">Service-side auto-labelling <small class="muted" style="font-weight:400">Runs in simulation first.</small></div>
            @foreach($d['autolabel'] as $i => $a)
                <label class="tpl-head"><input type="checkbox" name="autolabel[{{ $i }}][include]" value="1" @checked($a['include'])>
                    <span><strong>{{ $a['name'] }}</strong> → {{ $a['label'] }}<br><small class="muted">{{ $a['sits'] ? 'Detects: '.implode(', ', $a['sits']).' (at least '.$a['min_count'].')' : 'Trainable classifiers: '.implode(', ', $a['classifiers'] ?? []).'. Configure in the portal.' }}</small></span></label>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-title">Retention <small class="muted" style="font-weight:400">Confirm periods with legal counsel. Defaults follow common requirements for {{ \App\Services\Purview\Catalog::REGIONS[$project->requirements['region']] ?? 'the region' }}.</small></div>
        <div class="grid grid-3">
            <div class="field"><label>Baseline: keep email and files for (years)</label><input type="number" name="baseline[years]" value="{{ $d['retention']['baseline']['years'] }}" min="0" max="100" style="max-width:120px"><div class="help">0 = no baseline policy.</div></div>
            <label style="font-weight:400;display:flex;gap:8px;align-items:center"><input type="checkbox" name="baseline[delete]" value="1" @checked($d['retention']['baseline']['delete'])> Delete at the end of the baseline period</label>
            <div class="field"><label>Teams chats and channels (years)</label><input type="number" name="teams[years]" value="{{ $d['retention']['teams']['years'] }}" min="0" max="100" style="max-width:120px">
                @unless($d['retention']['teams']['include'])<div class="help">Not applied (Teams not in scope or not licensed).</div>@endunless</div>
        </div>
        <table class="design-table" style="margin-top:8px">
            <thead><tr><th>Use</th><th>Retention label</th><th>Years</th><th>At the end</th><th>Record</th><th>Applies to</th></tr></thead>
            <tbody>
            @foreach($d['retention']['labels'] as $i => $l)
                <tr>
                    <td><input type="checkbox" name="retention[{{ $i }}][include]" value="1" @checked($l['include'])></td>
                    <td><input type="text" name="retention[{{ $i }}][name]" value="{{ $l['name'] }}" maxlength="64"></td>
                    <td><input type="number" name="retention[{{ $i }}][years]" value="{{ $l['years'] }}" min="1" max="100" style="max-width:90px"></td>
                    <td><select name="retention[{{ $i }}][action]">
                        <option value="delete" @selected($l['action'] === 'delete')>Delete</option>
                        <option value="startDispositionReview" @selected($l['action'] === 'startDispositionReview') @disabled(($caps['records'] ?? null) === false)>Disposition review</option>
                        <option value="none" @selected($l['action'] === 'none')>Keep (do nothing)</option>
                    </select></td>
                    <td>{{ $l['record'] ? 'Yes (locked)' : 'No' }}</td>
                    <td><small class="muted">{{ $l['description'] }}</small></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-title">Insider risk management</div>
        @unless($d['irm']['licensed'])<p class="muted">Not licensed in this tenant. Policies are listed for planning and stay off.</p>@endunless
        @unless($d['irm']['privacy_approved'])<div class="alert alert-warn">Privacy approval (legal, HR, works council) is not recorded in the requirements. Policies must not be switched on until it is.</div>@endunless
        @forelse($d['irm']['policies'] as $i => $p)
            <label class="tpl-head"><input type="checkbox" name="irm[{{ $i }}][include]" value="1" @checked($p['include']) @disabled(! $d['irm']['licensed'])>
                <span><strong>{{ $p['template'] }}</strong><br><small class="muted">{{ $p['purpose'] }}</small></span></label>
        @empty
            <p class="muted">No insider risk concerns selected.</p>
        @endforelse
        <p class="help">Usernames are pseudonymised for analysts by default. HR connector: {{ $d['irm']['hr_connector'] ? 'available' : 'not available (account deletion is used as the departure signal)' }}.</p>
    </div>

    <div class="card">
        <div class="card-title">Pilot group</div>
        <div class="grid grid-3">
            <div class="field"><label>Group name</label><input type="text" name="pilot[group_name]" value="{{ $d['pilot']['group_name'] }}" maxlength="64"></div>
            <div class="field"><label>Mail nickname</label><input type="text" name="pilot[mail_nickname]" value="{{ $d['pilot']['mail_nickname'] }}" maxlength="40"><div class="help">{{ $d['pilot']['mail_nickname'] }}@{{ $project->tenant->domain }}</div></div>
            <div class="field"><label>Members</label><div>{{ count($d['pilot']['members']) }} chosen in the requirements · {{ $d['pilot']['weeks'] }} weeks</div></div>
        </div>
    </div>

    @if($can)
        <div class="sticky-actions"><span style="flex:1"></span><button class="btn btn-primary">Save design changes</button></div>
    @endif
    </fieldset>
</form>

@can('purview.approve')
    @unless($project->isApproved())
        <div class="card" style="border-color:var(--accent);margin-top:16px">
            <div class="card-title">Approve the design</div>
            <p class="muted" style="margin-top:0">Approval is recorded with your name and a hash of this exact design. Implementation scripts are generated only from an approved design.</p>
            <form method="post" action="{{ route('purview.approve', $project) }}">
                @csrf
                <div class="field"><label>Note</label><input type="text" name="note" maxlength="1000" placeholder="For example: approved in steering meeting 12 Nov, retention confirmed by legal"></div>
                <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="confirm" value="1" required> I reviewed the design document with the compliance owner ({{ $project->requirements['owner_name'] ?: 'not named yet' }}).</label>
                <button class="btn btn-primary">Approve design</button>
            </form>
        </div>
    @endunless
@endcan
@endsection
