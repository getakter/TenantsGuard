@use('App\Models\ScriptRun')
<table>
    <thead><tr><th>#</th><th>Script</th><th>Clients</th><th>Started by</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    @forelse($runs as $r)
        <tr>
            <td><a href="{{ route('scripts.runs.show', $r) }}">#{{ $r->id }}</a></td>
            <td>{{ $r->version->script->name }} <small class="muted">v{{ $r->version->version }}</small><br><small class="muted">{{ \Illuminate\Support\Str::limit($r->reason, 70) }}</small></td>
            <td>{{ $r->targets_count }}</td>
            <td>{{ $r->user->name ?? 'unknown' }}</td>
            <td><span class="badge {{ ScriptRun::STATUS[$r->status][1] ?? 's-na' }}">{{ ScriptRun::STATUS[$r->status][0] ?? $r->status }}</span></td>
            <td class="muted">{{ $r->created_at->diffForHumans() }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No runs yet.</td></tr>
    @endforelse
    </tbody>
</table>
