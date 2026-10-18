{{-- Simple Sankey diagram. Params: $nodes [[id, label, col, value, color]], $links [[from, to, value]], $w, $h --}}
@php
    $w = $w ?? 640;
    $h = $h ?? 250;
    $nw = 12;
    $gap = 18;
    $padY = 10;
    $nodes = collect($nodes)->filter(fn ($n) => $n[3] > 0)->keyBy(0);
    $links = collect($links)->filter(fn ($l) => $l[2] > 0 && $nodes->has($l[0]) && $nodes->has($l[1]));
    $cols = $nodes->groupBy(fn ($n) => $n[2])->sortKeys();
    $ncols = max(1, $cols->count());
    $maxTotal = max(1, $cols->map(fn ($c) => $c->sum(fn ($n) => $n[3]))->max());
    $maxCount = max(1, $cols->map->count()->max());
    $scale = ($h - 2 * $padY - ($maxCount - 1) * $gap) / $maxTotal;
    $pos = [];
    foreach ($cols as $ci => $col) {
        $x = $ncols === 1 ? 0 : ($ci / ($ncols - 1)) * ($w - $nw);
        $used = $col->sum(fn ($n) => max(3, $n[3] * $scale)) + ($col->count() - 1) * $gap;
        $y = $padY + ($h - 2 * $padY - $used) / 2;
        foreach ($col as $n) {
            $nh = max(3, $n[3] * $scale);
            $pos[$n[0]] = ['x' => $x, 'y' => $y, 'h' => $nh, 'out' => 0, 'in' => 0];
            $y += $nh + $gap;
        }
    }
    $uid = 'sk'.substr(md5(json_encode($nodes).mt_rand()), 0, 6);
@endphp
<svg class="svg-chart sankey" viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ $aria ?? 'Flow diagram' }}">
    <defs>
        @foreach($links as $i => [$from, $to])
            <linearGradient id="{{ $uid }}g{{ $i }}" x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" stop-color="{{ $nodes[$from][4] }}" stop-opacity=".55"/>
                <stop offset="1" stop-color="{{ $nodes[$to][4] }}" stop-opacity=".55"/>
            </linearGradient>
        @endforeach
    </defs>
    @foreach($links as $i => [$from, $to, $v])
        @php
            $s = &$pos[$from];
            $t = &$pos[$to];
            $lh = max(1.5, $v * $scale);
            $sy = $s['y'] + $s['out'];
            $ty = $t['y'] + $t['in'];
            $s['out'] += $lh;
            $t['in'] += $lh;
            $x0 = $s['x'] + $nw;
            $x1 = $t['x'];
            $mx = ($x0 + $x1) / 2;
            unset($s, $t);
        @endphp
        <path d="M{{ $x0 }},{{ $sy }} C{{ $mx }},{{ $sy }} {{ $mx }},{{ $ty }} {{ $x1 }},{{ $ty }} L{{ $x1 }},{{ $ty + $lh }} C{{ $mx }},{{ $ty + $lh }} {{ $mx }},{{ $sy + $lh }} {{ $x0 }},{{ $sy + $lh }} Z" fill="url(#{{ $uid }}g{{ $i }})"><title>{{ $nodes[$from][1] }} → {{ $nodes[$to][1] }}: {{ $v }}</title></path>
    @endforeach
    @foreach($nodes as $id => [, $label, $col, $value, $color])
        @php
            $p = $pos[$id];
            $last = $col === $cols->keys()->last();
        @endphp
        <rect x="{{ $p['x'] }}" y="{{ $p['y'] }}" width="{{ $nw }}" height="{{ $p['h'] }}" rx="2" fill="{{ $color }}"><title>{{ $label }}: {{ $value }}</title></rect>
        <text x="{{ $last ? $p['x'] - 8 : $p['x'] + $nw + 8 }}" y="{{ $p['y'] + $p['h'] / 2 + 4 }}" text-anchor="{{ $last ? 'end' : 'start' }}">{{ $label }} ({{ $value }})</text>
    @endforeach
</svg>
