<?php

use App\Domain\Integrations\Drive\DriveExports;
use App\Domain\Integrations\Drive\DriveFailure;
use App\Domain\Operations\Channel;
use App\Domain\Operations\DriveAction;
use App\Domain\Operations\OperationBlocked;
use App\Domain\Operations\OperationPermit;
use App\Domain\Operations\Operations;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DriveFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->owner = User::factory()->create();
    $this->operations = app(Operations::class);
});

afterEach(function () {
    Date::setTestNow();
});

function operationPermit(User $owner, Channel $channel = Channel::Email, ?DriveAction $action = null): OperationPermit
{
    return DB::transaction(fn () => app(Operations::class)->reserve($owner, $channel, (string) Str::uuid(), $action));
}

function operationLimit(User $owner, Channel $channel, array $changes): void
{
    $ops = app(Operations::class);
    $policy = $ops->policy($owner, $channel);
    $generation = $policy['generation'];
    unset($policy['generation']);
    $ops->configure($owner, $channel, $generation, [...$policy, ...$changes]);
}

test('missing policy fails closed and pause requires no row allocation', function () {
    $owner = User::create(['name' => 'Synthetic registration', 'email' => 'new-operations@example.test', 'password' => 'synthetic-password']);
    expect($this->operations->policy($owner, Channel::Email)['paused'])->toBeTrue();
    $this->operations->pauseAll($owner);
    expect(DB::table('operation_controls')->where('owner_id', $owner->id)->count())->toBe(0);
    expect(fn () => operationPermit($owner))->toThrow(OperationBlocked::class, 'paused');
    expect(fn () => $this->operations->configure($owner, Channel::Email, '0', ['paused' => false, 'requests_daily' => 1, 'units_daily' => 1, 'retention_days' => 1]))->toThrow(ValidationException::class, 'stopped limits');
    $this->operations->configure($owner, Channel::Email, '0', ['paused' => true, 'requests_daily' => 1, 'units_daily' => 1, 'retention_days' => 1]);
    $this->operations->configure($owner, Channel::Email, '1', ['paused' => false, 'requests_daily' => 1, 'units_daily' => 1, 'retention_days' => 1]);
    expect(operationPermit($owner))->toBeInstanceOf(OperationPermit::class);
});

test('hard request budget retains failed and uncertain reservations and isolates owners', function () {
    operationLimit($this->owner, Channel::Email, ['requests_daily' => 1]);
    $permit = operationPermit($this->owner);
    $permit->call(fn () => true);
    $permit->finish('uncertain');
    expect(fn () => operationPermit($this->owner))->toThrow(OperationBlocked::class, 'request_budget');
    $other = User::factory()->create();
    expect(operationPermit($other))->toBeInstanceOf(OperationPermit::class);
    expect($this->operations->usage($this->owner, Channel::Email)['requests_today'])->toBe(1);
});

test('hard unit allowance rejects oversized Drive actions before handoff', function () {
    operationLimit($this->owner, Channel::Drive, ['units_daily' => 7]);
    $sent = 0;
    expect(fn () => $this->operations->drive($this->owner, DriveAction::Run, function () use (&$sent) {
        $sent++;
    }))->toThrow(OperationBlocked::class, 'unit_budget');
    expect($sent)->toBe(0)->and(DB::table('operation_reservations')->count())->toBe(0);
});

test('each actual leaf send checks current stop and never recalls a previous call', function () {
    $permit = operationPermit($this->owner, Channel::Drive, DriveAction::Connect);
    $sent = 0;
    $permit->call(function () use (&$sent) {
        $sent++;
    });
    $this->operations->pauseAll($this->owner);
    expect(fn () => $permit->call(function () use (&$sent) {
        $sent++;
    }))->toThrow(OperationBlocked::class, 'paused');
    expect($sent)->toBe(1);
    $permit->finish('held');
    expect($this->operations->usage($this->owner, Channel::Drive)['units_today'])->toBe(3);
});

