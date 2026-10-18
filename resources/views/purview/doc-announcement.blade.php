@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': data protection announcement')

@section('doc')
@php
    $d = $project->design;
    $labels = collect($d['labels'])->where('include', true)->whereNull('parent')->sortBy('priority');
    $pilotStart = \Carbon\Carbon::parse($project->requirements['start_date'])->addDays(25);
    $owner = $project->requirements['owner_name'] ?: 'the compliance team';
@endphp
<div class="cover"><div class="muted">Draft email for staff · edit before sending</div><h1>Protecting our information: what changes and what you need to do</h1></div>
<p><strong>Subject:</strong> New labels in Outlook, Word, Excel, PowerPoint and Teams from {{ $pilotStart->format('j F') }}</p>
<p>Hi everyone,</p>
<p>We handle information that our clients and colleagues trust us with. To protect it, we are introducing <strong>sensitivity labels</strong> and <strong>data loss prevention</strong> in Microsoft 365. A small pilot group starts on {{ $pilotStart->format('j F') }}, and everyone follows a few weeks later.</p>
<h3>What you will see</h3>
<ul>
    <li>A <strong>Sensitivity</strong> button in Outlook, Word, Excel, PowerPoint and Teams. New documents and emails start as <strong>{{ collect($d['labels'])->firstWhere('key', $d['label_policy']['default_document'])['display'] ?? 'General' }}</strong>.</li>
    <li>Labels:
        <ul>@foreach($labels as $l)<li><strong>{{ $l['display'] }}</strong>: {{ $l['tooltip'] }}</li>@endforeach</ul>
    </li>
    @if($d['dlp'])<li>A short <strong>tip</strong> if you are about to share information such as {{ collect($d['dlp'])->where('include', true)->pluck('name')->map(fn ($n) => strtolower(str_replace(\App\Services\Purview\Designer::PREFIX, '', $n)))->implode(', ') }} outside the organisation.@if(collect($d['dlp'])->contains('style', 'block_override')) In some cases sharing is blocked, and you can give a business reason to continue.@endif</li>@endif
    @if(collect($d['labels'])->contains(fn ($l) => $l['include'] && $l['encryption'] !== 'none'))<li>Some labels <strong>encrypt</strong> the file or email, so only the right people can open it, even if it is forwarded.</li>@endif
</ul>
<h3>What you need to do</h3>
<ol>
    <li>Choose the right label when you create or share something sensitive. If you are unsure, see the attached one-page guide.</li>
    <li>If a tip or block looks wrong, use the <strong>report</strong> option in the tip or tell the helpdesk. That is how we tune the rules.</li>
    <li>Join the 15-minute session we will send an invitation for.</li>
</ol>
<p>Nothing changes about where you store files or how you work with colleagues. Questions: contact {{ $owner }}{{ $project->requirements['owner_email'] ? ' ('.$project->requirements['owner_email'].')' : '' }} or the helpdesk.</p>
<p>Thank you for helping us keep our information safe.</p>
@endsection
