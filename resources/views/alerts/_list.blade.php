{{-- Alert triage list. Params: $alerts (paginator), $filters, $staff, $showTenant, $tenants (portfolio only) --}}
@php
    $sev = \App\Models\SecurityAlert::SEVERITIES;
    $stat = \App\Models\SecurityAlert::STATUSES;
    $resolutions = \App\Models\SecurityAlert::RESOLUTIONS;
    $rules = \App\Services\Monitoring\SecurityMonitor::RULES;
    $canManage = auth()->user()->can('alerts.manage');
@endphp

<form method="get" class="filter-bar card">
    <div class="field"><label for="f-status">Status</label>
        <select id="f-status" name="status" onchange="this.form.submit()">
            @foreach(['open' => 'Open (new + investigating)', 'new' => 'New', 'acknowledged' => 'Investigating', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $l)
                <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $l }}</option>
            @endforeach
        </select></div>
    <div class="field"><label for="f-sev">Severity</label>
        <select id="f-sev" name="severity" onchange="this.form.submit()"><option value="">All</option>
            @foreach($sev as $k => [$l])<option value="{{ $k }}" @selected(($filters['severity'] ?? '') === $k)>{{ $l }}</option>@endforeach
        </select></div>
    <div class="field"><label for="f-rule">Detection</label>
        <select id="f-rule" name="rule" onchange="this.form.submit()"><option value="">All</option>
            @foreach($rules as $k => [$l])<option value="{{ $k }}" @selected(($filters['rule'] ?? '') === $k)>{{ $l }}</option>@endforeach
        </select></div>
    @if($showTenant)
        <div class="field"><label for="f-tenant">Client</label>
            <select id="f-tenant" name="tenant" onchange="this.form.submit()"><option value="">All clients</option>
                @foreach($tenants as $t)<option value="{{ $t->id }}" @selected((int) ($filters['tenant'] ?? 0) === $t->id)>{{ $t->name }}</option>@endforeach
            </select></div>
    @endif
    <div class="field" style="flex:1;min-width:180px"><label for="f-q">Search</label><input id="f-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="User, policy, app…"></div>
    <div class="field" style="align-self:flex-end"><button class="btn">Apply</button></div>
</form>

<form method="post" action="{{ route('alerts.bulk') }}" id="bulk-form">
    @csrf
    @if($canManage && $alerts->count())
        <div class="bulk-bar">
            <label><input type="checkbox" data-select-all> Select all</label>
            <span style="flex:1"></span>
            <select name="resolution" aria-label="Resolution">@foreach($resolutions as $k => $l)@if($k !== 'cleared_at_source')<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select>
            <button class="btn btn-sm" name="action" value="acknowledge">Investigate</button>
            <button class="btn btn-sm" name="action" value="assign">Assign to me</button>
            <button class="btn btn-sm btn-primary" name="action" value="resolve">Resolve</button>
        </div>
        @error('ids')<div class="error-text">{{ $message }}</div>@enderror
    @endif
</form>

<div class="alert-list">
    @forelse($alerts as $a)
        @php
            [$sl, $sc] = $sev[$a->severity] ?? [$a->severity, 's-info'];
            [$stl, $stc] = $stat[$a->status] ?? [$a->status, 's-info'];
        @endphp
        <details class="alert-item sev-{{ $a->severity }}" @if($loop->first && $alerts->count() === 1) open @endif>
            <summary>
                @if($canManage)<input type="checkbox" name="ids[]" value="{{ $a->id }}" form="bulk-form" aria-label="Select alert" onclick="event.stopPropagation()">@endif
                <span class="badge {{ $sc }}">{{ $sl }}</span>
                <span class="alert-title">
                    <strong>{{ $a->title }}</strong>
                    <small class="muted">
                        @if($showTenant && $a->tenant)<a href="{{ route('alerts.tenant', $a->tenant) }}">{{ $a->tenant->name }}</a> · @endif
                        {{ $rules[$a->rule][0] ?? $a->rule }} · {{ $a->occurred_at?->diffForHumans() ?? $a->created_at->diffForHumans() }}
                        @if($a->occurrences > 1) · seen {{ $a->occurrences }}× @endif
                        @if($a->assignee) · {{ $a->assignee->name }} @endif
                    </small>
                </span>
                <span class="badge {{ $stc }}">{{ $stl }}</span>
            </summary>
            <div class="alert-body">
                <p style="margin-top:0">{{ $a->detail }}</p>
                @if($a->evidence)
                    <dl class="kv">
                        @foreach($a->evidence as $k => $v)
                            @continue($v === null || $v === [] || $v === '')
                            <dt>{{ ucfirst(str_replace('_', ' ', $k)) }}</dt>
                            <dd class="mono">@if(is_array($v)){{ collect($v)->map(fn ($x, $y) => is_string($y) ? "$y: $x" : $x)->implode(' · ') }}@elseif($k === 'url' && str_starts_with((string) $v, 'https://'))<a href="{{ $v }}" target="_blank" rel="noopener">Open in Microsoft Defender</a>@else{{ $v }}@endif</dd>
                        @endforeach
                    </dl>
                @endif
                @if($a->status === 'resolved')
                    <p class="help">Resolved {{ $a->resolved_at?->diffForHumans() }}{{ $a->resolver ? ' by '.$a->resolver->name : '' }}: {{ $resolutions[$a->resolution] ?? $a->resolution }}.</p>
                @endif
                @if($a->note)<pre class="alert-note">{{ $a->note }}</pre>@endif
                @if($canManage)
                    <form method="post" action="{{ route('alerts.update', $a) }}" class="alert-actions">
                        @csrf
                        <input type="text" name="note" maxlength="2000" placeholder="Add a note (what you checked, ticket number…)" aria-label="Note">
                        @if($a->status === 'resolved')
                            <button class="btn btn-sm" name="action" value="reopen">Reopen</button>
                        @else
                            <select name="assigned_to" aria-label="Assign to">@foreach($staff as $u)<option value="{{ $u->id }}" @selected(($a->assigned_to ?? auth()->id()) === $u->id)>{{ $u->name }}</option>@endforeach</select>
                            <button class="btn btn-sm" name="action" value="assign">Assign</button>
                            @if($a->status === 'new')<button class="btn btn-sm" name="action" value="acknowledge">Investigate</button>@endif
                            <select name="resolution" aria-label="Resolution">@foreach($resolutions as $k => $l)@if($k !== 'cleared_at_source')<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select>
                            <button class="btn btn-sm btn-primary" name="action" value="resolve">Resolve</button>
                        @endif
                        <button class="btn btn-sm" name="action" value="note">Save note</button>
                    </form>
                @endif
            </div>
        </details>
    @empty
        <div class="card empty-state">
            <strong>No alerts match.</strong>
            <p class="muted">{{ $filters['status'] === 'open' ? 'Nothing needs attention right now.' : 'Try another filter.' }}</p>
        </div>
    @endforelse
</div>
{{ $alerts->links() }}

<script>
document.querySelectorAll('[data-select-all]').forEach((all) => all.addEventListener('change', () => {
  document.querySelectorAll('input[name="ids[]"]').forEach((b) => { b.checked = all.checked; });
}));
</script>
