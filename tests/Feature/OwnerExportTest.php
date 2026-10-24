<?php

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\CreateAccount;
use App\Domain\Ledger\PostingService;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use App\Support\Release\OwnerExport;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\DriveFixtures;
use Tests\Support\ReleaseFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

afterEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('complete owner export is unavailable to guests', function () {
    $this->get('/settings/data/export')->assertRedirect('/login');
});

test('complete owner export contains every financial section and excludes authentication material', function () {
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/data/export')->assertOk()->assertDownload('halalos-owner-v2.json');
    $download = $response->baseResponse;
    $path = $download->getFile()->getPathname();
    $document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($document['schema_version'])->toBe(2)
        ->and($document['profile']['id'])->toBe((string) $owner->id)
        ->and($document['data'])->toHaveCount(67)
        ->and($document['manifest']['sections'])->toHaveCount(67)
        ->and($document['data'])->toHaveKeys(['market_mappings', 'market_attempts', 'market_results', 'market_admissions', 'reminder_preferences', 'reminder_controls', 'reminder_notifications', 'reminder_channels', 'reminder_claims', 'reminder_results', 'reminder_reads'])
        ->and($document['profile'])->not->toHaveKeys(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']);
    ob_start();
    try {
        $download->sendContent();
    } finally {
        ob_end_clean();
    }
    expect(file_exists($path))->toBeFalse()->and(is_dir(dirname($path)))->toBeFalse();
});

test('complete export preserves exact large values both opening ownership paths and foreign-owner isolation', function () {
    DB::statement('ALTER SEQUENCE users_id_seq RESTART WITH 9007199254740993');
    $owner = User::factory()->withTwoFactor()->create(['name' => 'Synthetic export owner', 'is_admin' => true, 'remember_token' => 'synthetic-owner-remember-secret']);
    $foreign = User::factory()->create(['name' => 'FOREIGN-OWNER-MUST-NOT-APPEAR']);
    $account = app(CreateAccount::class)->create($owner, 'Synthetic exact opening', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '9007199254740993'));
    app(PostingService::class)->postOpening($owner, (string) $account->id, 'synthetic-owned-opening-request');
    $other = app(CreateAccount::class)->create($foreign, 'FOREIGN-ACCOUNT-MUST-NOT-APPEAR', AccountType::Bank, Currency::QAR, Money::fromMinor(Currency::QAR, '12345'));
    app(PostingService::class)->postOpening($foreign, (string) $other->id, 'FOREIGN-REQUEST-MUST-NOT-APPEAR');
    $response = app(OwnerExport::class)->download($owner);
    $path = $response->getFile()->getPathname();
    try {
        $bytes = file_get_contents($path);
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        expect($data['profile']['id'])->toBe('9007199254740993')
            ->and($data['data']['opening_balance_intents'])->toHaveCount(1)
            ->and($data['data']['opening_balance_postings'])->toHaveCount(1)
            ->and($data['data']['opening_balance_intents'][0]['amount_minor'])->toBe('9007199254740993');
        foreach ([$foreign->name, $foreign->email, 'FOREIGN-ACCOUNT-MUST-NOT-APPEAR', 'FOREIGN-REQUEST-MUST-NOT-APPEAR', $owner->password, $owner->two_factor_secret, $owner->two_factor_recovery_codes, $owner->remember_token] as $secret) {
            expect($bytes)->not->toContain($secret);
        }
        foreach ($data['manifest']['sections'] as $table => $manifest) {
            $rows = $data['data'][$table];
            expect($manifest['rows'])->toBe(count($rows))
                ->and($manifest['sha256'])->toBe(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    expect($value === null || is_string($value))->toBeTrue();
                }
            }
        }
        expect(fileperms($path) & 0777)->toBe(0600)->and(fileperms(dirname($path)) & 0777)->toBe(0700)
            ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
            ->and($data['manifest']['excluded_tables'])->toContain('passkeys', 'sessions', 'password_reset_tokens');
    } finally {
        unset($response);
    }
    expect(file_exists($path))->toBeFalse();
});