test('global stop and policy generation change invalidate old permits without refunds', function () {
    $permit = operationPermit($this->owner);
    config(['operations.paused' => true]);
    expect(fn () => $permit->call(fn () => true))->toThrow(OperationBlocked::class, 'paused');
    config(['operations.paused' => false]);
    operationLimit($this->owner, Channel::Email, ['retention_days' => 2]);
    expect(fn () => $permit->assertCurrent())->toThrow(OperationBlocked::class, 'policy_changed');
    $permit->finish('held');
    expect($this->operations->usage($this->owner, Channel::Email)['units_today'])->toBe(1);
});

test('finite allowance cannot be bypassed through the service send entrypoint or serialization', function () {
    $permit = operationPermit($this->owner);
    expect($permit->call(fn () => 'sent'))->toBe('sent');
    expect(fn () => $this->operations->send($permit, fn () => 'extra'))->toThrow(OperationBlocked::class, 'allowance_exhausted');
    expect(fn () => serialize($permit))->toThrow(LogicException::class, 'cannot be retained');
    expect(fn () => clone $permit)->toThrow(LogicException::class, 'cannot be copied');
    $permit->finish('complete');
    $permit->finish('unavailable');
    expect(DB::table('operation_reservations')->value('status'))->toBe('complete');
});

test('committed Drive allowance survives later native rollback and unknown handoff', function () {
    expect(fn () => $this->operations->drive($this->owner, DriveAction::Run, function (OperationPermit $permit) {
        return DB::transaction(function () use ($permit) {
            $permit->call(fn () => true);
            throw new RuntimeException('Synthetic interruption after unknown handoff');
        });
    }))->toThrow(RuntimeException::class, 'Synthetic interruption');
    $row = DB::table('operation_reservations')->first();
    expect($row->status)->toBe('uncertain')->and($row->units)->toBe(8)->and($row->finished_at)->not->toBeNull();
    expect(fn () => DB::transaction(fn () => $this->operations->drive($this->owner, DriveAction::Revoke, fn () => true)))->toThrow(LogicException::class, 'must commit');
    expect(DB::table('operation_reservations')->count())->toBe(1);
});

test('telemetry expiry changes neither immutable usage nor another owner metrics', function () {
    Date::setTestNow(now('UTC')->startOfDay());
    $other = User::factory()->create();
    $old = now('UTC')->subDays(8);
    foreach ([$this->owner, $other] as $owner) {
        DB::table('operation_metrics')->insert(['owner_id' => $owner->id, 'channel' => 'email', 'bucket_at' => $old,
            'attempts' => 1, 'failed' => 1, 'maximum_elapsed_ms' => 7]);
    }
    $permit = operationPermit($this->owner);
    $permit->finish('unavailable');
    $before = json_encode(DB::table('operation_reservations')->get());
    operationLimit($this->owner, Channel::Email, ['retention_days' => 1]);
    expect(json_encode(DB::table('operation_reservations')->get()))->toBe($before)
        ->and(DB::table('operation_metrics')->where('owner_id', $this->owner->id)->count())->toBe(1)
        ->and(DB::table('operation_metrics')->where('owner_id', $other->id)->count())->toBe(1);
});

test('settings reject stale resumes arbitrary fields and limits above server maxima', function () {
    $policy = $this->operations->policy($this->owner, Channel::Email);
    $generation = $policy['generation'];
    unset($policy['generation']);
    $this->operations->pauseAll($this->owner);
    expect(fn () => $this->operations->configure($this->owner, Channel::Email, $generation, $policy))->toThrow(HttpException::class, 'changed');
    expect(fn () => operationLimit($this->owner, Channel::Email, ['owner_id' => 999]))->toThrow(ValidationException::class);
    expect(fn () => operationLimit($this->owner, Channel::Copilot, ['requests_daily' => 11]))->toThrow(ValidationException::class);
    expect(fn () => operationLimit($this->owner, Channel::Drive, ['units_daily' => 801]))->toThrow(ValidationException::class);
});

