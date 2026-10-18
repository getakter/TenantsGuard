<?php

namespace App\Support;

/** Minimal line diff (LCS) for reviewing script changes. */
final class LineDiff
{
    /** @return array<int, array{0: string, 1: string}> [op (' ', '+', '-'), line] */
    public static function diff(string $old, string $new): array
    {
        $a = preg_split('/\r\n|\r|\n/', rtrim($old, "\n"));
        $b = preg_split('/\r\n|\r|\n/', rtrim($new, "\n"));
        if (count($a) * count($b) > 4_000_000) {
            return array_merge(array_map(fn ($l) => ['-', $l], $a), array_map(fn ($l) => ['+', $l], $b));
        }
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['-', $a[$i++]];
            } else {
                $out[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['+', $b[$j++]];
        }

        return $out;
    }
}
