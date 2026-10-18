@extends('layouts.app')
@section('title', $title)

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $desc }} Choose the client to work in.</p>
    </div>
    @if($action === 'templates')
        <a class="btn" href="{{ route('templates.index') }}">All templates</a>
    @endif
</div>

@if($tenants->isEmpty())
    <div class="card"><p class="muted">No connected clients yet. Add a client and grant admin consent from the Portfolio first.</p></div>
@else
    <div class="card" style="max-width:640px">
        <div class="filters"><input type="text" placeholder="Search clients…" autofocus oninput="const q=this.value.toLowerCase();document.querySelectorAll('.pick-row').forEach(r=>r.hidden=!r.dataset.q.includes(q))"></div>
        @foreach($tenants->sortByDesc(fn ($t) => $t->id === $last) as $t)
            <a class="pick-row" data-q="{{ strtolower($t->name.' '.$t->domain) }}" href="{{ route('identity.pick', [$action, 'tenant' => $t->id]) }}">
                <span><strong>{{ $t->name }}</strong><br><small class="muted">{{ $t->domain }}</small></span>
                @if($t->id === $last)<span class="chip">Last used</span>@endif
                @if($t->isDemo())<span class="chip">Demo</span>@endif
                <span class="pick-go">›</span>
            </a>
        @endforeach
    </div>
@endif
@endsection
