@extends('layouts.app')
@section('title', 'Copilot licences and usage: '.$project->tenant->name)
@use('App\Services\Copilot\Catalog')

@section('content')
@include('copilot._head')
@php
    $a = $project->assessment;
    $usage = collect($project->usage['rows'] ?? [])->keyBy(fn ($r) => strtolower($r['upn']));
    $can = auth()->user()->can('copilot.manage');
    $stageNote = ! $project->signoff('readiness') ? 'Locked: sign off "Ready for pilot" first.' : (! $project->signoff('pilot') ? 'Pilot phase: only pilot members can be given Copilot.' : 'Rollout: any eligible user can be given Copilot.');
@endphp

@if(! $a)
    <div class="card empty-card">Run the readiness assessment first.</div>
@else
@foreach(['licences', 'groups', 'usage', 'script'] as $e)@error($e)<div class="alert alert-err">{{ $message }}</div>@enderror @endforeach

{{-- ================= licence assignment ================= --}}
<div class="card" id="licences">
    <div class="card-title">@include('partials.icon', ['name' => 'licence']) Licence assignment
        <small class="muted" style="font-weight:400">{{ $a['copilot']['used'] }} of {{ $a['copilot']['total'] }} seats assigned · {{ $a['copilot']['free'] }} free · {{ $stageNote }}</small></div>
    <form method="post" action="{{ route('copilot.licences', $project) }}" id="licform">
        @csrf
        <div class="test-toolbar">
            <input type="text" placeholder="Search name, UPN or department…" oninput="fl()" id="lq">
            <select id="lf" onchange="fl()">
                <option value="">All users</option><option value="pilot">Pilot members</option><option value="copilot">Have Copilot</option>
                <option value="ready">Eligible, no Copilot yet</option><option value="inactive">Have Copilot, inactive 30 days</option><option value="gaps">Missing prerequisites</option>
            </select>
            <span class="muted" id="lc"></span>
        </div>
        <div class="table-wrap" style="max-height:460px;overflow:auto">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.lu:not([hidden]) input').forEach(i=>i.checked=this.checked);cnt()"></th><th>User</th><th>Base licence</th><th>Mailbox</th><th>OneDrive</th><th>Teams</th><th>Office apps</th><th>Copilot</th><th>Last used</th></tr></thead>
                <tbody>
                @foreach(collect($a['users'])->sortBy('name') as $u)
                    @php
                        $use = $usage[strtolower($u['upn'])] ?? null;
                        $inactive = $u['copilot'] && (! $use || ! $use['last'] || \Carbon\Carbon::parse($use['last'])->lt(now()->subDays(30)));
                        $gaps = ! $u['eligible'] || in_array(false, $u['services'], true) || ! $u['usage_location'];
                        $tags = implode(' ', array_filter([$u['pilot'] ? 'pilot' : null, $u['copilot'] ? 'copilot' : null, ! $u['copilot'] && $u['eligible'] ? 'ready' : null, $inactive ? 'inactive' : null, $gaps ? 'gaps' : null]));
                    @endphp
                    <tr class="lu" data-q="{{ strtolower($u['name'].' '.$u['upn'].' '.$u['department']) }}" data-tags="{{ $tags }}">
                        <td><input type="checkbox" name="users[]" value="{{ $u['id'] }}" onchange="cnt()"></td>
                        <td><strong>{{ $u['name'] }}</strong> @if($u['pilot'])<span class="chip">Pilot</span>@endif<br><small class="muted">{{ $u['upn'] }}{{ $u['department'] ? ' · '.$u['department'] : '' }}</small></td>
                        <td>{!! $u['eligible'] ? '<span class="badge s-pass">Eligible</span>' : '<span class="badge s-fail">Not eligible</span>' !!}@unless($u['usage_location'])<br><small class="error-text">no usage location</small>@endunless</td>
                        @foreach(['mailbox', 'onedrive', 'teams', 'apps'] as $svc)<td>{!! $u['services'][$svc] ? '✓' : '<span class="error-text">✗</span>' !!}</td>@endforeach
                        <td>{!! $u['copilot'] ? '<span class="badge s-pass">Assigned</span>' : '–' !!}</td>
                        <td>@if($use){{ $use['last'] ? \Carbon\Carbon::parse($use['last'])->diffForHumans() : 'never' }}<br><small class="muted">{{ implode(', ', $use['apps']) }}</small>@elseif($u['copilot'])<span class="muted">no data</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($can)
            <div class="sel-bar" style="flex-wrap:wrap">
                <span id="lsel" class="muted">0 selected</span>
                <select name="action" id="laction" onchange="cnt()" style="width:auto"><option value="assign">Assign Copilot</option><option value="remove">Remove Copilot (reclaim)</option></select>
                <input type="text" name="confirm" id="lconfirm" placeholder="Type ASSIGN 0" autocomplete="off" style="width:180px" required>
                <button class="btn btn-primary" @disabled(! $project->signoff('readiness'))>Apply</button>
                <small class="muted">Seats, eligible base licences and usage location are checked before anything is assigned.</small>
            </div>
        @endif
    </form>
