<?php

use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Notifications\Handoff;
use App\Domain\Notifications\ReminderMail;
use App\Domain\Notifications\ReminderMessage;
use App\Domain\Notifications\ReminderPolicy;
use App\Domain\Notifications\Reminders;
use App\Domain\Planning\BudgetService;
use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Jobs\DeliverReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BudgetFixtures as B;
use Tests\Support\DashboardFixtures;
use Tests\Support\FxFixtures as Fx;
use Tests\Support\GoalFixtures;
use Tests\Support\ReminderFixtures as N;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00Z'));
    $this->owner = User::factory()->create();
    Mail::fake();
    $this->reminders = app(Reminders::class);
});

test('reminders are all off by default including standing orders and ordinary dashboard rules', function () {
    N::schedule($this->owner);
    expect($this->reminders->settings($this->owner)['data'])->toBe(ReminderPolicy::defaults());
    expect($this->reminders->plan($this->owner))->toBe(['status' => 'not_opted_in', 'ids' => []]);
    N::configure($this->owner, ReminderPolicy::defaults());
    expect($this->reminders->plan($this->owner)['ids'])->toBe([]);
    Mail::assertNothingSent();
    $this->assertDatabaseCount('reminder_notifications', 0);
});

test('duplicate schedule planning jobs and committed preference retries retain one logical notification', function () {
    $schedule = N::schedule($this->owner);
    $data = N::preferences($this->owner, $schedule);
    $first = $this->reminders->configure($this->owner, 'notification-settings-01', '', $data);
    expect($this->reminders->configure($this->owner, 'notification-settings-01', '', $data)->id)->toBe($first->id);
    $ids = $this->reminders->plan($this->owner)['ids'];
    expect($this->reminders->plan($this->owner)['ids'])->toBe($ids);
    foreach ($ids as $id) {
        (new DeliverReminder($id))->handle($this->reminders);
        (new DeliverReminder($id))->handle($this->reminders);
    }
    $this->assertDatabaseCount('reminder_notifications', 1);
    $this->assertDatabaseCount('reminder_channels', 2);
    $this->assertDatabaseCount('reminder_claims', 2);
    $this->assertDatabaseCount('reminder_results', 2);
    Mail::assertSent(ReminderMessage::class, 1);
    $items = $this->reminders->inbox($this->owner)['items'];
    expect(array_column($items, 'status'))->toContain('in_app', 'mock_smtp');
    $this->assertDatabaseCount('journal_entries', 0);
});

test('quiet hours cross Qatar midnight and full grace days without manufacturing a new episode', function () {
    $schedule = N::schedule($this->owner, ['first_due_date' => '2026-10-23']);
    N::configure($this->owner, N::preferences($this->owner, $schedule, ['grace_days' => 1, 'quiet_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00']));
    $this->travelTo(new DateTimeImmutable('2026-10-24T20:59:59Z'));
    expect($this->reminders->plan($this->owner)['ids'])->toBe([]);
    $this->travelTo(new DateTimeImmutable('2026-10-24T21:00:00Z'));
    $ids = $this->reminders->plan($this->owner)['ids'];
    expect($ids)->toHaveCount(2)->and($this->reminders->dispatch(N::channel($this->owner, 'in_app')))->toMatchArray(['status' => 'held', 'code' => 'quiet_or_not_due']);
    $this->travelTo(new DateTimeImmutable('2026-10-25T04:00:00Z'));
    expect($this->reminders->dispatch(N::channel($this->owner, 'in_app'))['status'])->toBe('in_app');
    expect($this->reminders->plan($this->owner)['ids'])->toBe($ids);
});

test('complete posted matches beyond allocation preview suppress queued missed contributions', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $ids = $this->reminders->plan($this->owner)['ids'];
    $account = Fx::account($this->owner);
    for ($i = 0; $i < 11; $i++) {
        $journal = app(PostingService::class)->record($this->owner, 'reminder-income-'.$i, ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '1.00', 'effective_date' => '2026-10-20', 'description' => 'Synthetic contribution']);
        app(BudgetService::class)->allocate($this->owner, 'reminder-match-'.$i, (string) $schedule->id, '2026-10-20', (string) $journal->id, '1.00');
    }
    foreach ($ids as $id) {
        expect($this->reminders->dispatch($id))->toMatchArray(['status' => 'held', 'code' => 'no_longer_current']);
    }
    $this->assertDatabaseCount('reminder_claims', 0);
    Mail::assertNothingSent();
    expect($this->reminders->inbox($this->owner)['items'])->toHaveCount(2);
});

