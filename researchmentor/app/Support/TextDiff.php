<?php
declare(strict_types=1);

namespace App\Support;

/** Dependency-free LCS diff (word or line mode) with size caps so it cannot be used for DoS. */
final class TextDiff
{
    private const MAX_TOKENS = 4000;

    /** @return array<int,array{op:string,text:string}> op: eq|add|del */
    public static function words(string $a, string $b): array
    {
        return self::diff(self::tokenize($a, '/(\s+)/u'), self::tokenize($b, '/(\s+)/u'));
    }

    public static function lines(string $a, string $b): array
    {
        return self::diff(self::tokenize($a, '/(\R)/u'), self::tokenize($b, '/(\R)/u'));
    }

    private static function tokenize(string $s, string $splitter): array
    {
        return preg_split($splitter, $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function diff(array $x, array $y): array
    {
        // Trim common prefix/suffix first (cheap, shrinks the quadratic part).
        $prefix = [];
        while ($x && $y && $x[0] === $y[0]) {
            $prefix[] = ['op' => 'eq', 'text' => array_shift($x)];
            array_shift($y);
        }
        $suffix = [];
        while ($x && $y && end($x) === end($y)) {
            array_unshift($suffix, ['op' => 'eq', 'text' => array_pop($x)]);
            array_pop($y);
        }
        if (count($x) > self::MAX_TOKENS || count($y) > self::MAX_TOKENS) {
            // Too large for LCS: report as wholesale replacement.
            $mid = [];
            if ($x) $mid[] = ['op' => 'del', 'text' => implode('', $x)];
            if ($y) $mid[] = ['op' => 'add', 'text' => implode('', $y)];
            return self::merge(array_merge($prefix, $mid, $suffix));
        }
        $n = count($x);
        $m = count($y);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $x[$i] === $y[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($x[$i] === $y[$j]) {
                $ops[] = ['op' => 'eq', 'text' => $x[$i]]; $i++; $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['op' => 'del', 'text' => $x[$i++]];
            } else {
                $ops[] = ['op' => 'add', 'text' => $y[$j++]];
            }
        }
        while ($i < $n) $ops[] = ['op' => 'del', 'text' => $x[$i++]];
        while ($j < $m) $ops[] = ['op' => 'add', 'text' => $y[$j++]];
        return self::merge(array_merge($prefix, $ops, $suffix));
    }

    /** Merge adjacent same-op tokens. */
    private static function merge(array $ops): array
    {
        $out = [];
        foreach ($ops as $op) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['op'] === $op['op']) {
                $out[$last]['text'] .= $op['text'];
            } else {
                $out[] = $op;
            }
        }
        return $out;
    }

    public static function stats(array $ops): array
    {
        $add = $del = 0;
        foreach ($ops as $o) {
            if ($o['op'] === 'add') $add += max(1, str_word_count($o['text']));
            if ($o['op'] === 'del') $del += max(1, str_word_count($o['text']));
        }
        return ['added_words' => $add, 'removed_words' => $del, 'changed' => $add + $del > 0];
    }
}
