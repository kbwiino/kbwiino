<?php
declare(strict_types=1);

namespace App\Agents;

/** Renders extracted segments with page/section markers so the model can cite locations. */
final class DocumentRenderer
{
    public static function segment(array $s): string
    {
        $tag = [];
        if ($s['page'] !== null) $tag[] = 'Page ' . $s['page'];
        if (!empty($s['section'])) $tag[] = 'Section: ' . $s['section'];
        return ($tag ? '[' . implode(' | ', $tag) . "]\n" : '') . $s['content'];
    }

    public static function render(array $segments): string
    {
        return implode("\n\n", array_map([self::class, 'segment'], $segments));
    }

    /** Plain text (no markers) used to verify quoted evidence. */
    public static function plain(array $segments): string
    {
        return implode("\n", array_column($segments, 'content'));
    }

    /**
     * Group segments into windows no larger than $budget characters.
     * @return array<int,array<int,array>>
     */
    public static function windows(array $segments, int $budget): array
    {
        $windows = [];
        $cur = [];
        $size = 0;
        foreach ($segments as $s) {
            $len = mb_strlen($s['content']) + 60;
            if ($cur && $size + $len > $budget) {
                $windows[] = $cur;
                $cur = [];
                $size = 0;
            }
            $cur[] = $s;
            $size += $len;
        }
        if ($cur) {
            $windows[] = $cur;
        }
        return $windows;
    }

    public static function pageRange(array $segments): string
    {
        $pages = array_filter(array_column($segments, 'page'), fn($p) => $p !== null);
        if ($pages) {
            return 'pages ' . min($pages) . '-' . max($pages);
        }
        $secs = array_values(array_filter(array_column($segments, 'section')));
        return $secs ? 'sections "' . mb_substr($secs[0], 0, 60) . '" to "' . mb_substr(end($secs), 0, 60) . '"' : 'a consecutive part';
    }
}
