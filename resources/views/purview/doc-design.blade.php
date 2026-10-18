@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': Microsoft Purview design')
@use('App\Services\Purview\Catalog')
@use('App\Services\Purview\Designer')

@section('doc')
@php
    $r = $project->requirements;
    $d = $project->design;
    $caps = $project->capabilities ?? [];
    $gaps = Designer::gaps($r, $caps);
    $labels = collect($d['labels'])->where('include', true)->sortBy('priority');
    $dlp = collect($d['dlp'])->where('include', true);
    $ret = $d['retention'];
    $style = ['notify' => 'Policy tip and incident report', 'block_override' => 'Block external sharing; user may override with a reason', 'block' => 'Block external sharing; no override'];
    $enc = ['none' => 'Visual marking only', 'org' => 'Encrypted: organisation members can open and edit', 'user' => 'Encrypted: the user chooses who can open it'];
    $act = ['delete' => 'Delete automatically', 'startDispositionReview' => 'Disposition review, then delete', 'none' => 'Keep (no action)'];
@endphp

<div class="cover">
    @if($branding['logo_url'])<img src="{{ $branding['logo_url'] }}" alt="" style="height:40px">@endif
    <div class="muted">Data security design · Microsoft Purview</div>
    <h1>{{ $project->tenant->name }}</h1>
    <div>{{ $project->name }}</div>
    <div class="muted" style="margin-top:6px">
        Compliance owner: {{ $r['owner_name'] ?: 'to be named' }} ·
        @if($project->isApproved())Approved by {{ $project->approvedBy->name ?? '?' }} on {{ $project->design_approved_at->toFormattedDateString() }} (design {{ substr($project->design_hash, 0, 12) }})@else<strong>DRAFT: not yet approved</strong>@endif
    </div>
</div>

<section>
    <h2>1. Executive summary</h2>
    <p>{{ $project->tenant->name }} will use Microsoft Purview to know where its sensitive information is, protect it wherever it goes, keep it for as long as required, and detect risky handling. The programme follows Microsoft's recommended path: discover first in simulation, prove the controls with a pilot group, then roll out to everyone and enforce.</p>
    <ul>
        <li><strong>Protect:</strong> {{ $labels->count() }} sensitivity labels{{ $labels->contains(fn ($l) => $l['encryption'] !== 'none') ? ' (with encryption for the most sensitive content)' : '' }}, a default label{{ $d['label_policy']['mandatory'] ? ' and mandatory labelling' : '' }}.</li>
        <li><strong>Prevent loss:</strong> {{ $dlp->count() }} data loss prevention policies covering {{ $dlp->pluck('locations')->flatten()->unique()->implode(', ') ?: 'no locations' }}.</li>
        <li><strong>Govern:</strong> {{ $ret['baseline']['include'] ? 'a '.$ret['baseline']['years'].'-year baseline retention policy' : 'no baseline retention' }} and {{ collect($ret['labels'])->where('include', true)->count() }} retention labels for specific records.</li>
        <li><strong>Detect insider risk:</strong> {{ collect($d['irm']['policies'])->where('include', true)->count() ? collect($d['irm']['policies'])->where('include', true)->pluck('template')->implode(', ') : 'not in scope or not licensed' }}.</li>
    </ul>
</section>

<section>
    <h2>2. Scope and requirements</h2>
    <table>
        <tbody>
            <tr><th>Industry / jurisdiction</th><td>{{ Catalog::INDUSTRIES[$r['industry']] ?? '' }} · {{ Catalog::REGIONS[$r['region']] ?? '' }} · {{ $r['users'] }} users</td></tr>
            <tr><th>Regulations</th><td>{{ collect($r['regulations'])->map(fn ($x) => Catalog::REGULATIONS[$x][0] ?? $x)->implode('; ') ?: 'None specified' }}</td></tr>
            <tr><th>Sensitive data</th><td>{{ collect($d['data_types'])->map(fn ($x) => Catalog::DATA_TYPES[$x][0] ?? $x)->implode('; ') }}</td></tr>
            <tr><th>Locations</th><td>{{ collect($r['workloads'])->implode(', ') }} · external collaboration: {{ $r['external_sharing'] }}</td></tr>
            <tr><th>Biggest risk (customer)</th><td>{{ $r['top_risk'] ?: '–' }}</td></tr>
            <tr><th>Protection approach</th><td>Labelling: {{ $r['labelling'] }} · encryption: {{ $r['encryption'] ? 'yes' : 'no' }} · DLP: {{ $style[$r['dlp_style']] ?? $r['dlp_style'] }}</td></tr>
        </tbody>
    </table>
</section>

