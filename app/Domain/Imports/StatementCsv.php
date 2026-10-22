<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\User;
use Illuminate\Http\Response;

/** Spreadsheet-safe evidence; exact original source remains in complete owner JSON. */
final class StatementCsv
{
    public function download(User $owner, string $snapshotId): Response
    {
        $view = app(StatementImports::class)->show($owner, $snapshotId);
        $output = fopen('php://temp', 'w+');
        try {
            fputcsv($output, ['source_reference', 'source_sha256', 'row', 'type', 'date', 'currency', 'amount_minor', 'description', 'provider_id', 'outcome', 'reasons'], ',', '"', '', "\r\n");
            foreach ($view['rows'] as $row) {
                $d = $row['data'];
                $cells = [$view['source_reference'], $view['source_digest'], $row['number'], $d['kind'] ?? '', $d['effective_date'] ?? '', $d['currency'] ?? '',
                    $d['amount_minor'] ?? '', $d['description'] ?? '', $d['provider_id'] ?? '',
                    $row['existing'] === null ? 'unapproved' : '/imports/receipts/'.$row['existing']['receipt_id'], implode(' ', $row['errors'])];
                fputcsv($output, array_map(self::safe(...), $cells), ',', '"', '', "\r\n");
            }
            rewind($output);

            return response(stream_get_contents($output), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="halalos-statement-v1.csv"', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
        } finally {
            fclose($output);
        }
    }

    public static function safe(string $value): string
    {
        return preg_match('/^[\s\x00-\x20\x7f]*[=+\-@]/u', $value) || preg_match('/^[\x00-\x1f\x7f]/', $value) ? "'".$value : $value;
    }
}
