@extends('layouts.app')
@section('title', $group['name'].': '.$tenant->name)

@section('content')
@include('identity._head', ['title' => $group['name'], 'subtitle' => $group['kind'].($group['visibility'] ? ' · '.$group['visibility'] : '').($group['mail'] ? ' · '.$group['mail'] : ''), 'readAt' => now()->toIso8601String()])

@if($group['description'])<p class="muted">{{ $group['description'] }}</p>@endif
@if($group['dynamic'] && $group['rule'])<div class="alert alert-ok">Dynamic membership rule: <code>{{ $group['rule'] }}</code>. Members are added and removed automatically.</div>@endif
@if($group['visibility'] === 'Public')<div class="alert alert-warn">This group is public: everyone in the organisation can read its files and conversations, and Copilot can surface them to anyone.</div>@endif
@if(in_array($group['kind'], ['Team', 'Microsoft 365'], true) && ! $group['owner_list'])<div class="alert alert-err">This group has no owner. Nobody can manage membership or respond to access reviews.</div>@endif

<div class="dash-grid-2">
    <div class="card">
        <div class="card-title">Members ({{ count($group['member_list']) }})</div>
        <table>
            <tbody>
            @forelse($group['member_list'] as $m)
                <tr><td>@if($m['type'] === 'user')<a href="{{ route('operations.user', [$tenant, $m['id']]) }}"><strong>{{ $m['name'] }}</strong></a>@else<strong>{{ $m['name'] }}</strong> <span class="chip">{{ $m['type'] }}</span>@endif
                    @if($m['guest'])<span class="chip">Guest</span>@endif<br><small class="muted">{{ $m['upn'] }}</small></td></tr>
            @empty
                <tr><td class="muted">No members.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <div class="card-title">Owners ({{ count($group['owner_list']) }})</div>
        <table>
            <tbody>
            @forelse($group['owner_list'] as $m)
                <tr><td><a href="{{ route('operations.user', [$tenant, $m['id']]) }}"><strong>{{ $m['name'] }}</strong></a><br><small class="muted">{{ $m['upn'] }}</small></td></tr>
            @empty
                <tr><td class="muted">No owners.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="help" style="margin-top:12px"><a href="{{ route('identity.groups', $tenant) }}">← All groups</a></p>
    </div>
</div>
@endsection
