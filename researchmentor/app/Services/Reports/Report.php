<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\{DocxWriter, SimplePdf};

/** Format-neutral report model rendered to PDF or DOCX. */
final class Report
{
    /** @var array<int,array{heading:string,level:int,blocks:array}> */
    public array $sections = [];
    public array $meta = [];

    public function __construct(public string $title, public string $subtitle = '', public string $footer = 'ResearchMentor AI report - AI-assisted, not an official academic assessment')
    {
    }

    public function meta(string $k, ?string $v): self
    {
        if ($v !== null && $v !== '') {
            $this->meta[$k] = $v;
        }
        return $this;
    }

    public function section(string $heading, int $level = 1): int
    {
        $this->sections[] = ['heading' => $heading, 'level' => $level, 'blocks' => []];
        return count($this->sections) - 1;
    }

    private function add(array $block): self
    {
        if (!$this->sections) {
            $this->section('');
        }
        $this->sections[count($this->sections) - 1]['blocks'][] = $block;
        return $this;
    }

    public function p(string $t): self { return $this->add(['t' => 'p', 'text' => $t]); }
    public function b(string $t): self { return $this->add(['t' => 'b', 'text' => $t]); }
    public function bullets(array $items): self { foreach ($items as $i) { $this->b((string) $i); } return $this; }
    public function kv(string $k, ?string $v): self { return ($v === null || $v === '') ? $this : $this->add(['t' => 'kv', 'k' => $k, 'v' => $v]); }
    public function note(string $t): self { return $this->add(['t' => 'note', 'text' => $t]); }
    public function table(array $headers, array $rows): self { return $this->add(['t' => 'table', 'headers' => $headers, 'rows' => $rows]); }

    /** Plain text of the whole report (used by tests and for integrity checks). */
    public function text(): string
    {
        $out = [$this->title, $this->subtitle];
        foreach ($this->meta as $k => $v) $out[] = "$k: $v";
        foreach ($this->sections as $s) {
            $out[] = $s['heading'];
            foreach ($s['blocks'] as $b) {
                $out[] = match ($b['t']) { 'kv' => $b['k'] . ': ' . $b['v'], 'table' => implode(' | ', $b['headers']) . "\n" . implode("\n", array_map(fn($r) => implode(' | ', $r), $b['rows'])), default => $b['text'] };
            }
        }
        return implode("\n", $out);
    }

    public function toPdf(): string
    {
        $pdf = new SimplePdf($this->title, $this->footer);
        $pdf->heading($this->title, 1);
        if ($this->subtitle !== '') $pdf->paragraph($this->subtitle, 10.5);
        foreach ($this->meta as $k => $v) $pdf->keyValue($k, $v);
        foreach ($this->sections as $s) {
            if ($s['heading'] !== '') $pdf->heading($s['heading'], $s['level'] + 1);
            foreach ($s['blocks'] as $b) {
                match ($b['t']) {
                    'p' => $pdf->paragraph($b['text']),
                    'b' => $pdf->bullet($b['text']),
                    'kv' => $pdf->keyValue($b['k'], $b['v']),
                    'note' => $pdf->note($b['text']),
                    'table' => (function () use ($pdf, $b) {
                        $pdf->paragraph(implode('  |  ', $b['headers']), 9.5, true);
                        foreach ($b['rows'] as $r) $pdf->bullet(implode('  |  ', array_map(fn($c) => str_replace("\n", ' ', (string) $c), $r)), 9.5);
                    })(),
                };
            }
        }
        return $pdf->render();
    }

    public function toDocx(): string
    {
        $d = new DocxWriter($this->title, $this->footer);
        $d->title($this->title);
        if ($this->subtitle !== '') $d->paragraph($this->subtitle);
        foreach ($this->meta as $k => $v) $d->keyValue($k, $v);
        foreach ($this->sections as $s) {
            if ($s['heading'] !== '') $d->heading($s['heading'], $s['level']);
            foreach ($s['blocks'] as $b) {
                match ($b['t']) {
                    'p' => $d->paragraph($b['text']),
                    'b' => $d->bullet($b['text']),
                    'kv' => $d->keyValue($b['k'], $b['v']),
                    'note' => $d->note($b['text']),
                    'table' => $d->table($b['headers'], $b['rows']),
                };
            }
        }
        return $d->render();
    }
}
