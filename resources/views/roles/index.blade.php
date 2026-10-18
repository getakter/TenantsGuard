@extends('layouts.app')
@section('title', 'Roles & permissions')

@section('content')
@php
    $isGa = auth()->user()->isGlobalAdmin();
@endphp
<div class="page-head">
    <div>
        <h1>Roles &amp; permissions</h1>
        <p>What each role can do. Built-in roles can be adjusted, and you can add custom roles for your team's way of working. A user's tenant access is set on the user.</p>
    </div>
    <a class="btn" href="{{ route('users.index') }}">Users</a>
</div>

@unless($isGa)
    <div class="alert alert-warn">Only Global Administrators can change roles.</div>
@endunless

<div class="card" style="padding:0">
    <div class="table-wrap">
        <table class="perm-matrix">
            <thead>
            <tr>
                <th>Permission</th>
                @foreach($roles as $role)
                    <th style="text-align:center;min-width:120px">{{ $role->name }}<br><small style="text-transform:none;letter-spacing:0">{{ $role->users_count }} {{ \Illuminate\Support\Str::plural('user', $role->users_count) }}</small></th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach($groups as $group => $perms)
                <tr><td colspan="{{ count($roles) + 1 }}" style="background:var(--surface-2);font-weight:700">{{ $group }}</td></tr>
                @foreach($perms as $perm => $label)
                    <tr>
                        <td>{{ $label }}<br><small class="mono muted">{{ $perm }}</small></td>
                        @foreach($roles as $role)
                            <td style="text-align:center">
                                @if($role->allows($perm))<span style="color:var(--pass);font-weight:700">✓</span>@else<span class="muted">–</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($isGa)
    @foreach($roles as $role)
        @continue($role->isGlobalAdmin())
        <details class="card" style="margin-top:12px">
            <summary style="cursor:pointer;font-weight:700">Edit {{ $role->name }} @if($role->is_system)<span class="badge s-na">Built-in</span>@else<span class="badge s-info">Custom</span>@endif</summary>
            <form method="post" action="{{ route('roles.update', $role) }}" style="margin-top:12px">
                @csrf @method('put')
                @include('roles._fields', ['role' => $role])
                <div class="actions">
                    <button class="btn btn-primary">Save role</button>
                </div>
            </form>
            @unless($role->is_system)
                <form method="post" action="{{ route('roles.destroy', $role) }}" style="margin-top:8px" data-confirm="Delete role {{ $role->name }}?">
                    @csrf @method('delete') <button class="btn btn-sm btn-danger" @disabled($role->users_count)>Delete role</button>
                    @if($role->users_count)<span class="help">Move its {{ $role->users_count }} users to another role first.</span>@endif
                </form>
            @endunless
        </details>
    @endforeach

    <div class="card" style="margin-top:16px">
        <h2>Create a custom role</h2>
        <form method="post" action="{{ route('roles.store') }}">
            @csrf
            @include('roles._fields', ['role' => new \App\Models\Role(['permissions' => ['tenants.view']])])
            <button class="btn btn-primary">Create role</button>
        </form>
    </div>
@endif
@endsection
