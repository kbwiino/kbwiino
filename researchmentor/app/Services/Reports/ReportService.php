<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\GeneratedReport;
use App\Services\AuditLogger;
use App\Support\{FileStorage, Request, Response};

final class ReportService
{
    /** Render, store (exports area, random name) and stream a report. */
    public static function deliver(Request $r, array $user, array $project, ?array $review, string $type, Report $report, string $format): Response
    {
        $bytes = $format === 'pdf' ? $report->toPdf() : $report->toDocx();
        $stored = FileStorage::storeContents($bytes, 'exports');
        $id = GeneratedReport::create(['project_id' => $project['id'], 'review_id' => $review['id'] ?? null, 'report_type' => $type, 'format' => $format, 'stored_filename' => $stored, 'generated_by' => $user['id']]);
        $row = GeneratedReport::find($id);
        AuditLogger::log($r, 'report.generate', 'report', $row['uuid'], ['project' => $project['uuid'], 'type' => $type, 'format' => $format]);
        $name = preg_replace('/[^a-z0-9]+/i', '-', $type . '-' . gmdate('Ymd')) . '.' . $format;
        return Response::download(FileStorage::path($stored, 'exports'), $name, $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
}
