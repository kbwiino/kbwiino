<?php
declare(strict_types=1);

namespace App\Services\Extraction;

/** Heuristic heading detection so findings can cite sections (e.g. "3.4 Sampling"). */
final class SectionDetector
{
    public static function heading(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || mb_strlen($line) > 120) {
            return null;
        }
        if (preg_match('/^(chapter|section|appendix)\s+[\dIVXLivxl]+\b.*$/iu', $line)) {
            return $line;
        }
        // "3.4 Sampling design" / "1. Introduction" (must start with a capital letter, no trailing sentence punctuation)
        if (preg_match('/^\d+(\.\d+){0,3}\.?\s+\p{Lu}[^.!?]{2,100}$/u', $line)) {
            return $line;
        }
        if (preg_match('/^(abstract|introduction|literature review|methodology|research methodology|results|discussion|conclusion|references|bibliography|acknowledg(e)?ments)\s*$/iu', $line)) {
            return $line;
        }
        return null;
    }

    /** Split text into ~$maxChars segments on paragraph boundaries, carrying the latest heading as label. */
    public static function segmentText(string $text, ?int $page = null, ?string $inheritedSection = null, int $maxChars = 3500): array
    {
        $segments = [];
        $current = '';
        $section = $inheritedSection;
        $segSection = $section;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (($h = self::heading($line)) !== null) {
                $section = $h;
            }
            if ($current === '') {
                $segSection = $section;
            }
            $current .= $line . "\n";
            if (mb_strlen($current) >= $maxChars && trim($line) === '') {
                $segments[] = new Segment($page, $segSection, trim($current));
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $segments[] = new Segment($page, $segSection, trim($current));
        }
        return $segments;
    }
}
