<div class="table-wrap">
<table>
    <thead><tr><th>When</th><th>Tenant</th><th>Action</th><th>Outcome</th><th>Details</th><th>By</th></tr></thead>
    <tbody>
    @forelse($logs as $log)
        <tr>
            <td title="{{ $log->created_at }}" style="white-space:nowrap">{{ $log->created_at->diffForHumans() }}</td>
            <td>@if($log->tenant)<a href="{{ route('tenants.show', $log->tenant) }}">{{ $log->tenant->name }}</a>@else<span class="muted">–</span>@endif</td>
            <td><code>{{ $log->action }}</code>@if($log->check_id) <span class="tag">{{ $log->check_id }}</span>@endif</td>
            <td><span class="badge {{ ['success' => 's-pass', 'failure' => 's-fail', 'info' => 's-na'][$log->outcome] ?? 's-na' }}">{{ ucfirst($log->outcome) }}</span></td>
            <td>{{ $log->message }}</td>
            <td class="muted">{{ $log->user?->name ?? 'System' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="empty">No activity yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
