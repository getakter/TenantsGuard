@extends('layouts.app')
@section('title', 'Guests: '.$tenant->name)

@section('content')
@php
    $guests = $data['guests'];
    $domains = collect($guests)->countBy('domain')->sortDesc();
    $pending = count(array_filter($guests, fn ($g) => $g['state'] === 'PendingAcceptance'));
    $stale = count(array_filter($guests, fn ($g) => $g['stale']));
@endphp
@include('identity._head', ['title' => 'Guests', 'subtitle' => count($guests).' external guest accounts in '.$tenant->name.'. Guests keep access until someone removes them.', 'readAt' => $data['read_at']])

@error('guests')<div class="alert alert-err">{{ $message }}</div>@enderror

<div class="dash-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <div class="tile"><span class="ti ti-sky">@include('partials.icon', ['name' => 'guest', 'size' => 18])</span><span><small class="muted">Guests</small><b>{{ count($guests) }}</b></span></div>
    <div class="tile"><span class="ti ti-amber">@include('partials.icon', ['name' => 'pulse', 'size' => 18])</span><span><small class="muted">Invitation not accepted</small><b>{{ $pending }}</b></span></div>
    <div class="tile"><span class="ti ti-red">@include('partials.icon', ['name' => 'x', 'size' => 18])</span><span><small class="muted">Stale (no sign-in for {{ \App\Services\Lifecycle\IdentityDirectory::STALE_DAYS }}+ days)</small><b>{{ $stale }}</b></span></div>
</div>

@if($domains->isNotEmpty())
    <div class="card" style="margin-bottom:18px"><div class="card-title">Guests by organisation</div>
        <div class="pills">@foreach($domains as $d => $n)<span class="chip">{{ $d ?: 'unknown' }}: {{ $n }}</span>@endforeach</div></div>
@endif

<div class="card">
    <form method="post" action="{{ route('identity.guests.remove', $tenant) }}" id="gform">
        @csrf
        <div class="test-toolbar">
            <input type="text" id="gq" placeholder="Search name, email or organisation…" oninput="fg()">
            <select id="gf" onchange="fg()"><option value="">All guests</option><option value="stale">Stale</option><option value="pending">Invitation not accepted</option><option value="accepted">Accepted</option><option value="disabled">Sign-in blocked</option></select>
            <span class="muted" id="gc"></span>
        </div>
        <div class="table-wrap">
            <table id="gt">
                <thead><tr><th style="width:28px"></th><th>Guest</th><th>Organisation</th><th>Invitation</th><th>Invited</th><th>Last sign-in</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($guests as $g)
                    <tr data-q="{{ strtolower($g['name'].' '.$g['email'].' '.$g['domain']) }}" data-tags="{{ implode(' ', array_filter([$g['stale'] ? 'stale' : null, $g['state'] === 'PendingAcceptance' ? 'pending' : null, $g['state'] === 'Accepted' ? 'accepted' : null, ! $g['enabled'] ? 'disabled' : null])) }}">
                        <td><input type="checkbox" name="guests[]" value="{{ $g['id'] }}" onchange="gsel()"></td>
                        <td><strong>{{ $g['name'] }}</strong><br><small class="muted">{{ $g['email'] }}</small></td>
                        <td>{{ $g['domain'] }}</td>
                        <td>@if($g['state'] === 'Accepted')<span class="badge s-pass">Accepted</span>@elseif($g['state'] === 'PendingAcceptance')<span class="badge s-manual">Pending</span>@else<span class="badge s-na">{{ $g['state'] }}</span>@endif</td>
                        <td class="muted">{{ $g['created'] ? \Carbon\Carbon::parse($g['created'])->toFormattedDateString() : '' }}</td>
                        <td>@if($g['last_signin']){{ \Carbon\Carbon::parse($g['last_signin'])->diffForHumans() }}@else<span class="muted">Never</span>@endif @if($g['stale'])<br><span class="badge s-fail">Stale</span>@endif</td>
                        <td>@if($g['enabled'])<span class="badge s-pass">Enabled</span>@else<span class="badge s-na">Blocked</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No guests.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @can('staff.offboard')
            <div class="sel-bar" style="flex-wrap:wrap">
                <span class="muted" id="gs">0 selected</span>
                <input type="text" name="confirm" id="gconfirm" placeholder="Type DELETE 0" autocomplete="off" style="width:170px" required>
                <button class="btn btn-solid-danger" data-confirm="Remove the selected guest accounts? Their access to Teams, sites and files ends now. They can be restored for 30 days.">Remove selected guests</button>
                <small class="muted">Removed guests can be restored from Deleted users in Entra ID for 30 days. Review stale guests with their sponsor first.</small>
            </div>
        @endcan
    </form>
</div>
@endsection

@push('scripts')
<script>
function fg() {
  const q = document.getElementById('gq').value.toLowerCase(), f = document.getElementById('gf').value;
  let n = 0;
  document.querySelectorAll('#gt tbody tr[data-q]').forEach((r) => { const ok = (!q || r.dataset.q.includes(q)) && (!f || r.dataset.tags.split(' ').includes(f)); r.hidden = !ok; if (ok) n++; });
  document.getElementById('gc').textContent = n + ' shown';
}
function gsel() { const n = document.querySelectorAll('#gt input:checked').length; document.getElementById('gs').textContent = n + ' selected'; const c = document.getElementById('gconfirm'); if (c) c.placeholder = 'Type DELETE ' + n; }
fg();
</script>
@endpush
