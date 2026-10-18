@extends('purview._doc-layout')
@section('doctitle', $project->tenant->name.': Copilot announcement')
@use('App\Services\Copilot\Catalog')

@section('doc')
@php
    $r = $project->requirements;
@endphp
<div class="cover"><div class="muted">Draft email · edit before sending</div><h1>Microsoft 365 Copilot is coming to your apps</h1></div>
<p><strong>Subject:</strong> Your new AI assistant in Outlook, Teams, Word, Excel and PowerPoint</p>
<p>Hi everyone,</p>
<p>You now have <strong>Microsoft 365 Copilot</strong>. It helps you {{ collect($r['goals'])->map(fn ($g) => strtolower(Catalog::GOALS[$g][0] ?? $g))->implode(', ') }}, using the emails, chats, meetings and files you already have access to.</p>
<h3>What Copilot can see</h3>
<ul>
    <li>Only what <strong>you</strong> can already open. It does not give you access to anything new.</li>
    <li>Your prompts and answers stay within our Microsoft 365 tenant and are not used to train AI models.</li>
    <li>If Copilot shows you something you think you should not see, tell IT. That means a file is shared too widely, and we will fix it.</li>
</ul>
<h3>Getting started</h3>
<ol>
    <li>Look for the Copilot icon in Teams, Outlook, Word, Excel and PowerPoint (it can take a day to appear).</li>
    <li>Try a prompt from the guide, for example: "{{ Catalog::GOALS[$r['goals'][0] ?? 'meetings'][2][0] ?? 'Summarise this meeting' }}"</li>
    <li>Join the {{ ['live' => 'live training session', 'self' => 'self-paced training', 'both' => 'kick-off session'][$r['training']] }} in your invitation.</li>
    <li>Check every answer: Copilot drafts, you decide.</li>
</ol>
<p>Questions: ask your champion, or contact {{ $r['sponsor_name'] ?: 'IT' }}{{ $r['sponsor_email'] ? ' ('.$r['sponsor_email'].')' : '' }}.</p>
@endsection
