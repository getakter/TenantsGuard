@use('App\Services\Copilot\Catalog')
@php
    $stages = array_keys(Catalog::STAGES);
    $current = array_search($project->stage, $stages, true);
    $tabs = ['copilot.show' => 'Readiness', 'copilot.requirements' => 'Requirements', 'copilot.plan' => 'Plan and checklist', 'copilot.implement' => 'Licences, groups and usage'];
@endphp
<div class="dash-head">@include('partials.icon', ['name' => 'apps']) <a class="crumbs" href="{{ route('copilot.index') }}">Copilot programmes</a> <span class="muted">›</span> {{ $project->tenant->name }}
    @if(isset($project->assessment['score']))<span class="badge {{ $project->assessment['score'] >= 80 ? 's-pass' : ($project->assessment['score'] >= 50 ? 's-manual' : 's-fail') }}">Readiness {{ $project->assessment['score'] }}%</span>@endif
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
</nav>
