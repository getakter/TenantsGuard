{{-- Score ring. Params: $score (int|null), $size (px) --}}
@php
    $size = $size ?? 64;
    $r = $size / 2 - 5;
    $circ = 2 * M_PI * $r;
    $val = $score ?? 0;
    $color = $score === null ? 'var(--muted)' : ($score >= 80 ? 'var(--pass)' : ($score >= 50 ? 'var(--warn)' : 'var(--fail)'));
@endphp
<svg class="ring" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" role="img" aria-label="Score {{ $score ?? 'not scanned' }}">
    <circle cx="{{ $size/2 }}" cy="{{ $size/2 }}" r="{{ $r }}" fill="none" style="stroke:var(--surface-3)" stroke-width="7"/>
    <circle cx="{{ $size/2 }}" cy="{{ $size/2 }}" r="{{ $r }}" fill="none" style="stroke:{{ $color }}" stroke-width="7" stroke-linecap="round"
            stroke-dasharray="{{ $circ }}" stroke-dashoffset="{{ $circ * (1 - $val / 100) }}" transform="rotate(-90 {{ $size/2 }} {{ $size/2 }})"/>
    <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" font-size="{{ $size * 0.26 }}">{{ $score === null ? '–' : $score }}</text>
</svg>
