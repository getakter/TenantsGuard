<div class="table-wrap">
<table>
    <thead><tr><th>When</th>@if($showTenant ?? false)<th>Client</th>@endif<th>Type</th><th>Person</th><th>Ticket</th><th>Status</th><th>By</th><th></th></tr></thead>
    <tbody>
    @forelse($runs as $r)
        <tr>
            <td style="white-space:nowrap">{{ $r->created_at->diffForHumans() }}</td>
            @if($showTenant ?? false)<td>{{ $r->tenant?->name }}</td>@endif
            <td><span class="badge {{ $r->type === 'onboard' ? 's-info' : 's-manual' }}">{{ $r->type === 'onboard' ? 'Joiner' : 'Leaver' }}</span></td>
            <td><strong>{{ $r->target_name }}</strong><br><small class="muted">{{ $r->target_upn }}</small></td>
            <td>{{ $r->ticket ?? '–' }}</td>
            <td><span class="badge {{ \App\Models\LifecycleRun::STATUS[$r->status][1] ?? 's-na' }}">{{ \App\Models\LifecycleRun::STATUS[$r->status][0] ?? $r->status }}</span>
                @if(count($r->openSteps()))<small class="muted"> {{ count($r->openSteps()) }} open</small>@endif</td>
            <td class="muted">{{ $r->user?->name }}</td>
            <td><a class="btn btn-sm" href="{{ route('operations.run', $r) }}">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="empty">No joiners or leavers yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
