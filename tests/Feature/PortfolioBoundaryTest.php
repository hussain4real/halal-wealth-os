<?php

use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Reporting\NetWorthSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PortfolioFixtures as P;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18T12:00:00Z'));
    $this->owner = User::factory()->create();
    $this->account = P::account($this->owner);
    $this->p = P::link($this->owner, $this->account);
});
test('database rejects excess fractional scale rather than silently rounding numeric values', function () {
    $asset = P::asset($this->owner);
    P::opening($this->owner, $this->p, [P::row($asset)]);
    $row = (array) DB::table('portfolio_opening_rows')->sole();
    unset($row['id']);
    foreach (['quantity' => '1.1234567890123', 'unit_price' => '2.0000000000001', 'basis_minor' => '-1'] as $field => $value) {
        expect(fn () => DB::transaction(function () use ($row, $field, $value) {
            $book = (array) DB::table('portfolio_openings')->sole();
            $prior = $book['id'];
            unset($book['id']);
            $next = DB::table('portfolio_openings')->insertGetId([...$book, 'reference' => (string) Str::ulid(), 'version' => 2, 'supersedes_id' => $prior]);
            DB::table('portfolio_opening_rows')->insert([...$row, 'opening_id' => $next, $field => $value]);
        }))->toThrow(PDOException::class, 'violates check constraint');
    }
    expect(DB::table('portfolio_opening_rows')->sole()->quantity)->toBe('1.234567890123');
});
test('raw asset and account references enforce owner currency kind and linear versions', function () {
    $asset = P::asset($this->owner);
    $other = User::factory()->create();
    $copy = (array) $asset;
    unset($copy['id']);
    $copy['reference'] = (string) Str::ulid();
    foreach ([['version' => 2, 'supersedes_id' => $asset->id, 'kind' => 'equity'], ['version' => 3, 'supersedes_id' => $asset->id], ['owner_id' => $other->id, 'version' => 2, 'supersedes_id' => $asset->id]] as $change) {
        expect(fn () => DB::transaction(fn () => DB::table('portfolio_assets')->insert([...$copy, ...$change])))->toThrow(PDOException::class);
    }
    $link = (array) $this->p;
    unset($link['id']);
    $link['reference'] = (string) Str::ulid();
    $link['owner_id'] = $other->id;
    expect(fn () => DB::transaction(fn () => DB::table('portfolio_accounts')->insert($link)))->toThrow(PDOException::class);
});
test('raw mode insertion must match reviewed payload and cannot reuse an owned preview for another account', function () {
    $m = P::mode($this->owner, $this->p);
    $copy = (array) $m;
    unset($copy['id']);
    $preview = app(PortfolioService::class)->preview($this->owner, (string) $this->p->id, P::modeInput());
    $copy = [...$copy, 'reference' => (string) Str::ulid(), 'version' => 2, 'supersedes_id' => $m->id, 'preview_id' => $preview->id];
    expect(fn () => DB::transaction(fn () => DB::table('portfolio_modes')->insert([...$copy, 'source' => 'Forged reviewed source'])))->toThrow(PDOException::class);
    expect(fn () => DB::transaction(fn () => DB::table('portfolio_modes')->insert([...$copy, 'supersedes_id' => null])))->toThrow(PDOException::class);
    $second = P::link($this->owner, P::account($this->owner));
    expect(fn () => DB::transaction(fn () => DB::table('portfolio_modes')->insert([...$copy, 'portfolio_account_id' => $second->id, 'version' => 1, 'supersedes_id' => null])))->toThrow(PDOException::class);
    $this->assertDatabaseCount('portfolio_modes', 1);
});
test('all retained portfolio evidence rejects mutation and destructive downgrade before DDL', function () {
    $asset = P::asset($this->owner);
    P::opening($this->owner, $this->p, [P::row($asset)]);
    P::mode($this->owner, $this->p);
    foreach (['portfolio_assets', 'portfolio_accounts', 'portfolio_openings', 'portfolio_opening_rows', 'portfolio_mode_previews', 'portfolio_modes', 'portfolio_requests'] as $table) {
        $id = DB::table($table)->first()->id;
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['owner_id' => $this->owner->id])))->toThrow(PDOException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))->toThrow(PDOException::class);
    }
    $migration = require database_path('migrations/2026_10_18_150000_create_portfolio_opening_evidence.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('portfolio_opening_rows', 1);
});
test('unsupported contradictions mixed native currencies duplicate families and oversized books roll back', function () {
    $asset = P::asset($this->owner);
    $manual = P::asset($this->owner, ['kind' => 'manual_investment']);
    $usd = P::asset($this->owner, ['currency' => 'USD']);
    foreach ([[P::row($asset, ['quantity' => '0'])], [P::row($asset, ['quantity' => '0.0000000000001'])], [P::row($asset, ['basis_known' => '0', 'cost_basis' => '0'])], [P::row($manual, ['quantity' => '0', 'unit_price' => '', 'manual_value' => '1'])], [P::row($asset, ['manual_value' => '1'])], [P::row($usd)], [P::row($asset), P::row($asset)], array_fill(0, 1001, P::row($asset))] as $rows) {
        expect(fn () => P::opening($this->owner, $this->p, $rows))->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('portfolio_openings', 0);
});
test('asset metadata correction retains exact referenced version and rejects stale correction', function () {
    $asset = P::asset($this->owner);
    P::opening($this->owner, $this->p, [P::row($asset)]);
    $next = P::asset($this->owner, ['prior_id' => (string) $asset->id, 'name' => 'Synthetic revised reference']);
    expect($next->version)->toBe(2);
    $s = app(PortfolioRead::class)->state($this->owner, (string) $this->p->id, '2026-10-18T12:00:00Z');
    expect($s['rows'][0]['name'])->toBe($asset->name)->and($s['rows'][0]['asset_version'])->toBe('1');
    expect(fn () => P::asset($this->owner, ['prior_id' => (string) $asset->id]))->toThrow(ValidationException::class);
});
test('archiving stales review and refuses fresh writes while exact committed requests remain replayable', function () {
    $asset = P::asset($this->owner);
    $input = ['prior_id' => '', 'as_of' => '2026-10-18', 'source' => 'Synthetic source', 'reason' => 'Synthetic reason', 'rows' => [P::row($asset)]];
    $first = app(PortfolioService::class)->opening($this->owner, (string) $this->p->id, 'retained-opening-retry', $input);
    $preview = app(PortfolioService::class)->preview($this->owner, (string) $this->p->id, P::modeInput());
    $this->account->archived_at = now();
    $this->account->save();
    expect($this->account->fresh()->archived_at)->not->toBeNull();
    expect(app(PortfolioService::class)->opening($this->owner, (string) $this->p->id, 'retained-opening-retry', $input)->id)->toBe($first->id);
    expect(fn () => app(PortfolioService::class)->confirm($this->owner, (string) $this->p->id, 'archived-confirm-key', (string) $preview->id, '1'))->toThrow(ValidationException::class);
    expect(fn () => app(PortfolioService::class)->preview($this->owner, (string) $this->p->id, P::modeInput()))->toThrow(ValidationException::class);
});
test('aggregate signed zero values and round trip detailed modes never add the retained positions twice', function () {
    P::opening($this->owner, $this->p, [P::row(P::asset($this->owner), ['quantity' => '3', 'unit_price' => '2'])]);
    foreach (['0' => '0', '-2.34' => '-234', '17.05' => '1705'] as $amount => $minor) {
        P::mode($this->owner, $this->p, ['mode' => 'aggregate', 'aggregate_value' => (string) $amount, 'aggregate_as_of' => '2026-10-18']);
        expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total']['minor'])->toBe($minor);
    }
    P::mode($this->owner, $this->p);
    expect(app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-18T12:00:00Z')['total']['minor'])->toBe('600');
});
test('saved portfolio report remains immutable through opening correction and mode switch', function () {
    $asset = P::asset($this->owner);
    $book = P::opening($this->owner, $this->p, [P::row($asset, ['quantity' => '1', 'unit_price' => '2'])]);
    P::mode($this->owner, $this->p);
    $report = app(NetWorthSnapshot::class)->save($this->owner, 'retained-report-identity', Currency::QAR, '2026-10-18T12:00:00Z');
    P::opening($this->owner, $this->p, [], ['prior_id' => (string) $book->id]);
    P::mode($this->owner, $this->p, ['mode' => 'aggregate', 'aggregate_value' => '18', 'aggregate_as_of' => '2026-10-18']);
    $again = app(NetWorthSnapshot::class)->save($this->owner, 'retained-report-identity', Currency::QAR, '2026-10-18T12:00:00Z');
    expect($again->id)->toBe($report->id)->and($again->evidence)->toBe($report->evidence);
    $e = json_decode($again->evidence, true, flags: JSON_THROW_ON_ERROR);
    expect($e['total']['minor'])->toBe('200')->and($e['components'][0]['portfolio']['mode'])->toBe('detailed')->and($e['components'][0]['portfolio']['positions'][0]['quantity'])->toBe('1');
});
test('asset only profile cannot be deleted or logged out through the financial retention guard', function () {
    $owner = User::factory()->create();
    P::asset($owner);
    $this->actingAs($owner)->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrors('password');
    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh())->not->toBeNull();
});
test('records before portfolio setup retain historical ledger contribution', function () {
    P::mode($this->owner, $this->p, ['mode' => 'aggregate', 'aggregate_value' => '17', 'aggregate_as_of' => '2026-10-18']);
    $s = app(NetWorthSnapshot::class)->capture($this->owner, Currency::QAR, '2026-10-17T12:00:00Z');
    expect($s['total']['minor'])->toBe('0')->and($s['components'][0]['portfolio']['mode'])->toBe('legacy_ledger');
});
