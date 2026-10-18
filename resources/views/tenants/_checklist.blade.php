{{-- Guided onboarding for a new client. Hidden once every step is complete. Params: $tenant --}}
@php
    $runs = \App\Models\ControlPlanRun::where('tenant_id', $tenant->id);
    $steps = [
        ['done' => $tenant->isReady(), 'title' => 'Connect the tenant', 'text' => 'A Global Administrator grants consent in the Microsoft popup.', 'url' => route('tenants.show', $tenant), 'cta' => 'Connect'],
        ['done' => $tenant->scans()->exists(), 'title' => 'Run the first scan', 'text' => 'Baseline the current Microsoft 365 posture.', 'url' => route('tenants.show', $tenant), 'cta' => 'Scan'],
        ['done' => (bool) $tenant->compliance_profile_id, 'title' => 'Choose a control plan', 'text' => 'Pick the frameworks (for example CIS + HIPAA, or Essential Eight ML2) and the controls in scope.', 'url' => route('plans.rollout', $tenant), 'cta' => 'Choose'],
        ['done' => (clone $runs)->exists(), 'title' => 'Preview Phase 1', 'text' => 'See exactly what will change before touching anything.', 'url' => route('plans.rollout', $tenant), 'cta' => 'Preview'],
        ['done' => (clone $runs)->where('dry_run', false)->exists(), 'title' => 'Apply the first phase', 'text' => 'Roll out quick wins, then continue phase by phase.', 'url' => route('plans.rollout', $tenant), 'cta' => 'Roll out'],
    ];
    $doneCount = count(array_filter(array_column($steps, 'done')));
    $next = collect($steps)->firstWhere('done', false);
@endphp
@if($doneCount < count($steps))
    <div class="card checklist" style="margin-bottom:16px">
        <div class="page-head" style="margin-bottom:8px">
            <div>
                <h2 style="margin:0">Client onboarding · {{ $doneCount }}/{{ count($steps) }}</h2>
                <small class="muted">Next: {{ $next['title'] }}</small>
            </div>
            <a class="btn btn-primary btn-sm" href="{{ $next['url'] }}">{{ $next['cta'] }}</a>
        </div>
        <ol class="steps-list">
            @foreach($steps as $s)
                <li class="{{ $s['done'] ? 'done' : ($s === $next ? 'next' : '') }}">
                    <span class="dot">{{ $s['done'] ? '✓' : $loop->iteration }}</span>
                    <span><strong>{{ $s['title'] }}</strong><br><small class="muted">{{ $s['text'] }}</small></span>
                </li>
            @endforeach
        </ol>
    </div>
@endif
