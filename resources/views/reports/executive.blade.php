<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->name }}: {{ $delta ? 'Delta' : 'Security' }} report</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        :root { --accent: {{ $branding['accent_color'] }}; }
        body { background: #fff; }
        .report { max-width: 960px; margin: 0 auto; padding: 32px 24px 60px; }
        .cover { border-bottom: 4px solid var(--accent); padding-bottom: 18px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
        .cover h1 { font-size: 1.8rem; margin: 6px 0 0; }
        section { margin-bottom: 28px; break-inside: avoid-page; }
        section h2 { border-bottom: 1px solid var(--border); padding-bottom: 6px; }
        .failure { border-left: 4px solid var(--fail); padding: 8px 12px; margin-bottom: 10px; background: var(--surface-2); break-inside: avoid; }
        .failure.sev-c { border-color: var(--critical); }
        .failure.accepted { border-color: var(--muted); opacity: .8; }
        .footer { margin-top: 40px; color: var(--muted); font-size: 12px; border-top: 1px solid var(--border); padding-top: 10px; }
        @media print { .report { padding: 0; } a { color: inherit; } }
    </style>
</head>
<body>
<div class="report">
    <div class="no-print" style="text-align:right;margin-bottom:12px" @if($embed ?? false) hidden @endif>
        <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="cover">
        <div>
            @if($branding['logo_url'])<img src="{{ $branding['logo_url'] }}" alt="" style="height:40px"><br>@endif
            <div class="muted">{{ $branding['company_name'] }} · Microsoft 365 {{ $delta ? 'progress (delta) report' : 'security assessment' }}</div>
            <h1>{{ $tenant->name }}</h1>
            <div class="muted">Scan of {{ $scan->created_at->format('F j, Y H:i') }}@if($delta) compared with {{ $delta['from']->created_at->format('F j, Y H:i') }}@endif</div>
        </div>
        @include('partials.ring', ['score' => $scan->score, 'size' => 110])
    </div>

    @if($show('summary'))
    <section>
        <h2>Executive summary</h2>
        @php($c = $scan->counts)
        <p>
            The tenant scored <strong>{{ $scan->score }}%</strong> across {{ count($rows) }} security controls.
            {{ $c['pass'] ?? 0 }} controls passed and {{ $c['fail'] ?? 0 }} failed:
            {{ $c['fail_critical'] ?? 0 }} critical, {{ $c['fail_high'] ?? 0 }} high, {{ $c['fail_medium'] ?? 0 }} medium and {{ $c['fail_low'] ?? 0 }} low.
            @if(($c['manual'] ?? 0) > 0){{ $c['manual'] }} controls need a manual check.@endif
        </p>
        @if($delta)
            <div class="grid grid-3">
                <div class="card stat"><div class="label">Score change</div><div class="value" style="color:{{ $delta['change'] >= 0 ? 'var(--pass)' : 'var(--fail)' }}">{{ $delta['change'] >= 0 ? '+' : '' }}{{ $delta['change'] }}</div><small>{{ $delta['from']->score }}% → {{ $scan->score }}%</small></div>
                <div class="card stat"><div class="label">Controls fixed</div><div class="value" style="color:var(--pass)">{{ count($delta['fixed']) }}</div></div>
                <div class="card stat"><div class="label">Regressions</div><div class="value" style="color:{{ count($delta['regressed']) ? 'var(--fail)' : 'inherit' }}">{{ count($delta['regressed']) }}</div></div>
            </div>
        @endif
    </section>
    @endif

    @if($show('changes'))
    @if($delta)
        <section>
            <h2>What changed</h2>
            <div class="grid grid-2">
                <div>
                    <h3 style="color:var(--pass)">Fixed</h3>
                    @forelse($delta['fixed'] as $id)<div>✓ <span class="mono">{{ $id }}</span> {{ $rows[$id]['check']['title'] }}</div>@empty<p class="muted">None.</p>@endforelse
                </div>
                <div>
                    <h3 style="color:var(--fail)">Regressed</h3>
                    @forelse($delta['regressed'] as $id)<div>✗ <span class="mono">{{ $id }}</span> {{ $rows[$id]['check']['title'] }}</div>@empty<p class="muted">None.</p>@endforelse
                </div>
            </div>
        </section>
    @endif
    @endif

    @if($show('trend'))
    <section>
        <h2>Score trend</h2>
        @include('partials.trend', ['points' => $trend])
    </section>
    @endif

    @if($show('areas'))
    <section>
        <h2>Posture by area</h2>
        <table>
            <thead><tr><th>Area</th><th style="width:45%">Pass / fail</th><th>Passed</th><th>Failed</th></tr></thead>
            <tbody>
            @foreach($byCategory as $cat => $v)
                @php($tot = max(1, $v['pass'] + $v['fail']))
                <tr>
                    <td>{{ $cat }}</td>
                    <td><div class="bar"><span class="p" style="width:{{ 100 * $v['pass'] / $tot }}%"></span><span class="f" style="width:{{ 100 * $v['fail'] / $tot }}%"></span></div></td>
                    <td>{{ $v['pass'] }}</td><td>{{ $v['fail'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
    @endif

    @if($show('frameworks'))
    <section>
        <h2>Framework alignment</h2>
        <table>
            <thead><tr><th>Framework</th><th>Mapped controls</th><th>Passing</th><th>Failing</th><th>Alignment</th></tr></thead>
            <tbody>
            @foreach($frameworks as $fw)
                <tr><td>{{ $fw['label'] }}</td><td>{{ $fw['mapped'] }}</td><td>{{ $fw['pass'] }}</td><td>{{ $fw['fail'] }}</td><td><strong>{{ $fw['pct'] === null ? 'n/a' : $fw['pct'].'%' }}</strong></td></tr>
            @endforeach
            </tbody>
        </table>
        <p class="muted" style="font-size:12px">Alignment is the share of mapped, evaluable controls that passed. It is an indicator, not a certification.</p>
    </section>
    @endif

    @if($show('securescore'))
    @if($secure ?? null)
        <section>
            <h2>Microsoft Secure Score</h2>
            @if(! $secure->ok())
                <p class="muted">{{ $secure->error }}</p>
            @else
                @php($simPct = $secure->comparativePercent('TotalSeats'))
                @php($allPct = $secure->comparativePercent('AllTenants'))
                @php($top = collect($secure->openActions())->take(10))
                <div class="grid grid-3">
                    <div class="card stat"><div class="label">Secure Score</div><div class="value">{{ round($secure->percent) }}%</div>
                        <small>{{ round($secure->current_score) }} of {{ round($secure->max_score) }} points @if($securePrev?->ok())· {{ $secure->percent >= $securePrev->percent ? '+' : '' }}{{ round($secure->percent - $securePrev->percent, 1) }} since previous @endif</small></div>
                    <div class="card stat"><div class="label">Similar-size tenants</div><div class="value">{{ $simPct === null ? '–' : round($simPct).'%' }}</div>
                        <small>{{ $simPct === null ? '' : ($secure->percent >= $simPct ? 'Above' : 'Below').' peers' }}</small></div>
                    <div class="card stat"><div class="label">All tenants</div><div class="value">{{ $allPct === null ? '–' : round($allPct).'%' }}</div></div>
                </div>
                <table style="margin-top:12px">
                    <thead><tr><th>Category</th><th style="width:45%">Achieved</th><th>Points</th></tr></thead>
                    <tbody>
                    @foreach($secure->categories ?? [] as $cat => $c)
                        @php($p = $c['max'] > 0 ? round(100 * $c['score'] / $c['max']) : 0)
                        <tr><td>{{ $cat }}</td><td><div class="bar"><span class="p" style="width:{{ $p }}%"></span></div></td><td>{{ round($c['score'], 1) }} / {{ round($c['max'], 1) }} ({{ $p }}%)</td></tr>
                    @endforeach
                    </tbody>
                </table>
                @if($top->isNotEmpty())
                    <h3 style="margin-top:14px">Top improvement actions</h3>
                    <table>
                        <thead><tr><th>Action</th><th>Category</th><th>Points available</th><th>User impact</th></tr></thead>
                        <tbody>
                        @foreach($top as $a)
                            <tr><td>{{ $a['title'] }}@if($a['tg_controls'])<br><small class="muted">Addressed by TenantsGuard {{ implode(', ', $a['tg_controls']) }}</small>@endif</td><td>{{ $a['category'] }}</td><td>+{{ round($a['max'] - $a['score'], 1) }}</td><td>{{ $a['user_impact'] ?? '–' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                <p class="muted" style="font-size:12px">Microsoft Secure Score is Microsoft's own measure across Entra ID, Exchange, SharePoint, Teams and Defender. It complements the TenantsGuard posture score (configuration baseline) and the compliance framework results.</p>
            @endif
        </section>
    @endif
    @endif

    @if($show('identity') && ($overview['auth'] ?? null))
        <section>
            <h2>Identity and MFA</h2>
            @php($au = $overview['auth'])
            @php($me = $overview['methods'])
            @php($enfText = ['ca' => 'a Conditional Access policy', 'security_defaults' => 'security defaults', 'none' => 'nothing: MFA is not enforced for all users', 'unknown' => 'an unknown mechanism'][$au['enforcement']])
            <div class="grid grid-3">
                <div class="card stat"><div class="label">MFA registered</div><div class="value">{{ $au['total'] ? round($au['registered'] / $au['total'] * 100) : 0 }}%</div><small>{{ $au['registered'] }} of {{ $au['total'] }} enabled users</small></div>
                <div class="card stat"><div class="label">Phishing-resistant</div><div class="value">{{ $me && $me['total'] ? round($me['resistant'] / $me['total'] * 100) : 0 }}%</div><small>Passkeys, FIDO2, Windows Hello</small></div>
                <div class="card stat"><div class="label">Single factor only</div><div class="value">{{ $me['single'] ?? 0 }}</div><small>users with a password only</small></div>
            </div>
            <p>MFA for all users is enforced by {{ $enfText }}.@if($au['unregistered']) {{ $au['unregistered'] }} enabled user(s) have not registered an MFA method and cannot complete an MFA challenge.@endif</p>
        </section>
    @endif

    @if($show('licences') && ($overview['licences'] ?? null))
        <section>
            <h2>Licence management</h2>
            <table>
                <thead><tr><th>Subscription</th><th>Purchased</th><th>Assigned</th><th>Free</th></tr></thead>
                <tbody>
                @foreach(collect($overview['licences'])->sortByDesc('total') as $l)
                    <tr><td>{{ $l['name'] }}</td><td>{{ $l['total'] }}</td><td>{{ $l['used'] }}</td><td>{{ $l['available'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @php($free = collect($overview['licences'])->sum('available'))
            @if($free > 0)<p class="muted">{{ $free }} unassigned seat(s) across all subscriptions. Review whether they are still needed at the next renewal.</p>@endif
        </section>
    @endif

    @if($show('devices') && isset($overview['metrics']['devices']))
        <section>
            <h2>Device management</h2>
            <div class="grid grid-3">
                <div class="card stat"><div class="label">Managed devices</div><div class="value">{{ $overview['metrics']['devices'] }}</div></div>
                <div class="card stat"><div class="label">Compliant</div><div class="value">{{ $overview['metrics']['compliant'] }}</div></div>
                <div class="card stat"><div class="label">Compliance rate</div><div class="value">{{ $overview['metrics']['devices'] ? round($overview['metrics']['compliant'] / $overview['metrics']['devices'] * 100) : 0 }}%</div></div>
            </div>
        </section>
    @endif

    @if($show('compliance'))
    @if(! empty($compliance))
        <section>
            <h2>Compliance: {{ $profile->name }}</h2>
            <table>
                <thead><tr><th>Framework</th><th>Result</th><th>Met</th><th>Gaps</th><th>Evidence needed</th></tr></thead>
                <tbody>
                @foreach($compliance as $key => $fw)
                    <tr>
                        <td>{{ $fw['framework']['name'] }}</td>
                        <td><strong>
                            @if(isset($fw['maturity']))
                                Maturity Level {{ collect($fw['maturity'])->min('achieved') }} (target ML{{ $fw['target'] }})
                            @else
                                {{ $fw['score'] === null ? 'n/a' : $fw['score'].'%' }}
                            @endif
                        </strong></td>
                        <td>{{ $fw['counts']['met'] }}</td>
                        <td>{{ $fw['counts']['not_met'] + $fw['counts']['partial'] }}</td>
                        <td>{{ $fw['counts']['manual'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @foreach($compliance as $key => $fw)
                @if(isset($fw['maturity']))
                    <h3 style="margin-top:14px">{{ $fw['framework']['name'] }}: maturity by strategy</h3>
                    <table>
                        <thead><tr><th>Mitigation strategy</th><th>Achieved</th><th>Target</th></tr></thead>
                        <tbody>
                        @foreach($fw['maturity'] as $strategy => $m)
                            <tr><td>{{ $strategy }}</td><td style="color:{{ $m['achieved'] >= $fw['target'] ? 'var(--pass)' : 'var(--fail)' }}"><strong>ML{{ $m['achieved'] }}</strong></td><td>ML{{ $fw['target'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
            <p class="muted" style="font-size:12px">Automated results come from Microsoft 365 configuration scans and Intune deployments. Requirements without automated controls rely on recorded attestations. This is an assessment aid, not a certification.</p>
        </section>
    @endif
    @endif

    @if($show('findings'))
    <section>
        <h2>Priority findings and remediation</h2>
        @forelse($failures as $id => $x)
            @php($accepted = $x['annotation']?->excludesFromScore())
            <div class="failure {{ $x['check']['severity'] === 'critical' ? 'sev-c' : '' }} {{ $accepted ? 'accepted' : '' }}">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <strong><span class="mono">{{ $id }}</span> {{ $x['check']['title'] }}</strong>
                    <span><span class="badge sev-{{ $x['check']['severity'] }}">{{ ucfirst($x['check']['severity']) }}</span>
                        @if($accepted)<span class="badge s-na">{{ \App\Models\Annotation::TYPES[$x['annotation']->type] }}</span>@endif</span>
                </div>
                <div>{{ $x['result']->summary }}</div>
                <div class="muted" style="margin-top:4px"><strong>Recommendation:</strong> {{ $x['check']['remediation'] }}</div>
                @if($x['annotation'])<div style="margin-top:4px"><em>Note: {{ $x['annotation']->note }}</em></div>@endif
            </div>
        @empty
            <p>No failing controls. Excellent.</p>
        @endforelse
    </section>
    @endif

    @if($show('controls'))
    <section>
        <h2>All controls</h2>
        <table>
            <thead><tr><th>ID</th><th>Control</th><th>Severity</th><th>Status</th></tr></thead>
            <tbody>
            @foreach($rows as $id => $x)
                <tr><td class="mono">{{ $id }}</td><td>{{ $x['check']['title'] }}</td><td>{{ ucfirst($x['check']['severity']) }}</td>
                    <td><span class="badge s-{{ $x['result']->status }}">{{ $statuses[$x['result']->status] ?? $x['result']->status }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    </section>
    @endif

    <div class="footer">
        {{ $branding['report_footer'] }} · Generated {{ now()->format('F j, Y H:i') }} by {{ $branding['company_name'] }}.
        Tenant ID {{ $tenant->ms_tenant_id }}.
    </div>
</div>
</body>
</html>