test('reservation identity and control owner are database retained and stored secrets cannot create permits', function () {
    $reference = (string) Str::uuid();
    $permit = DB::transaction(fn () => $this->operations->reserve($this->owner, Channel::Email, $reference));
    expect(fn () => DB::transaction(fn () => $this->operations->reserve($this->owner, Channel::Email, $reference)))->toThrow(OperationBlocked::class, 'already_reserved');
    expect(fn () => $this->operations->reserve($this->owner, Channel::Email, (string) Str::uuid()))->toThrow(LogicException::class, 'committing admission');
    $forged = new OperationPermit($this->owner, Channel::Email, $reference, $permit->generation, 8, $permit->hash(), fn () => true);
    expect(fn () => $forged->call(fn () => true))->toThrow(OperationBlocked::class, 'policy_changed');
    expect(fn () => DB::transaction(fn () => DB::table('operation_reservations')->update(['units' => 0])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('operation_controls')->update(['owner_id' => 999])))->toThrow(QueryException::class);
    $permit->finish('complete');
    expect(fn () => DB::transaction(fn () => DB::table('operation_reservations')->delete()))->toThrow(QueryException::class);
});

test('an allowance cannot call before its native root admission commits', function () {
    $sent = 0;
    expect(fn () => DB::transaction(function () use (&$sent) {
        $permit = $this->operations->reserve($this->owner, Channel::Email, (string) Str::uuid());
        $permit->call(function () use (&$sent) {
            $sent++;
        });
    }))->toThrow(OperationBlocked::class, 'uncommitted_allowance');
    expect($sent)->toBe(0)->and(DB::table('operation_reservations')->count())->toBe(0);
});

