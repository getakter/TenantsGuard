@use('App\Services\Purview\Catalog')
@php
    $stages = array_keys(Catalog::STAGES);
    $current = array_search($project->stage, $stages, true);
    $tabs = ['purview.show' => 'Overview', 'purview.requirements' => 'Requirements', 'purview.design' => 'Design', 'purview.plan' => 'Plan and checklist', 'purview.implement' => 'Implement and verify'];
@endphp
<div class="dash-head">@include('partials.icon', ['name' => 'lock']) <a class="crumbs" href="{{ route('purview.index') }}">Purview programmes</a> <span class="muted">›</span> {{ $project->tenant->name }}
    @if($project->isApproved())<span class="badge s-pass">Design approved</span>@elseif($project->design)<span class="badge s-manual">Design not approved</span>@endif
</div>
<h1 style="margin:6px 0 12px">{{ $project->name }}</h1>
<div class="stepper">
    @foreach(Catalog::STAGES as $key => [$label, $desc])
        <div class="stp {{ $loop->index < $current ? 'done' : ($loop->index === $current ? 'now' : '') }}" title="{{ $desc }}">
            <span class="dot">{{ $loop->index < $current ? '✓' : $loop->iteration }}</span><span>{{ $label }}</span>
        </div>
    @endforeach
</div>
<nav class="dash-tabs">
    @foreach($tabs as $route => $label)
        <a href="{{ route($route, $project) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
    @if($project->design)
        <a href="{{ route('purview.document', [$project, 'design']) }}" target="_blank">Design document ↗</a>
    @endif
</nav>