test('partial matching and release reopen an unsent occurrence without resending a delivered one', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $account = Fx::account($this->owner);
    $journal = app(PostingService::class)->record($this->owner, 'reminder-partial-income', ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '11.00', 'effective_date' => '2026-10-20', 'description' => 'Synthetic contribution']);
    $match = app(BudgetService::class)->allocate($this->owner, 'reminder-partial-match', (string) $schedule->id, '2026-10-20', (string) $journal->id, '10.00');
    $ids = $this->reminders->plan($this->owner)['ids'];
    expect($ids)->toHaveCount(2);
    expect($this->reminders->dispatch(N::channel($this->owner, 'email'))['status'])->toBe('mock_smtp');
    app(BudgetService::class)->release($this->owner, 'reminder-match-release', (string) $match->id, 'Synthetic release');
    expect($this->reminders->plan($this->owner)['ids'])->toBe($ids);
    expect($this->reminders->dispatch(N::channel($this->owner, 'email'))['status'])->toBe('mock_smtp');
    Mail::assertSent(ReminderMessage::class, 1);
});

test('default and detailed external messages exclude money holdings and personal names', function () {
    $schedule = N::schedule($this->owner, ['name' => 'SECRET_ACCOUNT_GOAL_NAME', 'amount' => '987.65']);
    $data = N::preferences($this->owner, $schedule, ['detail' => 'review']);
    N::configure($this->owner, $data);
    $ids = $this->reminders->plan($this->owner)['ids'];
    N::configure($this->owner, [...$data, 'detail' => 'generic']);
    $this->reminders->dispatch(N::channel($this->owner, 'email'));
    Mail::assertSent(ReminderMessage::class, function ($message) {
        $body = $message->render();
        expect($body)->not->toContain('SECRET_ACCOUNT_GOAL_NAME', '987.65', $this->owner->name, 'Scheduled contribution review');
        expect($body)->toContain('/notifications');

        return true;
    });
    expect($this->reminders->preview('review')['body'])->toContain('Scheduled contribution review', '2030-01-15');
    expect($this->reminders->preview('generic')['body'])->not->toContain('2030-01-15');
});

test('pause stops new external handoffs preserves waiting evidence and works at byte capacity', function () {
    $schedule = N::schedule($this->owner);
    $data = N::preferences($this->owner, $schedule);
    N::configure($this->owner, $data);
    $ids = $this->reminders->plan($this->owner)['ids'];
    $full = new Reminders(app(ReminderMail::class), 1);
    $full->pause($this->owner);
    expect($full->dispatch(N::channel($this->owner, 'email')))->toMatchArray(['status' => 'held', 'code' => 'paused']);
    expect($full->inbox($this->owner)['items'])->toHaveCount(2);
    expect(fn () => $full->configure($this->owner, 'reminder-full-reenable', $full->settings($this->owner)['token'], $data))->toThrow(ValidationException::class);
    expect($full->settings($this->owner)['data']['external_paused'])->toBeTrue();
    Mail::assertNothingSent();
    $this->assertDatabaseCount('reminder_preferences', 1);
});

test('unknown SMTP handoff and orphan claims remain inspectable and never automatically resend', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $ids = $this->reminders->plan($this->owner)['ids'];
    $transport = new class extends ReminderMail
    {
        public int $calls = 0;

        public function send(string $recipient, ReminderMessage $message): Handoff
        {
            $this->calls++;
            throw new RuntimeException('Synthetic SMTP connection loss after handoff');
        }
    };
    $service = new Reminders($transport);
    expect($service->dispatch(N::channel($this->owner, 'email'))['status'])->toBe('uncertain');
    expect($service->dispatch(N::channel($this->owner, 'email'))['status'])->toBe('uncertain')->and($transport->calls)->toBe(1);
    DB::table('reminder_claims')->insert(['owner_id' => $this->owner->id, 'channel_id' => N::channel($this->owner, 'in_app'), 'ordinal' => 1, 'permit_hash' => hash('sha256', 'lost'), 'destination_hash' => hash('sha256', 'in_app')]);
    expect($service->dispatch(N::channel($this->owner, 'in_app'))['status'])->toBe('uncertain');
    expect($service->inbox($this->owner)['items'][0]['status'])->toBe('uncertain');
});

