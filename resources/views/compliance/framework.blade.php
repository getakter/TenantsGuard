@extends('layouts.app')
@section('title', $fw['name'])

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $fw['name'] }}</h1>
        <p>{{ $fw['publisher'] }} · {{ $fw['version'] }} · {{ $fw['region'] }}</p>
        <p class="muted">{{ $fw['about'] }}</p>
    </div>
    <a class="btn" href="{{ route('compliance.index') }}#frameworks">Back</a>
</div>

@foreach(collect($fw['requirements'])->groupBy('group') as $group => $reqs)
    <div class="card">
        <h2>{{ $group }}</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th style="width:150px">Requirement</th>@if($fw['levels'])<th>Level</th>@endif<th>Description</th><th>Automated controls</th></tr></thead>
                <tbody>
                @foreach($reqs as $r)
                    <tr>
                        <td class="mono">{{ $r['id'] }}</td>
                        @if($fw['levels'])<td><span class="badge s-info">ML{{ $r['level'] }}</span></td>@endif
                        <td>{{ $r['title'] }}@if(! empty($r['guidance']))<div class="help">{{ $r['guidance'] }}</div>@endif</td>
                        <td>
                            @forelse($r['controls'] as $cid)
                                <span class="tag" title="{{ $controls[$cid]['title'] ?? '' }}">{{ $cid }} · {{ \Illuminate\Support\Str::limit($controls[$cid]['title'] ?? '', 42) }}</span>
                            @empty
                                <span class="chip">Attestation</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach
@endsection
