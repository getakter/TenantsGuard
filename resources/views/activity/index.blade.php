@extends('layouts.app')
@section('title', 'Activity')

@section('content')
<div class="page-head">
    <div>
        <h1>Activity &amp; audit trail</h1>
        <p>Every scan, fix, baseline push, annotation and detected drift, with who did it and the outcome.</p>
    </div>
</div>

<form class="filters" method="get">
    <select name="tenant" onchange="this.form.submit()">
        <option value="">All tenants</option>
        @foreach($tenants as $t)<option value="{{ $t->id }}" @selected(request('tenant') == $t->id)>{{ $t->name }}</option>@endforeach
    </select>
    <select name="outcome" onchange="this.form.submit()">
        <option value="">All outcomes</option>
        @foreach(['success', 'failure', 'info'] as $o)<option value="{{ $o }}" @selected(request('outcome') === $o)>{{ ucfirst($o) }}</option>@endforeach
    </select>
    <input type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()" title="From">
    <input type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()" title="To">
    <a class="btn" href="{{ route('activity.export', request()->query()) }}">Export CSV</a>
</form>

<div class="card" style="padding:0">
    @include('activity._table', ['logs' => $logs])
</div>
<div style="margin-top:12px">
    @if($logs->previousPageUrl())<a class="btn btn-sm" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif
    @if($logs->nextPageUrl())<a class="btn btn-sm" href="{{ $logs->nextPageUrl() }}">Next</a>@endif
</div>
@endsection
