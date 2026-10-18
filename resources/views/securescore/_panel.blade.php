{{-- Microsoft Secure Score summary. Params: $snap (?SecureScoreSnapshot), $prev (?SecureScoreSnapshot), $tenant, $compact (bool) --}}
@php
    $compact = $compact ?? false;
@endphp
<div class="card ss-panel">
    <div class="page-head" style="margin-bottom:6px">
        <div>
            <div class="label-sm">Microsoft Secure Score</div>
            @if($snap && $snap->ok())
                <div style="display:flex;align-items:baseline;gap:8px">
                    <span class="value-lg" style="color:{{ $snap->percent >= 70 ? 'var(--pass)' : ($snap->percent >= 40 ? 'var(--warn)' : 'var(--fail)') }}">{{ round($snap->percent) }}%</span>
                    <span class="muted">{{ rtrim(rtrim(number_format($snap->current_score, 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($snap->max_score, 2), '0'), '.') }} points</span>
                    @if($prev && $prev->ok() && round($snap->percent - $prev->percent, 1) != 0)
                        <small style="color:{{ $snap->percent > $prev->percent ? 'var(--pass)' : 'var(--fail)' }}">{{ $snap->percent > $prev->percent ? '▲' : '▼' }}{{ abs(round($snap->percent - $prev->percent, 1)) }} pts</small>
                    @endif
                </div>
            @endif
        </div>
        @if(! $compact && $snap && $snap->ok())
            <a class="btn btn-sm" href="https://security.microsoft.com/securescore" target="_blank" rel="noopener">Open in Microsoft Defender</a>
        @endif
    </div>

    @if(! $snap)
        <p class="muted">Not captured yet. It is read from Microsoft on the next scan.</p>
    @elseif(! $snap->ok())
        <div class="alert alert-warn" style="margin:0">{{ $snap->error }}</div>
    @else
        @php
            $all = $snap->comparativePercent('AllTenants');
            $similar = $snap->comparativePercent('TotalSeats');
            $hist = collect($snap->history ?? [])->filter(fn ($h) => $h['max'] > 0)->values();
        @endphp
        <div class="ss-compare">
            @if($similar !== null)<span title="Average Secure Score of tenants with a similar number of seats">Similar-size tenants <strong>{{ round($similar) }}%</strong></span>@endif
            @if($all !== null)<span title="Average across all Microsoft 365 tenants">All tenants <strong>{{ round($all) }}%</strong></span>@endif
            <span class="muted">Calculated {{ $snap->ms_created_at?->diffForHumans() }}</span>
        </div>

        @if($hist->count() > 1)
            @php
                $w = 300; $h = 46;
                $pts = $hist->map(fn ($p, $i) => round($i * $w / max(1, $hist->count() - 1), 1).','.round($h - 4 - ($h - 8) * ($p['score'] / $p['max']), 1))->implode(' ');
            @endphp
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="spark" role="img" aria-label="Secure Score over the last {{ $hist->count() }} days">
                <polyline fill="none" stroke="var(--accent)" stroke-width="2" vector-effect="non-scaling-stroke" points="{{ $pts }}"/>
                <title>{{ $hist->first()['date'] }}: {{ round(100 * $hist->first()['score'] / $hist->first()['max']) }}% → {{ $hist->last()['date'] }}: {{ round(100 * $hist->last()['score'] / $hist->last()['max']) }}%</title>
            </svg>
            <div class="muted" style="font-size:11px;display:flex;justify-content:space-between"><span>{{ $hist->count() }} days ago</span><span>today</span></div>
        @endif

        <table class="ss-cats">
            <tbody>
            @foreach($snap->categories ?? [] as $cat => $c)
                @php
                    $pct = $c['max'] > 0 ? round(100 * $c['score'] / $c['max']) : 0;
                @endphp
                <tr>
                    <td style="width:90px">{{ $cat }}</td>
                    <td><div class="bar"><span class="p" style="width:{{ $pct }}%"></span></div></td>
                    <td style="width:110px;text-align:right" class="muted">{{ round($c['score'], 1) }}/{{ round($c['max'], 1) }} ({{ $pct }}%)</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($compact)
            @php
                $fixable = collect($snap->openActions())->filter(fn ($a) => $a['tg_controls'])->sum(fn ($a) => $a['max'] - $a['score']);
            @endphp
            <p style="margin:8px 0 0;font-size:13px">
                <strong>{{ round($fixable) }} points</strong> available from actions TenantsGuard can help with.
                <a href="{{ route('tenants.securescore', $tenant) }}">See prioritised actions</a>
            </p>
        @endif
    @endif
</div>
