@extends('layouts.app')
@section('title', 'Groups: '.$tenant->name)

@section('content')
@php
    $groups = $data['groups'];
    $kinds = collect($groups)->countBy('kind');
    $ownerless = count(array_filter($groups, fn ($g) => $g['owners'] === 0 && in_array($g['kind'], ['Team', 'Microsoft 365'], true)));
    $public = count(array_filter($groups, fn ($g) => $g['visibility'] === 'Public'));
@endphp
@include('identity._head', ['title' => 'Groups', 'subtitle' => count($groups).' groups in '.$tenant->name.': Teams, Microsoft 365, security and distribution.', 'readAt' => $data['read_at']])

<div class="tiles" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:18px">
    @foreach([['Teams', $kinds['Team'] ?? 0, 'group', 'ti-purple'], ['Microsoft 365', $kinds['Microsoft 365'] ?? 0, 'apps', 'ti-sky'], ['Security', ($kinds['Security'] ?? 0) + ($kinds['Mail-enabled security'] ?? 0), 'shield', 'ti-green'],
              ['Distribution', $kinds['Distribution list'] ?? 0, 'file', 'ti-amber'], ['Public', $public, 'guest', 'ti-red'], ['No owner', $ownerless, 'x', 'ti-orange']] as [$l, $n, $i, $c])
        <div class="tile"><span class="ti {{ $c }}">@include('partials.icon', ['name' => $i, 'size' => 18])</span><span><small class="muted">{{ $l }}</small><b>{{ $n }}</b></span></div>
    @endforeach
</div>

<div class="card">
    <div class="test-toolbar">
        <input type="text" id="rq" placeholder="Search groups…" oninput="fr()">
        <select id="rf" onchange="fr()"><option value="">All types</option>@foreach($kinds->keys()->sort() as $k)<option>{{ $k }}</option>@endforeach<option value="__public">Public only</option><option value="__noowner">Without owner</option><option value="__dynamic">Dynamic membership</option></select>
        <span class="muted" id="rc"></span>
    </div>
    <div class="table-wrap">
        <table class="test-table" id="rt">
            <thead><tr><th>Group</th><th>Type</th><th>Privacy</th><th>Members</th><th>Owners</th><th>Email</th><th>Created</th></tr></thead>
            <tbody>
            @foreach($groups as $g)
                <tr data-q="{{ strtolower($g['name'].' '.$g['mail'].' '.$g['description']) }}" data-kind="{{ $g['kind'] }}"
                    data-tags="{{ implode(' ', array_filter([$g['visibility'] === 'Public' ? '__public' : null, $g['owners'] === 0 && in_array($g['kind'], ['Team', 'Microsoft 365'], true) ? '__noowner' : null, $g['dynamic'] ? '__dynamic' : null])) }}"
                    onclick="location.href='{{ route('identity.group', [$tenant, $g['id']]) }}'">
                    <td><strong>{{ $g['name'] }}</strong> @if($g['synced'])<span class="chip">Synced</span>@endif @if($g['dynamic'])<span class="chip">Dynamic</span>@endif<br><small class="muted">{{ \Illuminate\Support\Str::limit($g['description'], 80) }}</small></td>
                    <td>{{ $g['kind'] }}</td>
                    <td>@if($g['visibility'] === 'Public')<span class="badge s-manual">Public</span>@elseif($g['visibility'])<span class="badge s-pass">{{ $g['visibility'] }}</span>@else<span class="muted">–</span>@endif</td>
                    <td>{{ $g['members'] ?? '?' }}</td>
                    <td>@if($g['owners'] === 0 && in_array($g['kind'], ['Team', 'Microsoft 365'], true))<span class="badge s-fail">None</span>@else{{ $g['owners'] ?? '–' }}@endif</td>
                    <td class="muted">{{ $g['mail'] }}</td>
                    <td class="muted">{{ $g['created'] ? \Carbon\Carbon::parse($g['created'])->toFormattedDateString() : '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
function fr() {
  const q = document.getElementById('rq').value.toLowerCase(), f = document.getElementById('rf').value;
  let n = 0;
  document.querySelectorAll('#rt tbody tr').forEach((r) => {
    const ok = (!q || r.dataset.q.includes(q)) && (!f || (f.startsWith('__') ? r.dataset.tags.split(' ').includes(f) : r.dataset.kind === f));
    r.hidden = !ok; if (ok) n++;
  });
  document.getElementById('rc').textContent = n + ' shown';
}
fr();
</script>
@endpush
