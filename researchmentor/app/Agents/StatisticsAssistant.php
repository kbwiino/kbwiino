<?php
declare(strict_types=1);

namespace App\Agents;

use App\Services\Extraction\TextExtractor;
use App\Support\FileStorage;

/**
 * Statistics Assistant (MVP): descriptive summaries computed in PHP (never by the AI), plus AI help to
 * interpret supplied tables. It is not a replacement for SPSS, SmartPLS or R.
 */
final class StatisticsAssistant extends AbstractReviewAgent
{
    public function type(): string { return 'statistics'; }
    public function label(): string { return 'Statistics assistant'; }

    public function variables(AiRunContext $ctx, string $documentText): array
    {
        $summary = 'No tabular dataset was supplied; interpret only the statistical text provided.';
        $v = $ctx->version;
        if ($v && in_array($v['extension'], ['csv', 'xlsx'], true) && $v['file_deleted_at'] === null) {
            try {
                $rows = self::readRows(FileStorage::path($v['stored_filename']), $v['extension']);
                $summary = self::describe($rows);
            } catch (\Throwable $e) {
                $summary = 'The dataset could not be summarised automatically: ' . $e->getMessage();
            }
        }
        return ['computed_summary' => $summary];
    }

    /** @return array<int,array<int,string>> */
    public static function readRows(string $path, string $ext): array
    {
        if ($ext === 'xlsx') {
            $sheets = TextExtractor::xlsxSheets($path, 20001);
            $first = reset($sheets);
            return $first ?: [];
        }
        $rows = [];
        $fh = fopen($path, 'rb');
        $first = fgets($fh) ?: '';
        rewind($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false && count($rows) < 20001) {
            if ($r === [null]) continue;
            $rows[] = array_map(fn($c) => (string) $c, $r);
        }
        fclose($fh);
        return $rows;
    }

    /** Deterministic descriptive statistics, rendered as text for the prompt and for display. */
    public static function describe(array $rows): string
    {
        if (count($rows) < 2) {
            return 'The dataset has no data rows.';
        }
        $header = array_map(fn($h, $i) => trim($h) !== '' ? trim($h) : 'col' . ($i + 1), $rows[0], array_keys($rows[0]));
        $header = array_slice($header, 0, 60);
        $data = array_slice($rows, 1, 20000);
        $n = count($data);
        $lines = ["Rows (excluding header): {$n}; columns: " . count($header)];
        foreach ($header as $ci => $name) {
            $vals = [];
            $missing = 0;
            foreach ($data as $r) {
                $v = trim($r[$ci] ?? '');
                if ($v === '' || strtolower($v) === 'na' || strtolower($v) === 'n/a') { $missing++; continue; }
                $vals[] = $v;
            }
            $numeric = $vals && count(array_filter($vals, 'is_numeric')) === count($vals);
            if ($numeric) {
                $nums = array_map('floatval', $vals);
                sort($nums);
                $cnt = count($nums);
                $mean = array_sum($nums) / $cnt;
                $sd = $cnt > 1 ? sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $nums)) / ($cnt - 1)) : 0.0;
                $q = fn(float $p) => $nums[(int) floor(($cnt - 1) * $p)];
                $lines[] = sprintf('- %s [numeric]: n=%d, missing=%d, mean=%.4g, sd=%.4g, min=%.4g, q1=%.4g, median=%.4g, q3=%.4g, max=%.4g', $name, $cnt, $missing, $mean, $sd, $nums[0], $q(.25), $q(.5), $q(.75), $nums[$cnt - 1]);
            } else {
                $freq = array_count_values($vals);
                arsort($freq);
                $top = array_slice($freq, 0, 8, true);
                $lines[] = sprintf('- %s [categorical/text]: n=%d, missing=%d, distinct=%d, top: %s', $name, count($vals), $missing, count($freq), implode(', ', array_map(fn($k, $c) => mb_substr((string) $k, 0, 40) . '=' . $c, array_keys($top), $top)));
            }
        }
        $lines[] = 'These figures were computed by the system from the uploaded file. Missing values: blank, NA, N/A.';
        return implode("\n", $lines);
    }
}
