@extends('layouts.app')
@section('title', 'Purview requirements: '.$project->tenant->name)

@section('content')
@include('purview._head')
@php
    $r = $project->requirements;
    $val = fn ($k) => old($k, $r[$k] ?? null);
@endphp

<p class="muted" style="margin-top:0">Run this as a workshop with the compliance owner. Every answer shapes the design: which data is protected, how strictly, for how long and in which order.</p>

<form method="post" action="{{ route('purview.requirements.save', $project) }}">
    @csrf
    <div class="card">
        <div class="field" style="max-width:560px"><label>Programme name</label><input type="text" name="project_name" value="{{ old('project_name', $project->name) }}" required maxlength="160"></div>
    </div>

    @foreach($questionnaire as $sec => [$title, $help, $fields])
        <div class="card q-section" id="q-{{ $sec }}">
            <div class="card-title">{{ $loop->iteration }} · {{ $title }} <small class="muted" style="font-weight:400">{{ $help }}</small></div>
            <div class="q-grid">
                @foreach($fields as $k => [$label, $type, $options, $default, $fhelp])
                    <div class="field" @if(in_array($type, ['multi', 'users'], true)) style="grid-column:1/-1" @endif>
                        @if($type === 'bool')
                            <label style="font-weight:400;display:flex;gap:10px;align-items:center"><span class="switch"><input type="checkbox" name="{{ $k }}" value="1" @checked($val($k))><span class="slider"></span></span> <strong>{{ $label }}</strong></label>
                        @else
                            <label>{{ $label }}</label>
                        @endif
                        @switch($type)
                            @case('select')
                                <select name="{{ $k }}">@foreach($options as $ok => $ol)<option value="{{ $ok }}" @selected($val($k) === $ok)>{{ $ol }}</option>@endforeach</select>
                                @break
                            @case('multi')
                                <div class="multi">@foreach($options as $ok => $ol)<label><input type="checkbox" name="{{ $k }}[]" value="{{ $ok }}" @checked(in_array($ok, (array) $val($k), true))> {{ $ol }}</label>@endforeach</div>
                                @break
                            @case('users')
                                <input type="text" placeholder="Search people…" oninput="const q=this.value.toLowerCase();document.querySelectorAll('.pu').forEach(l=>l.hidden=!l.dataset.q.includes(q))" style="max-width:320px;margin-bottom:8px">
                                <div class="tenant-pick">
                                    @forelse($users as $u)
                                        <label class="pu" data-q="{{ strtolower($u['displayName'].' '.$u['userPrincipalName'].' '.($u['department'] ?? '')) }}"><input type="checkbox" name="{{ $k }}[]" value="{{ $u['id'] }}" @checked(in_array($u['id'], (array) $val($k), true))>
                                            <span>{{ $u['displayName'] }} <small class="muted">{{ $u['jobTitle'] ?? $u['userPrincipalName'] }}</small></span></label>
                                    @empty
                                        <span class="muted">The directory could not be read. Add pilot members later in Entra ID.</span>
                                    @endforelse
                                </div>
                                @break
                            @case('bool')
                                @break
                            @case('number')
                                <input type="number" name="{{ $k }}" value="{{ $val($k) }}" min="0" style="max-width:160px">
                                @break
                            @case('date')
                                <input type="date" name="{{ $k }}" value="{{ $val($k) }}" style="max-width:200px">
                                @break
                            @default
                                <input type="{{ $type === 'email' ? 'email' : 'text' }}" name="{{ $k }}" value="{{ $val($k) }}">
                        @endswitch
                        @if($fhelp)<div class="help">{{ $fhelp }}</div>@endif
                        @error($k)<div class="error-text">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="sticky-actions">
        <span class="muted">@if($project->isApproved())Regenerating replaces the approved design and needs a new approval.@endif</span>
        <span style="flex:1"></span>
        @can('purview.manage')
            <button class="btn" name="next" value="save">Save</button>
            <button class="btn btn-primary" name="next" value="design" @if($project->isApproved()) data-confirm-btn="Regenerate the design? The current approved design will need approval again." @endif>Save and {{ $project->design ? 'regenerate' : 'generate' }} the design</button>
        @endcan
    </div>
</form>
@endsection

