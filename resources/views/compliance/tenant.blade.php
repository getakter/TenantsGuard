@extends('layouts.app')
@section('title', 'Compliance: '.$tenant->name)
@use('App\Services\Compliance\ComplianceEngine')
@use('App\Services\Compliance\FrameworkRegistry')
@use('App\Models\FrameworkAttestation')

@section('content')
@include('tenants._nav', ['tenant' => $tenant, 'active' => 'compliance'])
<div class="page-head">
    <div>
        <h1>Compliance: {{ $tenant->name }}</h1>
        <p>{{ $profile ? 'Control plan: '.$profile->name : 'No control plan assigned yet.' }}</p>
    </div>
    <div class="actions">
        @can('compliance.manage')
            <form method="post" action="{{ route('compliance.assign', $tenant) }}" style="display:flex;gap:6px">
                @csrf
                <select name="compliance_profile_id" style="width:auto">
                    <option value="">No control plan</option>
                    @foreach($profiles as $p)<option value="{{ $p->id }}" @selected($profile?->id === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                <button class="btn">Apply</button>
            </form>
            @if($profile)<a class="btn" href="{{ route('compliance.profiles.edit', $profile) }}">Choose controls</a>@endif
        @endcan
    </div>
</div>

@if(! $profile)
    <div class="card empty">
        <h2>Pick a control plan</h2>
        <p>Profiles bundle frameworks, such as Essential Eight ML2 + ASD ISM or HIPAA + NIST CSF, and the controls in scope for this customer.</p>
    </div>
@else
    <div class="grid grid-4" style="margin-bottom:16px">
    @php
        $ssCard = $tenant->secureScores()->first();
    @endphp
    <a class="card stat" href="{{ route('tenants.securescore', $tenant) }}" style="color:inherit" title="Microsoft Secure Score">
        <div class="label">Microsoft Secure Score</div>
        @if($ssCard && $ssCard->ok())
            <div class="value" style="color:{{ $ssCard->percent >= 80 ? 'var(--pass)' : ($ssCard->percent >= 50 ? 'var(--warn)' : 'var(--fail)') }}">{{ round($ssCard->percent) }}%</div>
            <small>{{ round($ssCard->current_score) }}/{{ round($ssCard->max_score) }} pts · similar tenants {{ round($ssCard->comparativePercent('TotalSeats') ?? 0) }}%</small>
        @else
            <div class="value">–</div><small>{{ $ssCard?->error ? 'Unavailable' : 'Captured on next scan' }}</small>
        @endif
    </a>
        @foreach($result as $key => $fw)
            <a class="card stat" href="#fw-{{ $key }}" style="color:inherit">
                <div class="label">{{ FrameworkRegistry::SHORT[$key] }}</div>
                @if(isset($fw['maturity']))
                    @php
                        $overall = collect($fw['maturity'])->min('achieved');
                    @endphp
                    <div class="value" style="color:{{ $overall >= $fw['target'] ? 'var(--pass)' : 'var(--fail)' }}">ML{{ $overall }}</div>
                    <small>target ML{{ $fw['target'] }}</small>
                @else
                    <div class="value">{{ $fw['score'] === null ? '–' : $fw['score'].'%' }}</div>
                    <small>{{ $fw['counts']['met'] }} met · {{ $fw['counts']['not_met'] + $fw['counts']['partial'] }} gaps · {{ $fw['counts']['manual'] }} need evidence</small>
                @endif
            </a>
        @endforeach
    </div>

    @foreach($result as $key => $fw)
        <div class="card" id="fw-{{ $key }}">
            <div class="page-head" style="margin-bottom:8px">
                <div>
                    <h2 style="margin:0">{{ $fw['framework']['name'] }}</h2>
                    <small class="muted">{{ $fw['framework']['publisher'] }} · {{ $fw['framework']['version'] }}</small>
                </div>
                <div class="pills">
                    @foreach(ComplianceEngine::STATUS as $s => [$label, $cls])
                        @if($fw['counts'][$s])<span class="badge {{ $cls }}">{{ $fw['counts'][$s] }} {{ strtolower($label) }}</span>@endif
                    @endforeach
                </div>
            </div>

            @if(isset($fw['maturity']))
                <div class="table-wrap" style="margin-bottom:14px">
                    <table>
                        <thead><tr><th>Mitigation strategy</th>@foreach($fw['framework']['levels'] as $l)<th style="text-align:center">ML{{ $l }}@if($l == $fw['target']) <small>(target)</small>@endif</th>@endforeach<th>Achieved</th></tr></thead>
                        <tbody>
                        @foreach($fw['maturity'] as $strategy => $m)
                            <tr>
                                <td>{{ $strategy }}</td>
                                @foreach($fw['framework']['levels'] as $l)
                                    @php
                                        $st = $m['levels'][$l] ?? 'na';
                                    @endphp
                                    <td style="text-align:center">
                                        @if($l > $fw['target'])<span class="muted">–</span>
                                        @else<span class="badge {{ ComplianceEngine::STATUS[$st][1] }}">{{ ComplianceEngine::STATUS[$st][0] }}</span>@endif
                                    </td>
                                @endforeach
                                <td><strong style="color:{{ $m['achieved'] >= $fw['target'] ? 'var(--pass)' : 'var(--fail)' }}">ML{{ $m['achieved'] }}</strong></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @foreach(collect($fw['requirements'])->groupBy('group') as $group => $reqs)
                <h3 class="cat-head">{{ $group }}</h3>
                @foreach($reqs as $r)
                    @php
                        $anchor = $key.'-'.md5($r['id']);
                        [$label, $cls] = ComplianceEngine::STATUS[$r['status']];
                        $a = $r['attestation'];
                    @endphp
                    <details class="finding" id="{{ $anchor }}">
                        <summary>
                            <span class="fid">{{ $r['id'] }}</span>
                            <div>
                                <div class="ftitle">{{ $r['title'] }} @if($r['level'])<span class="badge s-info">ML{{ $r['level'] }}</span>@endif</div>
                                <div class="fsum">
                                    @if($a) Attested by {{ $a->user?->name ?? 'unknown' }} {{ $a->updated_at->diffForHumans() }}
                                    @elseif($r['controls']) {{ count($r['controls']) }} automated control(s)
                                    @else No automated control: record an attestation with evidence @endif
                                </div>
                            </div>
                            <span></span>
                            <span class="badge {{ $cls }}">{{ $label }}</span>
                        </summary>
                        <div class="body">
                            <div>
                                @if(! empty($r['guidance']))<p style="margin-top:0" class="muted"><strong>Guidance:</strong> {{ $r['guidance'] }}</p>@endif
                                @if($r['controls_status'])
                                    <table>
                                        <tbody>
                                        @foreach($r['controls_status'] as $c)
                                            <tr>
                                                <td class="mono" style="width:90px">{{ $c['id'] }}</td>
                                                <td>
                                                    @if($c['source'] === 'posture')<a href="{{ route('tenants.show', $tenant) }}#{{ $c['id'] }}">{{ $c['title'] }}</a>
                                                    @else<a href="{{ route('tenants.intune', $tenant) }}">{{ $c['title'] }}</a> <span class="chip chip-platform">Intune</span>@endif
                                                </td>
                                                <td><span class="badge {{ ComplianceEngine::CONTROL_STATUS[$c['status']][1] }}">{{ ComplianceEngine::CONTROL_STATUS[$c['status']][0] }}</span></td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                            <div>
                                @if($a)
                                    <div class="ann-box" style="margin-top:0">
                                        <strong>{{ FrameworkAttestation::STATUSES[$a->status] }}</strong>
                                        <span class="muted">· {{ $a->user?->name }}, {{ $a->updated_at->format('M j, Y') }}@if($a->review_by) · review by {{ $a->review_by->format('M j, Y') }}@endif</span>
                                        <p style="margin:6px 0">{{ $a->note }}</p>
                                        @if($a->evidence_url)<a href="{{ $a->evidence_url }}" target="_blank" rel="noopener">Evidence</a>@endif
                                        @can('compliance.attest')
                                            <form method="post" action="{{ route('compliance.unattest', [$tenant, $key, $r['id']]) }}" style="margin-top:8px">@csrf @method('delete') <button class="btn btn-sm">Remove attestation</button></form>
                                        @endcan
                                    </div>
                                @endif
                                @can('compliance.attest')
                                    <details @if(! $a && ! $r['controls']) open @endif>
                                        <summary style="cursor:pointer;font-weight:600">{{ $a ? 'Update attestation' : 'Attest this requirement' }}</summary>
                                        <form method="post" action="{{ route('compliance.attest', [$tenant, $key, $r['id']]) }}" style="margin-top:8px">
                                            @csrf
                                            <div class="field">
                                                <select name="status">@foreach(FrameworkAttestation::STATUSES as $sk => $sl)<option value="{{ $sk }}" @selected($a?->status === $sk)>{{ $sl }}</option>@endforeach</select>
                                            </div>
                                            <div class="field"><textarea name="note" required placeholder="How it is implemented, or why not, and who confirmed it">{{ $a?->note }}</textarea></div>
                                            <div class="field"><input type="url" name="evidence_url" value="{{ $a?->evidence_url }}" placeholder="Evidence link (SharePoint, ticket…)"></div>
                                            <div class="field"><label>Review by</label><input type="date" name="review_by" value="{{ $a?->review_by?->format('Y-m-d') }}"></div>
                                            <button class="btn btn-sm btn-primary">Save attestation</button>
                                            @if($r['controls'])<div class="help">An attestation overrides the automated result for this requirement.</div>@endif
                                        </form>
                                    </details>
                                @endcan
                            </div>
                        </div>
                    </details>
                @endforeach
            @endforeach
        </div>
    @endforeach
@endif

<script>
    if (location.hash) { const el = document.getElementById(location.hash.slice(1)); if (el && el.tagName === 'DETAILS') { el.open = true; el.scrollIntoView({ block: 'center' }); } }
</script>
@endsection