<section>
    <h2>3. Licensing</h2>
    <table>
        <thead><tr><th>Capability</th><th>Status</th><th>Licence needed</th></tr></thead>
        <tbody>
        @foreach(Catalog::CAPABILITIES as $cap => [$label, , $needs])
            <tr><td>{{ $label }}</td><td>{{ ($caps[$cap] ?? null) === true ? 'Licensed' : (($caps[$cap] ?? null) === false ? 'Not licensed' : 'Unknown') }}</td><td>{{ $needs }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if($gaps)<p><strong>Gaps affecting this design:</strong> {{ collect($gaps)->map(fn ($g) => $g['label'].' ('.$g['needs'].')')->implode('; ') }}.</p>@endif
</section>

<section>
    <h2>4. Architecture decisions</h2>
    <ol>@foreach($d['decisions'] as [$t, $text])<li><strong>{{ $t }}.</strong> {{ $text }}</li>@endforeach</ol>
</section>

<section>
    <h2>5. Information protection: sensitivity labels</h2>
    <table>
        <thead><tr><th>Label</th><th>Use it for</th><th>Protection</th><th>Marking</th><th>Teams / sites</th></tr></thead>
        <tbody>
        @foreach($labels as $l)
            <tr>
                <td style="white-space:nowrap"><span class="swatch" style="background:{{ $l['color'] }}"></span>{{ $l['parent'] ? collect($d['labels'])->firstWhere('key', $l['parent'])['display'].' \ ' : '' }}<strong>{{ $l['display'] }}</strong></td>
                <td>{{ $l['tooltip'] }}</td>
                <td>{{ $enc[$l['encryption']] }}</td>
                <td>{{ collect([$l['header'] ? 'Header "'.$l['header'].'"' : null, $l['watermark'] ? 'Watermark "'.$l['watermark'].'"' : null])->filter()->implode(', ') ?: '–' }}</td>
                <td>{{ $l['container'] ? $l['container']['privacy'].'; guests '.($l['container']['guests'] ? 'allowed' : 'blocked').'; sharing: '.(\App\Services\Purview\Catalog::SHARING[$l['container']['sharing']] ?? $l['container']['sharing']) : '–' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p><strong>Label policy:</strong> default label "{{ collect($d['labels'])->firstWhere('key', $d['label_policy']['default_document'])['display'] ?? '' }}" for documents and email; justification {{ $d['label_policy']['justification'] ? 'required' : 'not required' }} to lower a label; mandatory labelling {{ $d['label_policy']['mandatory'] ? 'in production' : 'off' }}.</p>
    @if($d['autolabel'])
        <p><strong>Automatic labelling:</strong></p>
        <ul>@foreach(collect($d['autolabel'])->where('include', true) as $a)<li>{{ $a['name'] }} → {{ $a['label'] }}: {{ $a['sits'] ? implode(', ', $a['sits']) : implode(', ', $a['classifiers'] ?? []) }}. Simulation first, then enabled after review.</li>@endforeach</ul>
    @endif
</section>

<section>
    <h2>6. Data loss prevention</h2>
    @if($dlp->isEmpty())<p>No DLP policies in scope.</p>@else
    <table>
        <thead><tr><th>Policy</th><th>Detects</th><th>Locations</th><th>Low volume</th><th>High volume (≥ threshold)</th></tr></thead>
        <tbody>
        @foreach($dlp as $x)
            <tr><td><strong>{{ $x['name'] }}</strong><br><span class="note">{{ $x['purpose'] }}</span></td><td>{{ implode(', ', $x['sits']) }}</td><td>{{ implode(', ', $x['locations']) }}</td>
                <td>{{ $x['high_threshold'] > 1 ? 'Policy tip, low-severity report' : '–' }}</td><td>{{ $x['high_threshold'] }}+ instances: {{ $style[$x['style']] }}; high-severity alert to {{ $x['incident_email'] ?: 'the compliance owner' }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="note">Every policy runs in simulation for everyone first (at least 14 days), then shows tips to the pilot group, then is enforced after the pilot exit is signed.</p>
    @endif
</section>

<section>
    <h2>7. Data lifecycle: retention</h2>
    <ul>
        @if($ret['baseline']['include'])<li><strong>Baseline:</strong> email, SharePoint, OneDrive and Microsoft 365 group content is kept for {{ $ret['baseline']['years'] }} years{{ $ret['baseline']['delete'] ? ' and then deleted' : ' (not deleted automatically)' }}.</li>@endif
        @if($ret['teams']['include'])<li><strong>Teams:</strong> chats and channel messages are kept for {{ $ret['teams']['years'] }} years.</li>@endif
    </ul>
    <table>
        <thead><tr><th>Retention label</th><th>Period</th><th>From</th><th>At the end</th><th>Record</th><th>Applies to</th></tr></thead>
        <tbody>
        @foreach(collect($ret['labels'])->where('include', true) as $l)
            <tr><td><strong>{{ $l['name'] }}</strong></td><td>{{ $l['years'] }} years</td><td>last modified</td><td>{{ $act[$l['action']] ?? $l['action'] }}</td><td>{{ $l['record'] ? 'Yes' : 'No' }}</td><td>{{ $l['description'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="note">Retention periods are starting points based on common requirements. Confirm them with legal counsel before production.</p>
</section>

<section>
    <h2>8. Insider risk management</h2>
    @if(! $d['irm']['policies'])<p>Not in scope.</p>@else
        <ul>@foreach($d['irm']['policies'] as $p)<li><strong>{{ $p['template'] }}</strong>{{ $p['include'] ? '' : ' (not licensed: planned only)' }}: {{ $p['purpose'] }}</li>@endforeach</ul>
        <p><strong>Privacy safeguards:</strong> usernames pseudonymised for analysts; investigators separate from IT administrators; policies are switched on only after {{ $d['irm']['privacy_approved'] ? 'the recorded legal and HR approval' : 'legal and HR approval (NOT yet recorded)' }}.</p>
    @endif
</section>

<section>
    <h2>9. Rollout plan</h2>
    <table>
        <thead><tr><th>Phase</th><th>Task</th><th>Who</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
        @foreach($project->tasks->where('stale', false) as $t)
            <tr><td>{{ Catalog::STAGES[$t->phase][0] }}</td><td>{{ $t->title }}</td><td>{{ $t->who }}</td><td style="white-space:nowrap">{{ $t->due_date?->format('j M Y') }}</td><td>{{ \App\Models\PurviewTask::STATUS[$t->status][0] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>

<section>
    <h2>10. Roles and responsibilities (RACI)</h2>
    <table>
        <thead><tr><th>Activity</th><th>Compliance owner</th><th>Purview administrator</th><th>IT / helpdesk</th><th>HR and legal</th></tr></thead>
        <tbody>
            <tr><td>Requirements and design approval</td><td>A</td><td>R</td><td>C</td><td>C</td></tr>
            <tr><td>Label, DLP and retention configuration</td><td>I</td><td>A / R</td><td>C</td><td>–</td></tr>
            <tr><td>Pilot and production gates</td><td>A</td><td>R</td><td>C</td><td>I</td></tr>
            <tr><td>DLP alert triage</td><td>A</td><td>R</td><td>C</td><td>I</td></tr>
            <tr><td>Insider risk investigations</td><td>A</td><td>–</td><td>–</td><td>R</td></tr>
            <tr><td>User training and support</td><td>A</td><td>C</td><td>R</td><td>–</td></tr>
            <tr><td>Retention schedule review</td><td>A</td><td>R</td><td>–</td><td>C</td></tr>
        </tbody>
    </table>
    <p class="note">R responsible · A accountable · C consulted · I informed. Compliance owner: {{ $r['owner_name'] ?: 'to be named' }}.</p>
</section>

<section>
    <h2>11. Risks and mitigations</h2>
    <table>
        <thead><tr><th>Risk</th><th>Mitigation</th></tr></thead>
        <tbody>
            <tr><td>Controls block legitimate work</td><td>Simulation for everyone, pilot group first, overrides with justification during early production, signed exit gates.</td></tr>
            <tr><td>Too many false positives</td><td>Instance thresholds, tuning from simulation results, monthly review of overrides.</td></tr>
            <tr><td>Encrypted files cannot be opened by partners</td><td>Unencrypted "Anyone" option for partner sharing; "Specified People" for controlled external access; co-authoring enabled.</td></tr>
            <tr><td>Wrong labels / low adoption</td><td>Short label set with clear tooltips, default label, training and a one-page guide, adoption review in Activity explorer.</td></tr>
            <tr><td>Data deleted too early</td><td>Baseline policy keeps content; deletion only through labelled record classes{{ collect($ret['labels'])->contains('action', 'startDispositionReview') ? ' with disposition review' : '' }}.</td></tr>
            <tr><td>Employee privacy concerns</td><td>Insider risk only after legal/HR approval, pseudonymisation, separate investigator roles, audit of investigator activity.</td></tr>
            <tr><td>Licence gaps</td><td>{{ $gaps ? 'Design excludes unlicensed features; add-on recommended: '.collect($gaps)->pluck('needs')->unique()->implode('; ') : 'None identified.' }}</td></tr>
        </tbody>
    </table>
</section>

<section>
    <h2>12. Operating model</h2>
    <ul>
        <li>Weekly: triage DLP{{ $d['irm']['policies'] ? ' and insider risk' : '' }} alerts.</li>
        <li>Monthly: label adoption, overrides and false-positive review; tune policies.</li>
        <li>Quarterly: review Purview role assignments.</li>
        <li>Annually: review this design against requirements, regulations and the retention schedule.</li>
    </ul>
    <p class="note">Policy names in the tenant start with "{{ Designer::PREFIX }}". Configuration is deployed with generated, idempotent PowerShell and verified against the tenant by TenantsGuard. Cmdlet behaviour and sensitive information type names change over time; scripts skip unknown types and every step is verified after deployment.</p>
</section>
@endsection
