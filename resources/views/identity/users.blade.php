@extends('layouts.app')
@section('title', 'Users: '.$tenant->name)

@section('content')
@php
    $users = $data['users'];
    $count = fn ($f) => count(array_filter($users, $f));
@endphp
@include('identity._head', ['title' => 'Users', 'subtitle' => count($users).' member accounts in '.$tenant->name.'. Click a person for everything they have access to.', 'readAt' => $data['read_at']])

@foreach($data['errors'] as $e)<div class="alert alert-warn">{{ $e }}</div>@endforeach

<div class="tiles" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:18px">
    @foreach([['Enabled', $count(fn ($u) => $u['enabled']), 'user', 'ti-green', 'enabled'], ['Disabled', $count(fn ($u) => ! $u['enabled']), 'x', 'ti-red', 'disabled'],
              ['Licensed', $count(fn ($u) => $u['licences']), 'licence', 'ti-sky', 'licensed'], ['No MFA', $count(fn ($u) => $u['mfa'] === false && $u['enabled']), 'key', 'ti-amber', 'nomfa'],
              ['Admins', $count(fn ($u) => $u['admin']), 'shield', 'ti-purple', 'admin'], ['Stale (90+ days)', $count(fn ($u) => $u['stale'] && $u['enabled']), 'pulse', 'ti-orange', 'stale']] as [$l, $n, $i, $c, $f])
        <a class="tile" href="#" onclick="document.getElementById('uf').value='{{ $f }}';fu();return false;" style="color:inherit;text-decoration:none"><span class="ti {{ $c }}">@include('partials.icon', ['name' => $i, 'size' => 18])</span><span><small class="muted">{{ $l }}</small><b>{{ $n }}</b></span></a>
    @endforeach
</div>

<div class="card">
    <form method="get" action="{{ route('operations.offboard', $tenant) }}" id="uform">
        <div class="test-toolbar">
            <input type="text" id="uq" placeholder="Search name, UPN, title, department, licence…" oninput="fu()">
            <select id="uf" onchange="fu()">
                <option value="">All users</option><option value="enabled">Enabled</option><option value="disabled">Disabled (sign-in blocked)</option>
                <option value="licensed">Licensed</option><option value="unlicensed">Unlicensed</option><option value="nomfa">Enabled without MFA</option>
                <option value="admin">Administrators</option><option value="stale">Stale: no sign-in for 90+ days</option><option value="synced">Synced from on-premises</option>
            </select>
            <span class="muted" id="uc"></span>
            <span style="flex:1"></span>
            @can('staff.offboard')<button class="btn btn-sm btn-danger" id="offb" disabled>Offboard selected</button>@endcan
            <button type="button" class="btn btn-sm" onclick="exportCsv()">@include('partials.icon', ['name' => 'download', 'size' => 14]) Export CSV</button>
        </div>
        <div class="table-wrap">
            <table class="test-table" id="ut">
                <thead><tr><th style="width:28px"></th><th>User</th><th>Job</th><th>Licences</th><th>MFA</th><th>Last sign-in</th><th>Created</th><th>Status</th></tr></thead>
                <tbody>
                @foreach($users as $u)
                    @php
                        $tags = implode(' ', array_filter([$u['enabled'] ? 'enabled' : 'disabled', $u['licences'] ? 'licensed' : 'unlicensed', $u['mfa'] === false && $u['enabled'] ? 'nomfa' : null,
                            $u['admin'] ? 'admin' : null, $u['stale'] && $u['enabled'] ? 'stale' : null, $u['synced'] ? 'synced' : null]));
                        $csv = json_encode([$u['name'], $u['upn'], $u['title'], $u['department'], implode('; ', $u['licences']), $u['mfa'] === null ? '' : ($u['mfa'] ? 'Yes' : 'No'), $u['last_signin'], $u['created'], $u['enabled'] ? 'Enabled' : 'Disabled']);
                    @endphp
                    <tr data-q="{{ strtolower($u['name'].' '.$u['upn'].' '.$u['title'].' '.$u['department'].' '.implode(' ', $u['licences'])) }}" data-tags="{{ $tags }}"
                        data-csv="{{ $csv }}"
                        onclick="if(event.target.tagName!=='INPUT')location.href='{{ route('operations.user', [$tenant, $u['id']]) }}'">
                        <td><input type="checkbox" name="users[]" value="{{ $u['id'] }}" onchange="sel()"></td>
                        <td><strong>{{ $u['name'] }}</strong> @if($u['admin'])<span class="badge sev-high">Admin</span>@endif @if($u['synced'])<span class="chip">Synced</span>@endif<br><small class="muted">{{ $u['upn'] }}</small></td>
                        <td>{{ $u['title'] }}<br><small class="muted">{{ $u['department'] }}</small></td>
                        <td>@forelse($u['licences'] as $l)<div><small>{{ $l }}</small></div>@empty<span class="muted">–</span>@endforelse</td>
                        <td>@if($u['mfa'] === null)<span class="muted">?</span>@elseif($u['mfa'])<span class="badge s-pass">Registered</span>@else<span class="badge s-fail">None</span>@endif</td>
                        <td>@if($u['last_signin']){{ \Carbon\Carbon::parse($u['last_signin'])->diffForHumans() }}@else<span class="muted">Never</span>@endif @if($u['stale'] && $u['enabled'])<br><span class="badge s-manual">Stale</span>@endif</td>
                        <td class="muted">{{ $u['created'] ? \Carbon\Carbon::parse($u['created'])->toFormattedDateString() : '' }}</td>
                        <td>@if($u['enabled'])<span class="badge s-pass">Enabled</span>@else<span class="badge s-na">Blocked</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function rows() { return [...document.querySelectorAll('#ut tbody tr')]; }
function fu() {
  const q = document.getElementById('uq').value.toLowerCase(), f = document.getElementById('uf').value;
  let n = 0;
  rows().forEach((r) => { const ok = (!q || r.dataset.q.includes(q)) && (!f || r.dataset.tags.split(' ').includes(f)); r.hidden = !ok; if (ok) n++; });
  document.getElementById('uc').textContent = n + ' of ' + rows().length;
}
function sel() { const b = document.getElementById('offb'); if (b) { const n = document.querySelectorAll('#ut input:checked').length; b.disabled = !n; b.textContent = n ? 'Offboard ' + n + ' selected' : 'Offboard selected'; } }
function exportCsv() {
  const head = ['Name', 'UPN', 'Job title', 'Department', 'Licences', 'MFA registered', 'Last sign-in', 'Created', 'Status'];
  const data = rows().filter((r) => !r.hidden).map((r) => JSON.parse(r.dataset.csv));
  const csv = [head, ...data].map((r) => r.map((c) => { c = String(c ?? ''); if (/^[=+\-@]/.test(c)) c = "'" + c; return '"' + c.replace(/"/g, '""') + '"'; }).join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = @json(\Illuminate\Support\Str::slug($tenant->name).'-users.csv'); a.click();
}
fu();
</script>
@endpush
