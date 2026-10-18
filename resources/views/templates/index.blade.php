@extends('layouts.app')
@section('title', 'User templates')

@section('content')
<div class="page-head">
    <div>
        <h1>User templates</h1>
        <p>Per-client defaults for new accounts: username format, display name suffix, domain, licences, groups, company and address. The default template is pre-selected when you onboard someone for that client.</p>
    </div>
    <form method="get" action="{{ route('identity.pick', 'templates') }}" class="op-pick" style="margin:0">
        <select name="tenant" required>
            <option value="">New template for…</option>
            @foreach($tenants->filter->isReady() as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
        <button class="btn btn-primary">Create</button>
    </form>
</div>

@error('graph')<div class="alert alert-err">{{ $message }}</div>@enderror

@forelse($tenants->filter(fn ($t) => $templates->has($t->id)) as $t)
    <div class="card">
        <h2>{{ $t->name }} <small class="muted" style="font-weight:400">{{ $t->domain }}</small></h2>
        @foreach($templates[$t->id] as $tpl)
            <div class="tpl-card">
                <div>
                    <strong>{{ $tpl->name }}</strong> @if($tpl->is_default)<span class="badge s-pass">Default</span>@endif
                    <div class="leaver-facts">
                        <span>Username: {{ $formats[$tpl->data['username_format'] ?? 'first.last'] ?? 'firstname.lastname' }}{{ ! empty($tpl->data['domain']) ? '@'.$tpl->data['domain'] : '' }}</span>
                        @if(! empty($tpl->data['display_suffix']))<span>Display name suffix: {{ $tpl->data['display_suffix'] }}</span>@endif
                        @if(! empty($tpl->data['job_title']) || ! empty($tpl->data['department']))<span>{{ trim(($tpl->data['job_title'] ?? '').' · '.($tpl->data['department'] ?? ''), ' ·') }}</span>@endif
                        <span>{{ count($tpl->data['licences'] ?? []) }} licence(s)</span>
                        <span>{{ count($tpl->data['groups'] ?? []) }} group(s)</span>
                        <span>{{ ($tpl->data['password_mode'] ?? 'tap') === 'tap' ? 'Temporary Access Pass' : 'Temporary password' }}</span>
                    </div>
                </div>
                <span class="actions" style="flex-shrink:0">
                    <a class="btn btn-sm btn-primary" href="{{ route('operations.onboard', [$t, 'template' => $tpl->id]) }}">Onboard with it</a>
                    <a class="btn btn-sm" href="{{ route('templates.edit', [$t, $tpl]) }}">Edit</a>
                    <form class="inline" method="post" action="{{ route('templates.destroy', [$t, $tpl]) }}" data-confirm="Delete template {{ $tpl->name }}?">@csrf @method('delete') <button class="btn btn-sm btn-danger">Delete</button></form>
                </span>
            </div>
        @endforeach
    </div>
@empty
    <div class="card">
        <p class="muted">No templates yet. Create one per role, for example "Dental nurse" or "Sales", and mark the most common one as the default for the client.
            You can also save a template from the onboarding form, or build one from an existing user.</p>
    </div>
@endforelse
@endsection
