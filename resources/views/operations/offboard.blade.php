@extends('layouts.app')
@section('title', 'Offboard: '.$tenant->name)

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])
@php
    $chosenIds = array_map(fn ($s) => $s['snapshot']['user']['id'], $selected);
    $ready = array_values(array_filter($selected, fn ($s) => ! $s['guard']));
    $single = count($ready) === 1 ? $ready[0] : null;
    $owned = collect($ready)->flatMap(fn ($s) => $s['snapshot']['owned'] ?? []);
@endphp

<div class="page-head">
    <div>
        <h1>Offboard users</h1>
        <p>Locks the account out first, keeps mail and files, then removes access, devices and licences in the right order. Run it now or schedule it for the last working day. Every step is recorded.</p>
    </div>
    <a class="btn" href="{{ route('operations.tenant', $tenant) }}">Cancel</a>
</div>

@error('offboard')<div class="alert alert-err">{{ $message }}</div>@enderror

{{-- ================= Step 1: who ================= --}}
<div class="card">
    <h2>1 · Who is leaving?</h2>
    <p class="muted" style="margin-top:0">Tick one person, or several for a team or site closure (up to 25).</p>
    <form method="get" action="{{ route('operations.offboard', $tenant) }}" id="pickform">
        <div class="filters"><input type="text" id="uq" placeholder="Search by name, email, title or department…" oninput="const q=this.value.toLowerCase();document.querySelectorAll('.u-row').forEach(r=>r.hidden=!r.dataset.q.includes(q))"></div>
        <div class="table-wrap" style="max-height:300px;overflow:auto">
            <table>
                <tbody>
                @foreach($users as $u)
                    <tr class="u-row" data-q="{{ strtolower($u['displayName'].' '.$u['userPrincipalName'].' '.($u['jobTitle'] ?? '').' '.($u['department'] ?? '')) }}"
                        @if(in_array($u['id'], $chosenIds, true)) style="background:var(--accent-soft)" @endif>
                        <td style="width:30px"><input type="checkbox" name="users[]" value="{{ $u['id'] }}" @checked(in_array($u['id'], $chosenIds, true)) onchange="pickCount()"></td>
                        <td><strong>{{ $u['displayName'] }}</strong> @unless($u['accountEnabled'] ?? true)<span class="badge s-na">Disabled</span>@endunless<br><small class="muted">{{ $u['userPrincipalName'] }}</small></td>
                        <td class="muted">{{ $u['jobTitle'] ?? '' }} {{ ! empty($u['department']) ? '· '.$u['department'] : '' }}</td>
                        <td class="muted">{{ count($u['assignedLicenses'] ?? []) }} licence(s)</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="sel-bar">
            <span id="pickcount" class="muted">{{ count($chosenIds) }} selected</span>
            <button class="btn btn-primary">Review selected</button>
        </div>
    </form>
</div>

