@extends('layouts.app')
@section('title', 'Script runs')

@section('content')
<div class="page-head">
    <div>
        <h1>Script runs and results</h1>
        <p>Every run with who started it and why. You only see runs and results for clients assigned to you.</p>
    </div>
    <a class="btn" href="{{ route('scripts.index') }}">Script library</a>
</div>
<div class="card">
    @include('scripts._runs', ['runs' => $runs])
    <div style="margin-top:12px">{{ $runs->links() }}</div>
</div>
@endsection
