@extends('layouts.app')
@section('title', 'People: '.$tenant->name)
@use('App\Models\LifecycleTask')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'people'])

<div class="page-head">
    <div>
        <h1>People</h1>
        <p>Onboard and offboard {{ $tenant->name }} staff with a repeatable, audited process.</p>
    </div>
    <div class="actions">
        @if($tenant->isReady())
            @can('staff.onboard')
                <a class="btn btn-primary" href="{{ route('operations.onboard', $tenant) }}">Onboard user</a>
                <a class="btn" href="{{ route('operations.bulk', $tenant) }}">Bulk onboard</a>
            @endcan
            @can('staff.offboard')<a class="btn btn-danger" href="{{ route('operations.offboard', $tenant) }}">Offboard users</a>@endcan
        @endif
    </div>
</div>

@unless($tenant->isReady())
    <div class="alert alert-warn">Connect this tenant (admin consent) before managing its staff.</div>
@endunless

<div class="grid grid-2" style="margin-bottom:16px">
    <div class="card">
        <h2>User templates</h2>
        <p class="muted">Username format, licences, groups, company and sign-in method for a role, for example "Dental nurse" or "Sales". The default one is pre-selected when onboarding.
            @can('staff.onboard')<a href="{{ route('templates.create', $tenant) }}">New template</a>@endcan</p>
        @forelse($templates as $tpl)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
                <span><strong>{{ $tpl->name }}</strong> @if($tpl->is_default)<span class="badge s-pass">Default</span>@endif <small class="muted">{{ count($tpl->data['licences'] ?? []) }} licence(s) · {{ count($tpl->data['groups'] ?? []) }} group(s)</small></span>
                @can('staff.onboard')
                    <span class="actions">
                        <a class="btn btn-sm" href="{{ route('operations.onboard', [$tenant, 'template' => $tpl->id]) }}">Use</a>
                        <a class="btn btn-sm" href="{{ route('templates.edit', [$tenant, $tpl]) }}">Edit</a>
                        <form class="inline" method="post" action="{{ route('operations.template.delete', [$tenant, $tpl]) }}" data-confirm="Delete template {{ $tpl->name }}?">@csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button></form>
                    </span>
                @endcan
            </div>
        @empty
            <p class="muted">No templates yet.</p>
        @endforelse
    </div>
    <div class="card">
        <h2>Scheduled actions</h2>
        @forelse($tasks as $task)
            <div style="padding:6px 0;border-bottom:1px solid var(--border)">
                {{ $task->execute_at->format('D j M Y, H:i') }}: {{ LifecycleTask::ACTIONS[$task->action] }}, <a href="{{ route('operations.run', $task->run) }}">{{ $task->run->target_upn }}</a>
            </div>
        @empty
            <p class="muted">None.</p>
        @endforelse
    </div>
</div>

<div class="card">
    <h2>History</h2>
    @include('operations._runs', ['runs' => $runs])
</div>
@endsection
