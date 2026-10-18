{{-- Score trend line. Params: $points (collection of scans with score, created_at) --}}
@php
    $pts = $points->values();
    $w = 600; $h = 120; $pad = 18;
    $n = $pts->count();
    $coords = $pts->map(function ($s, $i) use ($n, $w, $h, $pad) {
        $x = $n > 1 ? $pad + ($w - 2 * $pad) * $i / ($n - 1) : $w / 2;
        $y = $h - $pad - ($h - 2 * $pad) * $s->score / 100;
        return [round($x, 1), round($y, 1), $s];
    });
@endphp
<div class="trend">
@if($n === 0)
    <div class="empty">No scans yet.</div>
@else
    <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="Score trend">
        @foreach([0, 50, 100] as $g)
            @php($gy = $h - $pad - ($h - 2 * $pad) * $g / 100)
            <line x1="{{ $pad }}" x2="{{ $w - $pad }}" y1="{{ $gy }}" y2="{{ $gy }}" style="stroke:var(--chart-grid)" stroke-dasharray="3 4"/>
            <text x="2" y="{{ $gy + 4 }}" font-size="10" style="fill:var(--muted)">{{ $g }}</text>
        @endforeach
        <polyline fill="none" style="stroke:var(--accent)" stroke-width="2.5" vector-effect="non-scaling-stroke"
                  points="{{ $coords->map(fn ($c) => $c[0].','.$c[1])->implode(' ') }}"/>
        @foreach($coords as [$x, $y, $s])
            <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" style="fill:var(--accent)"><title>{{ $s->created_at->format('M j, H:i') }}: {{ $s->score }}%</title></circle>
        @endforeach
    </svg>
@endif
</div>
