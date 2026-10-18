{{-- Three lenses on one tenant: TenantsGuard posture · Microsoft Secure Score · compliance frameworks.
     Params: $tenant, $snap, $prev, $compliance (ComplianceEngine::evaluate result), $plan --}}
@use('App\Services\Compliance\FrameworkRegistry')
@php
    $posture = $tenant->latestScan()->first();
@endphp
<div class="lenses">
    <div class="card">
        <div class="label-sm" title="Weighted pass rate of TenantsGuard's Microsoft 365 and Intune controls (out-of-scope and accepted risks excluded)">TenantsGuard posture</div>
        <div style="display:flex;gap:14px;align-items:center">
            @include('partials.ring', ['score' => $posture?->score, 'size' => 72])
            <div style="font-size:13px">
                @if($posture)
                    <div><span class="badge sev-critical">{{ $posture->counts['fail_critical'] ?? 0 }}</span> critical · <span class="badge sev-high">{{ $posture->counts['fail_high'] ?? 0 }}</span> high</div>
                    <div class="muted" style="margin-top:4px">{{ $posture->counts['pass'] ?? 0 }} controls passing · scanned {{ $posture->created_at->diffForHumans() }}</div>
                    <a href="{{ route('tenants.show', $tenant) }}">Findings</a>
                @else
                    <span class="muted">Not scanned yet</span>
                @endif
            </div>
        </div>
        <p class="help" style="margin:8px 0 0">Configuration against your baseline: Microsoft 365 and Intune.</p>
    </div>

    @include('securescore._panel', ['snap' => $snap, 'prev' => $prev, 'tenant' => $tenant, 'compact' => true])

    <div class="card">
        <div class="label-sm">Compliance frameworks</div>
        @if($plan && $compliance)
            <div style="font-size:13px;margin-bottom:6px"><a href="{{ route('plans.rollout', $tenant) }}">{{ $plan->name }}</a></div>
            <table>
                <tbody>
                @foreach($compliance as $key => $fw)
                    <tr>
                        <td style="padding:4px 0"><span class="fw fw-{{ $key }}">{{ FrameworkRegistry::SHORT[$key] }}</span></td>
                        <td style="padding:4px 0;text-align:right">
                            @if(isset($fw['maturity']))
                                @php
                                    $ml = collect($fw['maturity'])->min('achieved');
                                @endphp
                                <strong style="color:{{ $ml >= $fw['target'] ? 'var(--pass)' : 'var(--fail)' }}">ML{{ $ml }}</strong> <small class="muted">/ ML{{ $fw['target'] }}</small>
                            @else
                                <strong>{{ $fw['score'] === null ? '–' : $fw['score'].'%' }}</strong>
                                @if($fw['counts']['manual'])<small class="muted"> · {{ $fw['counts']['manual'] }} need evidence</small>@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <a href="{{ route('compliance.tenant', $tenant) }}" style="font-size:13px">Framework scorecard</a>
        @else
            <p class="muted" style="font-size:13px">No control plan yet. Choose frameworks such as CIS, HIPAA or Essential Eight to measure compliance.</p>
            <a class="btn btn-sm" href="{{ route('plans.rollout', $tenant) }}">Set up a control plan</a>
        @endif
        <p class="help" style="margin:8px 0 0">Regulatory and contractual obligations.</p>
    </div>
</div>