test('only proved no handoff schedules finite retries and respects current quiet policy', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $this->reminders->plan($this->owner);
    $id = N::channel($this->owner, 'email');
    $transport = new class extends ReminderMail
    {
        public int $calls = 0;

        public function send(string $recipient, ReminderMessage $message): Handoff
        {
            $this->calls++;

            return Handoff::BeforeHandoffFailure;
        }
    };
    $service = new Reminders($transport);
    expect($service->dispatch($id)['status'])->toBe('retryable');
    expect($service->dispatch($id)['status'])->toBe('deferred');
    $this->travel(60)->seconds();
    expect($service->dispatch($id)['status'])->toBe('retryable');
    $this->travel(300)->seconds();
    expect($service->dispatch($id)['status'])->toBe('retryable');
    $this->travel(300)->seconds();
    expect($service->dispatch($id)['status'])->toBe('exhausted')->and($transport->calls)->toBe(3);
    $this->assertDatabaseCount('reminder_claims', 3);
});

test('live SMTP is closed even with configured credentials and explicit owner email opt in', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $this->reminders->plan($this->owner);
    $id = N::channel($this->owner, 'email');
    app()->detectEnvironment(fn () => 'production');
    try {
        expect($this->reminders->dispatch($id))->toMatchArray(['status' => 'held', 'code' => 'live_blocked']);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    Mail::assertNothingSent();
    $this->assertDatabaseCount('reminder_claims', 0);
});

test('fresh verification opt out and changed destination block queued mail without rerouting', function () {
    $schedule = N::schedule($this->owner);
    $data = N::preferences($this->owner, $schedule);
    N::configure($this->owner, $data);
    $this->reminders->plan($this->owner);
    $id = N::channel($this->owner, 'email');
    $this->owner->forceFill(['email_verified_at' => null])->save();
    expect($this->reminders->dispatch($id)['code'])->toBe('unverified');
    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $off = $data;
    $off['rules']['contribution']['email'] = false;
    N::configure($this->owner, $off);
    expect($this->reminders->dispatch($id)['code'])->toBe('opted_out');
    N::configure($this->owner, $data);
    DB::table('reminder_claims')->insert(['owner_id' => $this->owner->id, 'channel_id' => $id, 'ordinal' => 1, 'permit_hash' => hash('sha256', 'first'), 'destination_hash' => hash('sha256', $this->owner->email)]);
    $claim = DB::table('reminder_claims')->first();
    DB::table('reminder_results')->insert(['owner_id' => $this->owner->id, 'claim_id' => $claim->id, 'preference_id' => $this->reminders->settings($this->owner)['id'], 'outcome' => 'retryable', 'safe_code' => 'proved_no_handoff', 'message_hash' => hash('sha256', ''), 'retry_at' => now()->subMinute()]);
    $this->owner->forceFill(['email' => 'changed-synthetic@example.test'])->save();
    expect($this->reminders->dispatch($id)['code'])->toBe('destination_changed');
    Mail::assertNothingSent();
});

test('bounded scanner recovers lost queue hints using ID only jobs', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    expect($this->reminders->tick())->toMatchArray(['owners' => 1, 'next' => null]);
    $this->reminders->tick();
    Mail::assertSent(ReminderMessage::class, 1);
    $this->assertDatabaseCount('reminder_results', 2);
    $this->assertDatabaseCount('reminder_channels', 2);
});

test('owner scoped inbox read and preference identities reject foreign resources and recover committed requests', function () {
    $schedule = N::schedule($this->owner);
    $data = N::preferences($this->owner, $schedule);
    $settings = $this->reminders->configure($this->owner, 'reminder-owned-settings', '', $data);
    $this->reminders->plan($this->owner);
    $id = (string) DB::table('reminder_notifications')->value('id');
    $this->reminders->read($this->owner, $id);
    $this->reminders->read($this->owner, $id);
    $this->assertDatabaseCount('reminder_reads', 1);
    $other = User::factory()->create();
    expect($this->reminders->inbox($other)['items'])->toBeEmpty();
    expect(fn () => N::configure($other, $data))->toThrow(HttpException::class);
    $this->actingAs($other)->post('/notifications/'.$id.'/read')->assertNotFound();
    N::configure($this->owner, [...$data, 'detail' => 'review']);
    expect($this->reminders->configure($this->owner, 'reminder-owned-settings', '', $data)->id)->toBe($settings->id);
    expect(fn () => $this->reminders->configure($this->owner, 'reminder-owned-settings', '', [...$data, 'detail' => 'review']))->toThrow(ValidationException::class);
    expect($this->reminders->settings($this->owner)['data']['detail'])->toBe('review');
});

