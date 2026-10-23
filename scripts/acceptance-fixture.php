<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Integrations\MarketFeed;
use App\Domain\Integrations\MarketRefresh;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Ledger\PostingService;
use App\Domain\Planning\GoalView;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DecisionFixtures;
use Tests\Support\DisposablePostgres;
use Tests\Support\MarketFixtures;
use Tests\Support\PortfolioFixtures as P;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
DisposablePostgres::assertConfiguration($app->environment(), $connection->getDriverName(), $connection->getConfig(), env('HWO_DISPOSABLE_DATABASE'));
if (DB::selectOne('SELECT current_database() AS name')->name !== 'halal_wealth_os_test') {
    throw new RuntimeException('Disposable connection identity mismatch.');
}
$command = $argv[1] ?? '';
$owner = fn (): User => User::where('email', 'acceptance-owner@example.test')->firstOrFail();
$result = match ($command) {
    'prepare' => (function (): array {
        $password = getenv('HWO_ACCEPTANCE_PASSWORD');
        if (! is_string($password) || strlen($password) < 20) {
            throw new RuntimeException('Synthetic acceptance password is missing.');
        }
        $users = [];
        foreach (['owner', 'foreign', 'admin'] as $kind) {
            $user = User::factory()->create(['email' => 'acceptance-'.$kind.'@example.test', 'password' => $password, 'is_admin' => $kind === 'admin']);
            $users[$kind] = (string) $user->id;
        }

        return ['users' => $users, 'date' => now('UTC')->toDateString(), 'upload_path' => route('livewire.upload-file', [], false), 'plan' => DecisionFixtures::input(['currency' => 'QAR', 'target' => '100', 'maximum' => '100', 'tranches' => '[{"key":"initial","label":"Initial proposal","amount":"60","condition":"Review terms"},{"key":"reserve","label":"Conditional proposal","amount":"40","condition":"Review evidence"}]'])];
    })(),
    'market-prepare' => (function () use ($owner): array {
        $user = $owner();
        $account = P::link($user, P::account($user, Currency::USD));
        P::mode($user, $account);
        $asset = P::asset($user, ['currency' => 'USD', 'identifier' => 'SYNTH', 'market' => 'Synthetic XNAS listing']);

        return ['portfolio_account_id' => (string) $account->id, 'asset_id' => (string) $asset->id,
            'context' => app(PriceObservationService::class)->context($user, (string) $account->id)];
    })(),
    'market-refresh' => (function () use ($owner, $argv): array {
        $mapping = LedgerInput::owned('market_mappings', $owner(), $argv[2]);
        $feed = MarketFeed::from(json_decode($mapping->feed, true, flags: JSON_THROW_ON_ERROR));
        config(['market_data.mode' => 'mock']);
        Http::preventStrayRequests();
        Http::fake(['api.twelvedata.com/*' => ($argv[3] ?? '') === 'timeout'
            ? Http::failedConnection()
            : Http::response(MarketFixtures::priceBody(now('America/New_York')->subDays(10)->toDateString()))]);
        $before = DB::table('portfolio_prices')->where('owner_id', $owner()->id)->orderBy('id')->get()->toJson();
        $result = app(MarketRefresh::class)->refresh($feed, now('UTC')->toDateString());

        return ['result' => $result, 'prior_unchanged' => $before === DB::table('portfolio_prices')->where('owner_id', $owner()->id)->orderBy('id')->get()->toJson()];
    })(),
    'inspect' => (function () use ($owner): array {
        $user = $owner();
        $cutoff = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $portfolio = DB::table('portfolio_accounts')->where('owner_id', $user->id)->first();
        $rows = $portfolio === null ? [] : app(PortfolioRead::class)->state($user, (string) $portfolio->id, $cutoff)['rows'];
        $balances = DB::table('postings as p')->join('accounts as a', 'a.id', '=', 'p.account_id')->where('p.owner_id', $user->id)->whereNull('a.system_role')->groupBy('a.id', 'a.name')->selectRaw('a.id,a.name,SUM(p.amount_minor)::text AS minor')->get()->all();
        $unbalanced = DB::table('postings')->where('owner_id', $user->id)->select('journal_id', 'currency_code')->groupBy('journal_id', 'currency_code')->havingRaw('SUM(amount_minor) <> 0')->get()->count();
        $dashboard = app(DashboardSnapshot::class)->current($user, Currency::QAR);

        return ['postings' => DB::table('postings')->where('owner_id', $user->id)->count(), 'journals' => DB::table('journal_entries')->where('owner_id', $user->id)->count(), 'events' => DB::table('investment_events')->where('owner_id', $user->id)->count(), 'unbalanced' => $unbalanced, 'balances' => $balances, 'positions' => $rows, 'dashboard' => $dashboard];
    })(),
    'goal' => (function () use ($owner, $argv): array {
        $view = app(GoalView::class)->show($owner(), $argv[2], now('UTC')->format('Y-m'));

        return ['goal' => $view['goal'], 'links' => DB::table('goal_funding_links')->where('owner_id', $owner()->id)->count(), 'backed_minor' => $view['goal']['backed']['minor']];
    })(),
    'fingerprint' => (function () use ($owner): array {
        $data = [];
        foreach (DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema='public' AND column_name='owner_id' ORDER BY table_name") as $table) {
            $rows = DB::table($table->table_name)->where('owner_id', $owner()->id)->get()->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $data[$table->table_name] = $rows;
        }

        return ['sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))];
    })(),
    'posting' => (function () use ($owner, $argv): array {
        $row = DB::table('postings')->where('owner_id', $owner()->id)->where('journal_id', $argv[2] ?? '')->where('amount_minor', '>', 0)->orderBy('id')->first();
        if ($row === null) {
            throw new RuntimeException('Missing owned destination posting.');
        }

        return ['id' => (string) $row->id];
    })(),
    'price-context' => (function () use ($owner, $argv): array {
        return ['context' => app(PriceObservationService::class)->context($owner(), $argv[2] ?? '')];
    })(),
    'reset-password' => (function () use ($owner): array {
        app(ResetUserPassword::class)->reset($owner(), ['password' => getenv('HWO_ACCEPTANCE_NEW_PASSWORD'), 'password_confirmation' => getenv('HWO_ACCEPTANCE_NEW_PASSWORD')]);

        return ['changed' => true];
    })(),
    'query-plans' => (function (): array {
        $queries = json_decode(file_get_contents(dirname(getenv('HWO_ACCEPTANCE_METRICS')).'/query-profile.json'), true, flags: JSON_THROW_ON_ERROR);
        uasort($queries, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $plans = [];
        foreach ($queries as $fingerprint => $query) {
            if (! str_starts_with(strtolower($query['sql']), 'select ') || preg_match('/pg_|for update|for share/i', $query['sql'])) {
                continue;
            }
            $result = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'], $query['bindings']);
            $plan = json_decode($result[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
            $plans[] = ['fingerprint' => $fingerprint, 'plan' => $plan];
            if (count($plans) === 3) {
                break;
            }
        }
        if (count($plans) !== 3) {
            throw new RuntimeException('Missing three actual slow-query plans.');
        }

        return $plans;
    })(),
    'benchmark' => (function () use ($owner): array {
        $user = $owner();
        $account = P::account($user);
        $portfolio = P::link($user, $account);
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $asset = P::asset($user, ['name' => 'Synthetic workload asset '.$i, 'identifier' => 'SYNTHETIC-WORKLOAD-'.$i]);
            $rows[] = P::row($asset, ['quantity' => '1', 'unit_price' => '2', 'basis_known' => '1', 'cost_basis' => '1']);
        }
        P::opening($user, $portfolio, $rows);
        P::mode($user, $portfolio);
        for ($i = 0; $i < 5000; $i++) {
            app(PostingService::class)->record($user, 'synthetic-workload-income-'.$i, ['kind' => 'income', 'account_id' => (string) $account->id, 'amount' => '1', 'effective_date' => now('UTC')->toDateString(), 'description' => 'Synthetic bounded workload']);
        }
        $state = app(PortfolioRead::class)->state($user, (string) $portfolio->id, now('UTC')->format('Y-m-d\TH:i:s\Z'));
        $positive = array_filter($state['rows'], fn (array $row): bool => bccomp($row['quantity'], '0', 12) > 0);

        return ['postings' => DB::table('postings')->count(), 'holdings' => count($positive), 'portfolio_id' => (string) $portfolio->id, 'expected_net_worth_minor' => '520000'];
    })(),
    default => throw new RuntimeException('Unknown acceptance fixture operation.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
