<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Drive;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Reporting\DashboardSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class SavedReport
{
    public const int LIMIT = 5_000_000;

    public const string FORMAT = 'halalos-saved-dashboard-v1';

    /** @param array<string,mixed> $manifest */
    private function __construct(public string $bytes, public string $sha256, public array $manifest) {}

    public static function make(User $owner, string $id): self
    {
        $id = LedgerInput::identifier($id);
        $row = DB::table('dashboard_snapshots')->where('owner_id', $owner->id)->where('id', $id)
            ->select(['reference', 'evidence_hash', 'recorded_at'])->selectRaw('octet_length(evidence::text) AS bytes')->first();
        abort_unless($row !== null, 404);
        if ((int) $row->bytes > self::LIMIT) {
            throw new DriveFailure('report_too_large');
        }
        $report = app(DashboardSnapshot::class)->open($owner, $id);
        $payload = self::encode($report);
        $manifest = ['format' => self::FORMAT, 'snapshot_id' => $id, 'reference' => $row->reference,
            'saved_at' => LedgerInput::utc($row->recorded_at), 'captured_at' => $report['captured_at'],
            'base_currency' => $report['facts']['base_currency'], 'retained_evidence_sha256' => $row->evidence_hash,
            'payload_sha256' => hash('sha256', $payload), 'sections' => array_keys($report),
            'coverage' => 'Complete selected saved dashboard evidence, including retained source records. Not an application database backup.'];
        $bytes = self::encode(['manifest' => $manifest, 'report' => $report]);
        if (strlen($bytes) > self::LIMIT) {
            throw new DriveFailure('report_too_large');
        }

        return new self($bytes, hash('sha256', $bytes), $manifest);
    }

    /** @param array<array-key,mixed> $value */
    public static function encode(array $value): string
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = json_decode(self::encode($entry), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        unset($entry);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