test('complete export rejects unreviewed schema changes without leaving a partial download', function (string $change) {
    $owner = User::factory()->create();
    $before = glob(sys_get_temp_dir().'/hwo-owner-export-*');
    DB::statement($change);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/data/export')
        ->assertStatus(503)->assertDontSee('synthetic-unreviewed-credential');
    expect(glob(sys_get_temp_dir().'/hwo-owner-export-*'))->toBe($before);
})->with([
    'column' => ["ALTER TABLE users ADD COLUMN new_secret text DEFAULT 'synthetic-unreviewed-credential'"],
    'table' => ['CREATE TABLE unclassified_owner_records (owner_id bigint NOT NULL, payload text)'],
]);

test('complete export cannot inherit an unknown transaction snapshot', function () {
    $owner = User::factory()->create();
    DB::beginTransaction();
    try {
        expect(fn () => app(OwnerExport::class)->download($owner))->toThrow(RuntimeException::class, 'own coherent snapshot');
    } finally {
        DB::rollBack();
    }
});

test('complete export enforces email verification recent password confirmation and no owner selector', function () {
    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get('/settings/data/export')->assertRedirectToRoute('verification.notice');
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/settings/data/export')->assertRedirectToRoute('password.confirm');
    $this->withSession(['auth.password_confirmed_at' => time()])->get('/settings/data/export?owner_id='.$unverified->id)->assertBadRequest();
});

test('populated owner export retains revisions exact nested decimals and released reservations', function () {
    $fixture = ReleaseFixtures::seed();
    $owner = User::findOrFail($fixture['owner']);
    $response = app(OwnerExport::class)->download($owner);
    try {
        $document = json_decode(file_get_contents($response->getFile()->getPathname()), true, flags: JSON_THROW_ON_ERROR);
        $data = $document['data'];
        expect($data['goal_reservations'])->toHaveCount(3)->and($data['goal_releases'])->toHaveCount(1)
            ->and($data['decision_reservations'])->toHaveCount(1)->and($data['shariah_assessments'])->toHaveCount(2)
            ->and($data['purification_obligations'])->toHaveCount(1)->and($data['purification_allocations'])->toHaveCount(1)
            ->and(array_column($data['portfolio_opening_rows'], 'quantity'))->toContain('1.234567890123');
        expect($data['statement_sources'])->toHaveCount(2)->and($data['statement_adapters'])->toHaveCount(1)
            ->and($data['statement_snapshots'])->toHaveCount(2)->and($data['statement_receipts'])->toHaveCount(3)
            ->and($data['statement_claims'])->not->toBeEmpty();
        $source = collect($data['statement_sources'])->firstWhere('id', $fixture['import_source']);
        expect(hash('sha256', base64_decode($source['source_base64'], true)))->toBe($source['digest'])
            ->and(base64_decode($source['source_base64'], true))->toContain('SYNTHETIC-RECOVERY-IMPORT')
            ->not->toContain('FOREIGN-STATEMENT-SOURCE-MUST-NOT-APPEAR');
        foreach ($data as $table => $rows) {
            foreach ($rows as $row) {
                if (array_key_exists('owner_id', $row)) {
                    expect($row['owner_id'])->toBe($fixture['owner']);
                }
            }
        }
        foreach (DB::table('portfolio_requests')->where('owner_id', $owner->id)->get() as $record) {
            $exported = collect($data['portfolio_requests'])->firstWhere('id', (string) $record->id);
            foreach ((array) $record as $key => $value) {
                if (is_string($value) && str_contains($value, '1.234567890123')) {
                    expect($exported[$key])->toBe($value);
                }
            }
        }
    } finally {
        unset($response);
    }
});

test('authenticated data settings page exposes complete export without embedding owner records', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/settings/data')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/Data')->missing('records')->missing('export'));
});

test('owner exports include safe drive metadata while excluding entire oauth and token relations', function () {
    $wire = new DriveFixtures;
    $wire->fake();
    $this->owner = User::factory()->create();
    $wire->ready($this->owner);
    DriveFixtures::queued($this->owner, DriveFixtures::snapshot($this->owner));
    $download = app(OwnerExport::class)->download($this->owner);
    $response = $download;
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();
    expect($bytes)->not->toContain('synthetic-access', 'synthetic-refresh', 'ciphertext', 'oauth_verifier');
    $json = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    expect($bytes)->toContain('drive_connections', 'drive_operations', 'drive_events');
    expect($json)->toBeArray();
});