test('monitor exposes fixed owner-local counts and honest elapsed metadata only', function () {
    $permit = operationPermit($this->owner);
    $permit->finish('complete');
    $other = User::factory()->create();
    operationPermit($other)->finish('unavailable');
    $page = $this->operations->page($this->owner);
    $email = collect($page['channels'])->firstWhere('channel', 'email');
    expect($email['metrics']['attempts'])->toBe(1)->and($email['metrics']['failed'])->toBe(0)
        ->and($page['global_paused'])->toBeFalse()->and(count($page['channels']))->toBe(4);
    $json = json_encode($page);
    foreach ([$this->owner->email, $other->email, $permit->hash(), 'permit_hash', 'document', 'question'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

test('UTC budgets do not inherit a non UTC PostgreSQL session day', function () {
    DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");
    try {
        $old = now('UTC')->startOfDay()->subMinute();
        DB::table('operation_reservations')->insert(['owner_id' => $this->owner->id, 'channel' => 'email', 'reference' => (string) Str::uuid(),
            'control_generation' => 0, 'units' => 1, 'allowance' => 1, 'permit_hash' => hash('sha256', 'synthetic old reservation'), 'recorded_at' => $old->format('Y-m-d\TH:i:s.uP')]);
        operationPermit($this->owner);
        expect($this->operations->usage($this->owner, Channel::Email)['requests_today'])->toBe(1);
    } finally {
        DB::statement("SET TIME ZONE 'UTC'");
    }
});

test('a stop after partial Drive work retains uncertainty rather than no handoff', function () {
    expect(fn () => $this->operations->drive($this->owner, DriveAction::Connect, function (OperationPermit $permit) {
        $permit->call(fn () => true);
        $this->operations->pauseAll($this->owner);
        $permit->call(fn () => true);
    }))->toThrow(OperationBlocked::class, 'paused');
    expect(DB::table('operation_reservations')->value('status'))->toBe('uncertain');
});

test('an explicitly UTC current day charge still exhausts budget in another session timezone', function () {
    Date::setTestNow(new DateTimeImmutable('2026-10-27T00:30:00Z'));
    operationLimit($this->owner, Channel::Email, ['requests_daily' => 1]);
    DB::table('operation_reservations')->insert(['owner_id' => $this->owner->id, 'channel' => 'email', 'reference' => (string) Str::uuid(),
        'control_generation' => 1, 'units' => 1, 'allowance' => 1, 'permit_hash' => hash('sha256', 'explicit UTC charge'), 'recorded_at' => '2026-10-27T00:01:00Z']);
    DB::statement("SET TIME ZONE 'America/Los_Angeles'");
    try {
        expect(fn () => operationPermit($this->owner))->toThrow(OperationBlocked::class, 'request_budget');
        expect($this->operations->usage($this->owner, Channel::Email)['requests_today'])->toBe(1);
    } finally {
        DB::statement("SET TIME ZONE 'UTC'");
    }
});

test('Drive operational hold preserves credential validity and same ID reconciliation', function (bool $previousUnknownUpload) {
    $wire = new DriveFixtures;
    $wire->fake();
    $connection = $wire->ready($this->owner);
    $receipt = DriveFixtures::queued($this->owner, DriveFixtures::snapshot($this->owner));
    $drive = app(DriveExports::class);
    if ($previousUnknownUpload) {
        $wire->failure = 'after_create';
        $drive->run($receipt['id']);
        $wire->failure = '';
        $this->travel(2)->minutes();
    }
    $before = count(Http::recorded());
    $once = false;
    Event::listen(TransactionCommitted::class, function () use (&$once, $receipt): void {
        if (! $once && DB::connection()->transactionLevel() === 0
            && DB::table('drive_operations')->where('id', $receipt['id'])->value('state') === 'claimed') {
            $once = true;
            $this->operations->pauseAll($this->owner);
        }
    });
    $drive->run($receipt['id']);
    expect($once)->toBeTrue()->and(count(Http::recorded()))->toBe($before)
        ->and($drive->connection($this->owner, $connection['id'])['status'])->toBe('ready')
        ->and($drive->inspect($this->owner, $receipt['request_key']))->toMatchArray(['state' => 'unknown', 'remote_id' => $receipt['remote_id'], 'safe_code' => 'operations_held']);
    operationLimit($this->owner, Channel::Drive, ['paused' => false]);
    $this->travel(6)->minutes();
    $creates = $wire->creates;
    $drive->run($receipt['id']);
    expect($drive->inspect($this->owner, $receipt['request_key']))->toMatchArray(['state' => 'uploaded', 'remote_id' => $receipt['remote_id']])
        ->and($wire->creates)->toBe($creates + ($previousUnknownUpload ? 0 : 1));
})->with([false, true]);

test('owner HTTP settings and summary isolate identity reject forged fields and fence stale resumes', function () {
    $other = User::factory()->create();
    $this->actingAs($this->owner)->get('/operations')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('operations/Index')->has('operations.channels', 4));
    $data = ['generation' => $this->operations->policy($this->owner, Channel::Email)['generation'], 'paused' => false, 'requests_daily' => 2, 'units_daily' => 2, 'retention_days' => 1];
    $this->postJson('/operations/email', [...$data, 'owner_id' => $other->id])->assertUnprocessable();
    expect($this->operations->policy($other, Channel::Email)['requests_daily'])->toBe(100);
    $this->postJson('/operations/email', $data)->assertStatus(303);
    $this->postJson('/operations/email', $data)->assertConflict();
    $this->postJson('/operations/pause', ['owner_id' => $other->id])->assertUnprocessable();
    $this->postJson('/operations/pause', [])->assertStatus(303);
    expect($this->operations->policy($this->owner, Channel::Email)['paused'])->toBeTrue()
        ->and($this->operations->policy($other, Channel::Email)['paused'])->toBeFalse();
    $this->postJson('/operations/email', [...$data, 'generation' => '1'])->assertConflict();
    $this->postJson('/operations/invalid', $data)->assertNotFound();
    $this->actingAs(User::factory()->unverified()->create())->get('/operations')->assertRedirect('/email/verify');
    Auth::logout();
    $this->get('/operations')->assertRedirect('/login');
});

test('export capacity denies admissions and setting growth but pause is allocation free', function () {
    $limited = new Operations(1048576);
    expect(fn () => DB::transaction(fn () => $limited->reserve($this->owner, Channel::Email, (string) Str::uuid())))
        ->toThrow(OperationBlocked::class, 'export_capacity');
    expect(DB::table('operation_reservations')->count())->toBe(0);
    $policy = $limited->policy($this->owner, Channel::Email);
    unset($policy['generation']);
    expect(fn () => $limited->configure($this->owner, Channel::Email, $limited->policy($this->owner, Channel::Email)['generation'], [...$policy, 'requests_daily' => 2]))->toThrow(OperationBlocked::class, 'export_capacity');
    expect($limited->policy($this->owner, Channel::Email)['requests_daily'])->toBe(100);
    $limited->pauseAll($this->owner);
    expect($limited->policy($this->owner, Channel::Email)['paused'])->toBeTrue();
});

test('global metric maintenance expires only owner configured coarse buckets', function () {
    $other = User::factory()->create();
    $now = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS at')->at)->utc();
    foreach ([$this->owner, $other] as $owner) {
        foreach ([1, 9] as $days) {
            DB::table('operation_metrics')->insert(['owner_id' => $owner->id, 'channel' => 'email', 'bucket_at' => $now->subDays($days)->format('Y-m-d\TH:i:s.uP'), 'attempts' => 2, 'failed' => 1, 'maximum_elapsed_ms' => 3]);
        }
    }
    $permit = operationPermit($this->owner);
    $permit->finish('uncertain');
    $before = json_encode(DB::table('operation_reservations')->get());
    $this->artisan('operations:prune-metrics')->assertSuccessful();
    expect(DB::table('operation_metrics')->where('owner_id', $other->id)->count())->toBe(1)
        ->and(json_encode(DB::table('operation_reservations')->get()))->toBe($before);
});

test('operational copilot reservation follows configured native units and invalid configuration fails closed', function () {
    config(['copilot.tokens_per_request' => 32768]);
    operationLimit($this->owner, Channel::Copilot, ['units_daily' => 32768]);
    $permit = operationPermit($this->owner, Channel::Copilot);
    expect(DB::table('operation_reservations')->value('units'))->toBe(32768);
    expect(fn () => operationPermit($this->owner, Channel::Copilot))->toThrow(OperationBlocked::class, 'unit_budget');
    $permit->finish('held');
    config(['copilot.tokens_per_request' => '32768']);
    expect(fn () => operationPermit($this->owner, Channel::Copilot))->toThrow(LogicException::class, 'configuration');
});

test('retained reservation ceiling cannot be reset but stop and foreign owner remain available', function () {
    $rows = [];
    for ($i = 0; $i < Operations::MAX_RESERVATIONS; $i++) {
        $rows[] = ['owner_id' => $this->owner->id, 'channel' => 'email', 'reference' => (string) Str::uuid(), 'control_generation' => 1,
            'units' => 1, 'allowance' => 1, 'permit_hash' => hash('sha256', 'synthetic-'.$i), 'recorded_at' => '2000-01-01T00:00:00+00:00'];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('operation_reservations')->insert($chunk);
    }
    expect(fn () => operationPermit($this->owner))->toThrow(OperationBlocked::class, 'retained_capacity');
    $this->operations->pauseAll($this->owner);
    expect($this->operations->policy($this->owner, Channel::Email)['paused'])->toBeTrue()
        ->and(operationPermit(User::factory()->create()))->toBeInstanceOf(OperationPermit::class);
    expect(DB::table('operation_reservations')->where('owner_id', $this->owner->id)->count())->toBe(2048);
});

test('next copilot admission is visibly exhausted when remaining units cannot cover its reservation', function () {
    operationLimit($this->owner, Channel::Copilot, ['units_daily' => 16385]);
    operationPermit($this->owner, Channel::Copilot)->finish('held');
    $channels = $this->operations->page($this->owner)['channels'];
    expect(array_values(array_filter($channels, fn ($row) => $row['channel'] === 'copilot'))[0]['state'])->toBe('exhausted');
});

test('invalid oauth callbacks cannot spend operational allowances before native authorization', function () {
    $wire = new DriveFixtures;
    $wire->fake();
    $drive = app(DriveExports::class);
    $start = $drive->begin($this->owner, 'authorized-session');
    $before = (array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first();
    $this->actingAs($this->owner)->getJson('/drive/callback?state='.str_repeat('f', 64).'&code=synthetic-code')
        ->assertUnprocessable()->assertJsonPath('definitive_no_write', true);
    expect(DB::table('operation_reservations')->count())->toBe(0);
    expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
    foreach ([['wrong-session', $start['state'], 'code'], ['authorized-session', str_repeat('e', 64), 'code'], ['authorized-session', $start['state'], '']] as [$session, $state, $code]) {
        expect(fn () => $drive->finish($this->owner, $session, $state, $code))->toThrow(DriveFailure::class, 'invalid_callback');
    }
    Date::setTestNow(now()->addMinutes(11));
    expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'invalid_callback');
    Date::setTestNow();
    config(['drive_export.mode' => 'disabled']);
    expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'disabled_or_restore_held');
    expect(DB::table('operation_reservations')->count())->toBe(0);
    expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
    Http::assertNothingSent();
    config(['drive_export.mode' => 'mock']);
    expect($drive->finish($this->owner, 'authorized-session', $start['state'], 'code')['status'])->toBe('account_review');
    expect(DB::table('operation_reservations')->count())->toBe(1);
    expect(DB::table('operation_reservations')->value('units'))->toBe(3);
    Http::assertSentCount(2);
    expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'invalid_callback');
    expect(DB::table('operation_reservations')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('callback budget rejection preserves the native state for a later explicit permitted attempt', function () {
    $wire = new DriveFixtures;
    $wire->fake();
    $drive = app(DriveExports::class);
    $start = $drive->begin($this->owner, 'authorized-session');
    $before = (array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first();
    DB::transaction(function () use ($drive, $start, $before): void {
        expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'caller_transaction_not_supported');
        expect(DB::table('operation_reservations')->count())->toBe(0);
        expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
        Http::assertNothingSent();
    });
    expect(DB::table('operation_reservations')->count())->toBe(0);
    expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
    Http::assertNothingSent();
    operationLimit($this->owner, Channel::Drive, ['requests_daily' => 0]);
    expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'operations_held');
    expect(DB::table('operation_reservations')->count())->toBe(0);
    expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
    Http::assertNothingSent();
    operationLimit($this->owner, Channel::Drive, ['requests_daily' => 1]);
    app()->instance(Operations::class, new Operations(1048576));
    expect(fn () => $drive->finish($this->owner, 'authorized-session', $start['state'], 'code'))->toThrow(DriveFailure::class, 'operations_held');
    expect(DB::table('operation_reservations')->count())->toBe(0);
    expect((array) DB::table('drive_controls')->where('owner_id', $this->owner->id)->first())->toBe($before);
    Http::assertNothingSent();
    app()->instance(Operations::class, $this->operations);
    expect($drive->finish($this->owner, 'authorized-session', $start['state'], 'code')['status'])->toBe('account_review');
    expect(DB::table('operation_reservations')->count())->toBe(1);
    expect(DB::table('drive_controls')->value('oauth_hash'))->toBeNull();
    Http::assertSentCount(2);
});