</div>

<div class="dash-grid-2" style="margin-top:20px">
    {{-- ================= public groups ================= --}}
    <div class="card" id="groups">
        <div class="card-title">@include('partials.icon', ['name' => 'group']) Public Teams and groups ({{ count($a['public_groups']) }})</div>
        <p class="muted" style="margin-top:0">Everyone in the organisation can read files in public groups, so Copilot can surface them to anyone. Agree with each owner, then make the group private. Members keep access; non-members lose it.</p>
        @if($a['public_groups'])
            <form method="post" action="{{ route('copilot.groups', $project) }}" data-confirm="Make the selected groups private? People who are not members will lose access to their files.">
                @csrf
                @foreach($a['public_groups'] as $g)
                    <label style="font-weight:400;display:flex;gap:8px;margin-bottom:6px"><input type="checkbox" name="groups[]" value="{{ $g['id'] }}"> {{ $g['name'] }} @if($g['team'])<span class="chip">Team</span>@endif</label>
                @endforeach
                @if($can)<button class="btn btn-sm btn-primary" style="margin-top:8px">Make selected private</button>@endif
            </form>
        @else
            <p class="badge s-pass">No public groups.</p>
        @endif
    </div>

    {{-- ================= usage ================= --}}
    <div class="card" id="usage">
        <div class="card-title">@include('partials.icon', ['name' => 'pulse']) Usage</div>
        @if($project->usage)
            @php
                $u = $project->usage;
                $target = $project->requirements['target_active'] ?? 70;
            @endphp
            <div class="lic-bar"><div class="row"><span>Active in the last 7 days</span><span><b>{{ $u['active7'] }}</b> of {{ $u['licensed'] }} ({{ $u['active7_pct'] }}%, target {{ $target }}%)</span></div>
                <div class="bar"><span style="width:{{ $u['active7_pct'] }}%;background:{{ $u['active7_pct'] >= $target ? 'var(--pass)' : 'var(--warn)' }}"></span></div></div>
            <div class="cap-row"><span>Active in the last 30 days</span><b>{{ $u['active30'] }}</b></div>
            <div class="cap-row"><span>Inactive for 30 days (coach, then reclaim)</span><b>{{ count($u['inactive']) }}</b></div>
            <p class="help">Updated {{ $project->usage_at->diffForHumans() }}. Microsoft's reports lag by about two days.</p>
        @else
            <p class="muted">No usage pulled yet. Usage appears once users have Copilot.</p>
        @endif
        @if($can)<form method="post" action="{{ route('copilot.usage', $project) }}" class="inline">@csrf <button class="btn btn-sm">@include('partials.icon', ['name' => 'refresh', 'size' => 14]) Refresh usage</button></form>@endif
    </div>
</div>

{{-- ================= scripts ================= --}}
<div class="card" id="scripts" style="margin-top:20px">
    <div class="card-title">@include('partials.icon', ['name' => 'terminal']) Admin scripts</div>
    <p class="muted" style="margin-top:0">For settings Microsoft Graph cannot change: oversharing reports, Restricted Content Discovery, Restricted SharePoint Search, default sharing links and the Teams meeting policy. Run them yourself with a SharePoint and Teams administrator account; preview with <code>-WhatIf</code>.</p>
    @foreach(['prepare' => 'Oversharing reports, sharing link default, Restricted Content Discovery'.(($project->requirements['data_approach'] ?? '') === 'strict' ? ', Restricted SharePoint Search' : '').', Teams meeting policy', 'rollout' => 'Turn off Restricted SharePoint Search after remediation', 'adopt' => 'Refresh the oversharing reports (quarterly)'] as $ph => $desc)
        <div class="cap-row" style="align-items:center"><span><strong>{{ Catalog::STAGES[$ph][0] }}</strong><br><small class="muted">{{ $desc }}</small></span>
            @if($can && $project->unlocked($ph))<a class="btn btn-sm btn-primary" href="{{ route('copilot.script', [$project, $ph]) }}">@include('partials.icon', ['name' => 'download', 'size' => 14]) Download</a>@else<span class="badge s-na">Locked</span>@endif</div>
    @endforeach
</div>
@endif
@endsection

@push('scripts')
<script>
function fl() {
  const q = document.getElementById('lq').value.toLowerCase(), f = document.getElementById('lf').value;
  let n = 0;
  document.querySelectorAll('.lu').forEach((r) => { const ok = (!q || r.dataset.q.includes(q)) && (!f || r.dataset.tags.split(' ').includes(f)); r.hidden = !ok; if (ok) n++; });
  document.getElementById('lc').textContent = n + ' shown';
}
function cnt() {
  const n = document.querySelectorAll('.lu input:checked').length, a = document.getElementById('laction');
  document.getElementById('lsel').textContent = n + ' selected';
  if (a) document.getElementById('lconfirm').placeholder = 'Type ' + a.value.toUpperCase() + ' ' + n;
}
if (document.getElementById('lq')) { fl(); cnt(); }
</script>
@endpush