@if($selected)
    {{-- ================= Step 2: what they have ================= --}}
    <div class="card">
        <h2>2 · Current access</h2>
        <p class="muted" style="margin-top:0">Captured before any change and kept with each offboarding record, as audit evidence and as a reference if the person returns.</p>
        @foreach($selected as $s)
            @php
                $snap = $s['snapshot'];
                $u = $snap['user'];
                $fwd = array_filter($snap['rules'] ?? [], fn ($r) => $r['forwards']);
            @endphp
            <div class="leaver {{ $s['guard'] ? 'blocked' : '' }}">
                <strong>{{ $u['displayName'] }}</strong> <small class="muted">{{ $u['userPrincipalName'] }}</small>
                @if($s['manager'])<small class="muted"> · Manager: {{ $s['manager']['displayName'] }}</small>@endif
                @if($s['guard'])
                    <div class="error-text">{{ $s['guard'] }} This person is left out.</div>
                @elseif(count($selected) === 1)
                    <div class="grid grid-3" style="margin-top:10px">
                        <div><div class="label-sm">Licences ({{ count($snap['licences']) }})</div>@forelse($snap['licences'] as $l)<div>{{ $l['name'] }}</div>@empty<span class="muted">None</span>@endforelse</div>
                        <div><div class="label-sm">Groups and Teams ({{ count($snap['groups']) }})</div>@forelse($snap['groups'] as $g)<div>{{ $g['name'] }}@if($g['dynamic']) <small class="muted">(dynamic)</small>@endif</div>@empty<span class="muted">None</span>@endforelse</div>
                        <div><div class="label-sm">Owner of ({{ count($snap['owned'] ?? []) }})</div>@forelse($snap['owned'] ?? [] as $g)<div>{{ $g['name'] }}</div>@empty<span class="muted">Nothing</span>@endforelse</div>
                        <div><div class="label-sm">Admin roles ({{ count($snap['roles']) }})</div>@forelse($snap['roles'] as $r)<div><span class="badge sev-high">{{ $r['name'] }}</span></div>@empty<span class="muted">None</span>@endforelse</div>
                        <div><div class="label-sm">MFA methods ({{ count($snap['methods']) }})</div>@forelse($snap['methods'] as $m)<div>{{ \Illuminate\Support\Str::of($m['type'])->afterLast('.')->replace('AuthenticationMethod', '')->headline() }}</div>@empty<span class="muted">None</span>@endforelse</div>
                        <div><div class="label-sm">Inbox rules ({{ count($snap['rules'] ?? []) }})</div>@forelse($snap['rules'] ?? [] as $r)<div>{{ $r['name'] }}@if($r['forwards']) <span class="badge sev-high">Forwards mail</span>@endif</div>@empty<span class="muted">None</span>@endforelse</div>
                    </div>
                @else
                    <div class="leaver-facts">
                        <span>{{ count($snap['licences']) }} licence(s)</span>
                        <span>{{ count($snap['groups']) }} group(s)</span>
                        <span>{{ count($snap['devices']) }} device(s)</span>
                        @if($snap['owned'] ?? [])<span>Owns {{ count($snap['owned']) }}: {{ implode(', ', array_column($snap['owned'], 'name')) }}</span>@endif
                        @if($snap['roles'])<span class="warn">Admin: {{ implode(', ', array_column($snap['roles'], 'name')) }}</span>@endif
                        @if($fwd)<span class="warn">{{ count($fwd) }} forwarding rule(s)</span>@endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if($ready)
    <form method="post" action="{{ route('operations.offboard.run', $tenant) }}" id="offb">
        @csrf
        @foreach($ready as $s)<input type="hidden" name="users[]" value="{{ $s['snapshot']['user']['id'] }}">@endforeach

        {{-- ================= Step 3: steps ================= --}}
        <div class="card">
            <h2>3 · Choose the steps</h2>
            <p class="muted" style="margin-top:0">
                @if($defaults['saved'])Pre-filled with the saved defaults for {{ $tenant->name }}.@else Defaults follow common MSP and Microsoft practice.@endif
                Untick anything that does not apply. Checklist steps become tracked to-do items.
            </p>
            @php
                $sections = [
                    'Immediate lock-out' => ['block_signin', 'revoke_sessions', 'reset_password', 'remove_mfa'],
                    'Mailbox and files' => ['auto_reply', 'remove_rules', 'convert_mailbox', 'forward_mail', 'hide_gal', 'onedrive'],
                    'Access' => ['remove_roles', 'ownership', 'remove_groups', 'third_party'],
                    'Devices' => ['devices', 'collect_hardware'],
                    'Licences and account' => ['licences', 'delete_account'],
                ];
                $checked = old('steps', $defaults['steps']);
            @endphp
            @foreach($sections as $title => $keys)
                <h3 class="cat-head">{{ $title }}</h3>
                @foreach($keys as $key)
                    @php
                        [$label, $desc, $kind] = $steps[$key];
                    @endphp
                    <div class="off-step">
                        <label style="font-weight:400;display:flex;gap:10px;align-items:flex-start;margin:0">
                            <input type="checkbox" name="steps[]" value="{{ $key }}" @checked(in_array($key, $checked, true))>
                            <span><strong>{{ $label }}</strong> <span class="chip {{ $kind === 'auto' ? 'chip-platform' : '' }}">{{ $kind === 'auto' ? 'Automatic' : 'Checklist' }}</span><br><small class="muted">{{ $desc }}</small></span>
                        </label>
                        @if($key === 'auto_reply')
                            <textarea name="auto_reply_message" rows="3" style="margin:6px 0 0 26px;width:calc(100% - 26px)">{{ old('auto_reply_message', $defaults['auto_reply_message']) }}</textarea>
                            <div class="help" style="margin-left:26px">{name}, {manager} and {company} are filled in for each person. Without a manager the reply says "our main office".</div>
                        @elseif($key === 'ownership')
                            <div style="margin:6px 0 0 26px">
                                <small class="muted">New owner:</small>
                                <select name="new_owner_id" style="width:auto;display:inline-block;min-width:260px">
                                    <option value="">Nobody (just remove the leaver)</option>
                                    @foreach($users as $o)
                                        @if(! in_array($o['id'], $chosenIds, true) && ($o['accountEnabled'] ?? true) && ($o['userType'] ?? 'Member') === 'Member')
                                            <option value="{{ $o['id'] }}" @selected(old('new_owner_id', $single['manager']['id'] ?? null) === $o['id'])>{{ $o['displayName'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('new_owner_id')<div class="error-text">{{ $message }}</div>@enderror
                                @if($owned->isNotEmpty())
                                    <div class="help">Affects {{ $owned->pluck('name')->unique()->implode(', ') }}. Microsoft 365 groups and Teams need at least one owner, so choose a new owner when the leaver is the only one.</div>
                                @else
                                    <div class="help">The selected {{ count($ready) === 1 ? 'person owns' : 'people own' }} no groups or Teams.</div>
                                @endif
                            </div>
                        @elseif($key === 'devices')
                            <div style="margin:6px 0 0 26px">
                                @if($single)
                                    @forelse($single['snapshot']['devices'] as $d)
                                        <div style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
                                            <span style="min-width:220px">💻 {{ $d['name'] }} <small class="muted">{{ $d['os'] }} · {{ $d['owner'] }}</small></span>
                                            <select name="devices[{{ $d['id'] }}]" style="width:auto">
                                                <option value="retire" @selected($d['owner'] !== 'company')>Retire (remove company data and apps)</option>
                                                <option value="wipe" @selected($d['owner'] === 'company')>Wipe (factory reset, corporate devices)</option>
                                                <option value="none">Leave as is</option>
                                            </select>
                                        </div>
                                    @empty
                                        <small class="muted">No Intune-managed devices for this user.</small>
                                    @endforelse
                                @else
                                    For every device of the selected people:
                                    <select name="device_action" style="width:auto;display:inline-block">
                                        @foreach(['retire' => 'Retire (remove company data and apps)', 'wipe' => 'Wipe (factory reset)', 'none' => 'Leave as is'] as $v => $l)
                                            <option value="{{ $v }}" @selected(old('device_action', $defaults['device_action']) === $v)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>
                        @elseif($key === 'licences')
                            <div style="margin:6px 0 0 26px">Remove
                                <select name="licence_delay_days" style="width:auto;display:inline-block">
                                    @foreach([0 => 'immediately', 7 => 'after 7 days', 14 => 'after 14 days', 30 => 'after 30 days (recommended: convert the mailbox first)'] as $v => $l)
                                        <option value="{{ $v }}" @selected((int) old('licence_delay_days', $defaults['licence_delay_days']) === $v)>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif($key === 'delete_account')
                            <div style="margin:6px 0 0 26px">Delete after
                                <select name="delete_after_days" style="width:auto;display:inline-block">@foreach([30, 60, 90, 180] as $d)<option value="{{ $d }}" @selected((int) old('delete_after_days', $defaults['delete_after_days']) === $d)>{{ $d }} days</option>@endforeach</select>
                                <small class="muted">Keep accounts until mail and files are handed over and any legal hold is confirmed.</small>
                            </div>
                        @elseif($key === 'forward_mail' && $single)
                            <div style="margin:6px 0 0 26px"><small class="muted">Delegate to:</small> <input type="text" name="manager_upn" value="{{ old('manager_upn', $single['manager']['userPrincipalName'] ?? '') }}" placeholder="manager@domain" style="width:auto;display:inline-block;min-width:260px"></div>
                        @elseif($key === 'forward_mail')
                            <div class="help" style="margin-left:26px">Each person's mail is delegated to their own manager.</div>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- ================= Step 4: when ================= --}}
        <div class="card">
            <h2>4 · When</h2>
            <label class="when-opt"><input type="radio" name="when" value="now" @checked(old('when', 'now') === 'now')>
                <span><strong>Now</strong><br><small class="muted">For dismissals, or when the person has already gone.</small></span></label>
            <label class="when-opt"><input type="radio" name="when" value="later" @checked(old('when') === 'later')>
                <span><strong>Schedule</strong><br><small class="muted">Runs automatically at the chosen time, for example the end of the last working day. Access stays unchanged until then and you can cancel it from Lifecycle activity.</small>
                <br><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', now()->addDay()->setTime(17, 30)->format('Y-m-d\TH:i')) }}" min="{{ now()->format('Y-m-d\TH:i') }}" style="width:auto;margin-top:6px"></span></label>
            @error('scheduled_at')<div class="error-text">{{ $message }}</div>@enderror
        </div>

        {{-- ================= Step 5: confirm ================= --}}
        @php
            $confirmWord = $single ? $single['snapshot']['user']['userPrincipalName'] : 'OFFBOARD '.count($ready);
        @endphp
        <div class="card" style="border-color:var(--fail)">
            <h2>5 · Confirm</h2>
            <div class="grid grid-3">
                <div class="field"><label>Ticket / reference</label><input type="text" name="ticket" value="{{ old('ticket') }}" placeholder="PSA ticket #"></div>
                <div class="field" style="grid-column:span 2"><label>Reason / notes</label><input type="text" name="reason" value="{{ old('reason') }}" placeholder="Resignation effective Friday; HR approval in ticket"></div>
            </div>
            <label style="font-weight:400;display:flex;gap:8px;align-items:center;margin-bottom:12px">
                <input type="checkbox" name="save_defaults" value="1"> Save these steps and options as the default for {{ $tenant->name }}
            </label>
            <div class="field">
                <label>Type <code>{{ $confirmWord }}</code> to confirm</label>
                <input type="text" name="confirm" autocomplete="off" required style="max-width:420px">
                @error('confirm')<div class="error-text">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-danger">{{ $single ? 'Offboard '.$single['snapshot']['user']['displayName'] : 'Offboard '.count($ready).' people' }}</button>
        </div>
    </form>
    @endif
@endif

<script>
function pickCount() {
    document.getElementById('pickcount').textContent = document.querySelectorAll('#pickform input[name="users[]"]:checked').length + ' selected';
}
</script>
@endsection