test('reminder settings and synthetic preview routes preserve explicit opt in without creating deliveries', function () {
    $schedule = N::schedule($this->owner);
    $this->actingAs($this->owner)->get('/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('notifications/Index')->has('schedules', 1));
    $wire = N::wire(N::preferences($this->owner, $schedule));
    $response = $this->postJson('/notifications/preferences', $wire)->assertCreated();
    $this->postJson('/notifications/preferences', $wire)->assertCreated()->assertJson(['id' => $response['id']]);
    $this->getJson('/notifications/preview?detail=review')->assertOk()->assertJson(['mode' => 'synthetic_preview_no_send']);
    $this->getJson('/notifications/preview?detail=invalid')->assertUnprocessable();
    $this->post('/notifications/pause')->assertRedirect('/notifications');
    expect($this->reminders->settings($this->owner)['data']['external_paused'])->toBeTrue();
    $this->assertDatabaseCount('reminder_channels', 0);
    Mail::assertNothingSent();
});

test('a stale preference form cannot undo a newer external pause and exact old receipts do not rewind it', function () {
    $schedule = N::schedule($this->owner);
    $data = N::preferences($this->owner, $schedule);
    $first = $this->reminders->configure($this->owner, 'reminder-before-pause', '', $data);
    $prior = $this->reminders->settings($this->owner)['token'];
    $this->reminders->pause($this->owner);
    expect(fn () => $this->reminders->configure($this->owner, 'reminder-stale-resume', $prior, [...$data, 'detail' => 'review']))->toThrow(ValidationException::class);
    expect($this->reminders->configure($this->owner, 'reminder-before-pause', '', $data)->id)->toBe($first->id);
    expect($this->reminders->settings($this->owner)['data']['external_paused'])->toBeTrue();
    N::configure($this->owner, $data);
    expect($this->reminders->settings($this->owner)['data']['external_paused'])->toBeFalse();
});

test('full byte capacity is visible and in app publication rolls back atomically before retry', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $this->reminders->plan($this->owner);
    $full = new Reminders(app(ReminderMail::class), 1);
    expect($full->inbox($this->owner)['capacity']['full'])->toBeTrue();
    $id = N::channel($this->owner, 'in_app');
    expect($full->dispatch($id))->toMatchArray(['status' => 'held', 'code' => 'capacity']);
    $this->assertDatabaseCount('reminder_claims', 0);
    $this->assertDatabaseCount('reminder_results', 0);
    expect($this->reminders->dispatch($id)['status'])->toBe('in_app');
    expect($this->reminders->dispatch($id)['status'])->toBe('in_app');
    $this->assertDatabaseCount('reminder_claims', 1);
    $this->assertDatabaseCount('reminder_results', 1);
});

test('current dashboard dismissal and disabled dashboard policy suppress queued actions', function () {
    $account = Fx::account($this->owner);
    GoalFixtures::cash($this->owner, $account, '100.00');
    $data = ReminderPolicy::defaults();
    $data['rules']['reconciliation'] = ['in_app' => true, 'email' => false];
    $data['quiet_enabled'] = false;
    $data['send_after'] = '00:00';
    N::configure($this->owner, $data);
    $ids = $this->reminders->plan($this->owner)['ids'];
    expect($ids)->toHaveCount(1);
    $dashboard = app(DashboardSnapshot::class);
    $action = $dashboard->current($this->owner, Currency::QAR)['actions'][0];
    $dashboard->dismiss($this->owner, 'reminder-dismiss-current', ['snapshot_id' => '', 'base_currency' => 'QAR', 'action_key' => $action['key'], 'reason' => 'Synthetic reviewed episode']);
    expect($this->reminders->dispatch($ids[0])['code'])->toBe('no_longer_current');
    $dashboard->configure($this->owner, 'reminder-disable-rule', DashboardFixtures::settings(['reconciliation' => '0']));
    expect($this->reminders->plan($this->owner)['ids'])->toBeEmpty();
    $this->assertDatabaseCount('reminder_claims', 0);
});

