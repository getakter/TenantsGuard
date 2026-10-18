@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': which label do I use?')

@section('doc')
@php
    $d = $project->design;
    $examples = [
        'Public' => 'Published brochures, website text, job adverts.',
        'General' => 'Everyday emails, internal notes, meeting agendas, most documents.',
        'Confidential' => 'Client proposals, pricing, contracts, project plans, internal reports.',
        'HighlyConfidential' => 'Personal, health or payment information, HR files, legal advice, board papers, passwords.',
    ];
    $prot = ['none' => 'Marked, not encrypted', 'org' => 'Encrypted: staff only', 'user' => 'Encrypted: you choose who'];
@endphp
<div class="cover"><div class="muted">{{ $project->tenant->name }} · quick reference</div><h1>Which sensitivity label do I use?</h1></div>
<p>Ask yourself: <em>what would happen if this reached the wrong person?</em> Pick the lowest label that still protects it. When in doubt, go one level up.</p>
<table>
    <thead><tr><th>Label</th><th>Use it for</th><th>Examples</th><th>What it does</th></tr></thead>
    <tbody>
    @foreach(collect($d['labels'])->where('include', true)->sortBy('priority') as $l)
        <tr>
            <td style="white-space:nowrap"><span class="swatch" style="background:{{ $l['color'] }}"></span><strong>{{ $l['parent'] ? (collect($d['labels'])->firstWhere('key', $l['parent'])['display'] ?? '').' \ ' : '' }}{{ $l['display'] }}</strong></td>
            <td>{{ $l['tooltip'] }}</td>
            <td>{{ $l['parent'] ? '' : ($examples[$l['key']] ?? '') }}</td>
            <td>{{ $prot[$l['encryption']] }}{{ $l['header'] ? ', header "'.$l['header'].'"' : '' }}{{ $l['watermark'] ? ', watermark' : '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<h3>Good to know</h3>
<ul>
    <li>New documents and emails start as <strong>{{ collect($d['labels'])->firstWhere('key', $d['label_policy']['default_document'])['display'] ?? 'General' }}</strong>. Change it with the Sensitivity button.</li>
    @if($d['label_policy']['justification'])<li>Lowering a label asks for a short reason. This is recorded.</li>@endif
    <li>Labels on a Team or SharePoint site control who can join and whether guests are allowed.</li>
    <li>A tip about sensitive information is a reminder, not an accusation. If it is wrong, report it so the rule can be improved.</li>
</ul>
@endsection
