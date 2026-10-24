<?php

namespace Tests\Support;

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\SavedReport;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class DriveRecoveryFixtures
{
    public static function seed(User $owner, User $foreign): void
    {
        $prior = ['drive_export.mode' => config('drive_export.mode'), 'drive_export.epoch' => config('drive_export.epoch')];
        $wire = new DriveFixtures;
        $wire->fake();
        $drive = app(DriveExports::class);
        $c = $wire->ready($owner);
        $snapshot = DriveFixtures::snapshot($owner);
        $uploaded = DriveFixtures::queued($owner, $snapshot);
        $drive->run($uploaded['id']);
        $unknown = DriveFixtures::queued($owner, $snapshot);
        $wire->failure = 'after_create';
        $drive->run($unknown['id']);
        $wire->failure = '';
        DriveFixtures::queued($owner, $snapshot);
        $wire->failure = 'revoke';
        $drive->disconnect($owner, $c['id']);
        $wire->failure = '';
        $wire->connect($foreign);
        config($prior);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public static function read(User $owner): array
    {
        $reports = [];
        foreach (DB::table('drive_operations')->where('owner_id', $owner->id)->where('kind', 'report')->orderBy('id')->get() as $row) {
            $artifact = SavedReport::make($owner, (string) $row->snapshot_id);
            if ($artifact->sha256 !== $row->content_hash || strlen($artifact->bytes) !== $row->content_bytes) {
                throw new \RuntimeException('Restored saved report bytes changed');
            }
            $reports[(string) $row->id] = $artifact->sha256;
        }
        $vault = [];
        foreach (DB::table('drive_secrets')->where('owner_id', $owner->id)->orderBy('id')->get() as $row) {
            $data = json_decode(Crypt::decryptString($row->ciphertext), true, flags: JSON_THROW_ON_ERROR);
            if ($data['owner'] !== (string) $owner->id || $data['connection'] !== (string) $row->connection_id || ! is_string($data['access']) || ! is_string($data['refresh'])) {
                throw new \RuntimeException('Restored credential envelope failed ownership/decryption');
            }
            $vault[(string) $row->connection_id] = 'DECRYPTED_OWNER_BOUND';
        }

        return ['page' => app(DriveExports::class)->page($owner), 'reports' => $reports, 'vault' => $vault];
    }

    public static function assertHeld(): void
    {
        $prior = ['drive_export.mode' => config('drive_export.mode'), 'drive_export.epoch' => config('drive_export.epoch')];
        config(['drive_export.mode' => 'mock', 'drive_export.epoch' => str_repeat('b', 32)]);
        $before = DB::table('drive_operations')->orderBy('id')->get()->toJson();
        $connections = DB::table('drive_connections')->orderBy('id')->get()->toJson();
        $service = app(DriveExports::class);
        try {
            $service->tick();
            foreach (DB::table('drive_operations')->pluck('id') as $id) {
                $service->run((string) $id);
            }
            foreach (DB::table('drive_connections')->pluck('id') as $id) {
                $service->revoke((string) $id);
            }
            if ($before !== DB::table('drive_operations')->orderBy('id')->get()->toJson() || $connections !== DB::table('drive_connections')->orderBy('id')->get()->toJson()) {
                throw new \RuntimeException('Restored Drive work escaped epoch hold');
            }
            Http::assertNothingSent();
        } finally {
            config($prior);
        }
    }
}