test('quiet windows honor inclusive start exclusive end send time and DST wall clock', function (array $change, string $instant, bool $allowed) {
    $data = [...ReminderPolicy::defaults(), 'send_after' => '00:00', ...$change];
    expect(ReminderPolicy::allowed($data, CarbonImmutable::parse($instant)))->toBe($allowed);
})->with([
    [[], '2026-10-24T18:59:59Z', true], [[], '2026-10-24T19:00:00Z', false], [[], '2026-10-25T03:59:59Z', false], [[], '2026-10-25T04:00:00Z', true],
    [['quiet_start' => '10:00', 'quiet_end' => '12:00'], '2026-10-24T07:00:00Z', false], [['quiet_start' => '10:00', 'quiet_end' => '12:00'], '2026-10-24T09:00:00Z', true],
    [['send_after' => '08:00'], '2026-10-24T04:59:59Z', false],
    [['timezone' => 'Europe/London', 'quiet_start' => '01:00', 'quiet_end' => '02:00'], '2026-10-25T00:30:00Z', false],
    [['timezone' => 'Europe/London', 'quiet_start' => '01:00', 'quiet_end' => '02:00'], '2026-10-25T01:30:00Z', false],
    [['timezone' => 'Europe/London', 'quiet_start' => '01:00', 'quiet_end' => '02:00'], '2026-03-29T01:00:00Z', true],
]);

test('invalid reminder settings reject without retained writes', function (array $changes) {
    expect(fn () => N::configure($this->owner, [...ReminderPolicy::defaults(), ...$changes]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('reminder_preferences', 0);
})->with([
    [['timezone' => 'invalid/zone']], [['grace_days' => -1]], [['grace_days' => 31]], [['detail' => 'financial']],
    [['quiet_start' => '07:00', 'quiet_end' => '07:00']], [['external_paused' => 'false']], [['unrecognized' => true]],
    [['schedule_ids' => array_fill(0, 33, '1')]], [['rules' => []]],
]);

test('revised schedule versions require explicit fresh selection and February grace is not skipped', function () {
    $this->travelTo(new DateTimeImmutable('2027-03-03T12:00:00Z'));
    $schedule = N::schedule($this->owner, ['first_due_date' => '2027-01-31']);
    $data = N::preferences($this->owner, $schedule, ['grace_days' => 30]);
    N::configure($this->owner, $data);
    $this->reminders->plan($this->owner);
    expect(DB::table('reminder_notifications')->where('due_date', '2027-01-31')->count())->toBe(1);
    app(BudgetService::class)->schedule($this->owner, 'reminder-schedule-revision', [...B::input(LedgerInput::owned('planning_categories', $this->owner, (string) $schedule->category_id)), 'cutover_date' => '2027-03-03', 'first_due_date' => '2027-03-03', 'reason' => 'Synthetic revision'], (string) $schedule->id);
    expect(fn () => N::configure($this->owner, $data))->toThrow(ValidationException::class);
});

test('SMTP boundary refuses an unfaked manager and treats adapter exceptions as unknown', function () {
    $message = new ReminderMessage('Synthetic safe subject', 'Synthetic body', hash('sha256', 'synthetic'));
    Mail::swap(new MailManager(app()));
    expect(app(ReminderMail::class)->send('synthetic@example.test', $message))->toBe(Handoff::BeforeHandoffFailure);
    $fake = Mockery::mock(MailFake::class, [app('mail.manager')])->makePartial();
    $fake->shouldReceive('mailer')->once()->with('smtp')->andThrow(new RuntimeException('Synthetic adapter loss'));
    Mail::swap($fake);
    expect(app(ReminderMail::class)->send('synthetic@example.test', $message))->toBe(Handoff::Uncertain);
});

test('hard export overflow from other retained writers preserves inbox inspection and unconditional pause', function () {
    $schedule = N::schedule($this->owner);
    N::configure($this->owner, N::preferences($this->owner, $schedule));
    $this->reminders->plan($this->owner);
    DB::statement("INSERT INTO dashboard_snapshots(owner_id,reference,idempotency_key,payload_hash,evidence,evidence_hash) VALUES (?,?,'synthetic-hard-capacity',?,jsonb_build_object('synthetic',repeat('x',67108864)),?)", [$this->owner->id, (string) Str::ulid(), hash('sha256', 'synthetic'), hash('sha256', 'synthetic')]);
    $this->reminders->pause($this->owner);
    $inbox = $this->reminders->inbox($this->owner);
    expect($inbox['capacity']['full'])->toBeTrue()->and($inbox['settings']['data']['external_paused'])->toBeTrue()->and($inbox['items'])->toHaveCount(2);
    expect($this->reminders->dispatch(N::channel($this->owner, 'in_app')))->toMatchArray(['status' => 'held', 'code' => 'capacity']);
    $this->assertDatabaseCount('reminder_claims', 0);
});
