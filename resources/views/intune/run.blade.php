@extends('layouts.app')
@section('title', ($first->dry_run ? 'Preview' : 'Deployment').': '.$first->bundle_name)
@use('App\Models\IntuneDeploymentItem')

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $first->dry_run ? 'Preview' : 'Deployment' }}: {{ $first->bundle_name }}</h1>
        <p>{{ $first->created_at->format('M j, Y H:i') }} by {{ $first->user?->name ?? 'System' }}{{ $first->update_existing ? ' · update existing' : '' }}</p>
    </div>
    <div class="actions">
        @if($first->dry_run && ! $active && $bundle && auth()->user()->can('intune.deploy'))
            <form method="post" action="{{ route('intune.bundles.deploy.run', $bundle) }}" data-confirm="Deploy now to {{ $deployments->count() }} tenant(s)?">
                @csrf
                @foreach($deployments as $d)<input type="hidden" name="tenant_ids[]" value="{{ $d->tenant_id }}">@endforeach
                <input type="hidden" name="update_existing" value="{{ $first->update_existing ? 1 : 0 }}">
                <button class="btn btn-primary" name="mode" value="deploy" data-busy="Deploying…">Deploy this plan</button>
            </form>
        @endif
        <a class="btn" href="{{ route('intune.index') }}#deployments">All deployments</a>
    </div>
</div>

@if($active)
    <div class="alert alert-info run-progress" role="status" aria-live="polite">
        <span class="spinner" aria-hidden="true"></span>
        <div><strong>{{ $first->dry_run ? 'Preview' : 'Deployment' }} in progress</strong> · {{ $done }} of {{ $total }} items handled. It runs in the background, so you can leave this page; it refreshes every few seconds.</div>
    </div>
    <script>setTimeout(() => location.reload(), 4000);</script>
@endif

@foreach($deployments as $d)
    <div class="card">
        <div class="page-head" style="margin-bottom:8px">
            <div>
                <h2 style="margin:0">{{ $d->tenant?->name ?? 'Deleted tenant' }}
                    @if($d->status === 'queued')<span class="badge s-na">Waiting to start</span>@elseif($d->status === 'running')<span class="badge s-info">Running</span>@endif</h2>
                <div class="pills">
                    @foreach($d->summary ?? [] as $k => $n)
                        <span class="badge {{ IntuneDeploymentItem::STATUS_LABELS[$k][1] ?? 's-na' }}">{{ $n }} {{ strtolower(IntuneDeploymentItem::STATUS_LABELS[$k][0] ?? $k) }}</span>
                    @endforeach
                </div>
            </div>
            @if($d->tenant)<a class="btn btn-sm" href="{{ route('tenants.intune', $d->tenant) }}">Tenant Intune status</a>@endif
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Template</th><th>Policy</th><th>Status</th><th>Assignment</th><th>Details</th></tr></thead>
                <tbody>
                @foreach($d->items->sortBy(fn ($i) => array_search($i->status, ['failed', 'manual', 'skipped', 'would_create', 'would_update', 'created', 'updated', 'exists', 'ready'])) as $item)
                    <tr>
                        <td class="mono">{{ $item->template }}</td>
                        <td>{{ $item->display_name }}</td>
                        <td><span class="badge {{ IntuneDeploymentItem::STATUS_LABELS[$item->status][1] ?? 's-na' }}">{{ IntuneDeploymentItem::STATUS_LABELS[$item->status][0] ?? $item->status }}</span></td>
                        <td>{{ $item->assignment ?? '–' }}</td>
                        <td style="font-size:13px">{{ $item->message }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach
@endsection
