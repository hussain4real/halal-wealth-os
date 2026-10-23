<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Reporting\InvestmentValuation;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Observations\ObservationRead;
use App\Http\Requests\ValuationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

class ValuationController extends Controller
{
    public function index(Request $request): Page
    {
        $owner = $this->owner($request);
        $input = $this->input($request);
        $result = app(InvestmentValuation::class)->capture($owner, PortfolioInput::currency($input['base_currency']), $input['cutoff'], $input['account_id'] ?: null, $input['start'] ?: null);
        $offset = $request->query('after', '0');
        if (! is_string($offset) || ! preg_match('/^[0-9]{1,7}$/D', $offset)) {
            LedgerInput::fail('after', 'Use a valid component offset.');
        }
        $rows = $result['ending']['components'];
        $result['ending']['components'] = array_slice($rows, (int) $offset, 20);
        // Full provenance and all rows remain in authenticated complete export/saved evidence.
        unset($result['ending']['accounts'], $result['ending']['net_worth_snapshot']);
        if ($result['beginning'] !== null) {
            unset($result['beginning']['accounts'], $result['beginning']['net_worth_snapshot'], $result['beginning']['components']);
        }
        $runQuery = DB::table('investment_valuation_runs')->where('owner_id', $owner->id);
        if (($runAfter = $request->query('runs_after')) !== null) {
            $runQuery->where('id', '<', LedgerInput::identifier($runAfter));
        }
        $runs = $runQuery->orderByDesc('id')->limit(21)->get(['id', 'reference', 'created_at']);

        return Inertia::render('portfolio/Valuation', ['input' => $input, 'result' => $result, 'next' => count($rows) > (int) $offset + 20 ? (string) ((int) $offset + 20) : null,
            'currencies' => array_map(fn (Currency $c): string => $c->value, Currency::cases()),
            'accounts' => DB::table('accounts')->where('owner_id', $owner->id)->whereNull('system_role')->whereIn('type', ['bank', 'cash', 'investment', 'liability'])->orderBy('id')->get(['id', 'name', 'currency_code'])->map(fn ($a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency_code])->all(),
            'runs_next' => $runs->count() > 20 ? (string) $runs[19]->id : null, 'runs' => $runs->take(20)->map(fn ($r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'created_at' => LedgerInput::utc($r->created_at)])->all()]);
    }

    public function store(ValuationRequest $request): JsonResponse|RedirectResponse
    {
        $v = $request->validated();
        $row = app(InvestmentValuation::class)->save($this->owner($request), $v['idempotency_key'], PortfolioInput::currency($v['base_currency']), $v['cutoff'], empty($v['account_id']) ? null : $v['account_id'], empty($v['start']) ? null : $v['start']);

        return $this->saved($request, $row, '/valuations/'.$row->id);
    }

    public function show(Request $request, string $run): Page
    {
        $row = LedgerInput::owned('investment_valuation_runs', $this->owner($request), $run);

        return Inertia::render('portfolio/ValuationRun', ['run' => ['id' => (string) $row->id, 'reference' => $row->reference, 'hash' => $row->evidence_hash, 'created_at' => LedgerInput::utc($row->created_at)],
            'evidence' => json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR)]);
    }

    public function export(Request $request, ?string $run = null): Response
    {
        if ($run !== null) {
            $row = LedgerInput::owned('investment_valuation_runs', $this->owner($request), $run);
            $evidence = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);
        } else {
            $v = $this->input($request);
            $evidence = app(InvestmentValuation::class)->capture($this->owner($request), PortfolioInput::currency($v['base_currency']), $v['cutoff'], $v['account_id'] ?: null, $v['start'] ?: null);
        }

        return response(json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200,
            ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="investment-valuation.json"', 'Cache-Control' => 'private, no-store']);
    }

    public function prices(Request $request, string $portfolio): Page
    {
        $owner = $this->owner($request);
        $p = app(PortfolioRead::class)->account($owner, $portfolio);
        $query = DB::table('portfolio_prices')->where('owner_id', $owner->id)->where('portfolio_account_id', $portfolio);
        if (($after = $request->query('after')) !== null) {
            $query->where('id', '<', LedgerInput::identifier($after));
        }
        $rows = $query->orderByDesc('id')->limit(21)->get();
        $assetQuery = DB::table('portfolio_assets')->where('owner_id', $owner->id)->where('currency', $p->currency)->where('kind', '<>', 'manual_investment')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('portfolio_assets as newer')->whereColumn('newer.supersedes_id', 'portfolio_assets.id'));
        $search = $request->query('q', '');
        if (! is_string($search) || mb_strlen($search) > 120) {
            LedgerInput::fail('q', 'Use a search up to 120 characters.');
        }
        if ($search !== '') {
            $assetQuery->where('name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }
        $prior = null;
        if (($priorId = $request->query('revise')) !== null) {
            $prior = LedgerInput::owned('portfolio_prices', $owner, LedgerInput::identifier($priorId));
            abort_unless((string) $prior->portfolio_account_id === $portfolio, 404);
        }
        if (($after = $request->query('asset_after')) !== null) {
            $assetQuery->where('id', '<', LedgerInput::identifier($after));
        }
        $assets = $assetQuery->orderByDesc('id')->limit(21)->get(['id', 'name', 'currency', 'version']);
        $assetNext = $assets->count() > 20 ? (string) $assets[19]->id : null;
        $assets = $assets->take(20);
        if ($prior !== null && ! $assets->contains('id', $prior->asset_id)) {
            $assets->push(LedgerInput::owned('portfolio_assets', $owner, (string) $prior->asset_id));
        }

        return Inertia::render('portfolio/Prices', ['account' => ['id' => $portfolio, 'name' => $p->name, 'currency' => $p->currency, 'archived' => $p->archived],
            'prices' => $rows->take(20)->map(fn ($r): array => $this->price($r))->values()->all(), 'next' => $rows->count() > 20 ? (string) $rows[19]->id : null,
            'assets' => $assets->map(fn ($a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency, 'version' => (string) $a->version])->all(),
            'asset_next' => $assetNext, 'context' => app(PriceObservationService::class)->context($owner, $portfolio), 'prior' => $prior === null ? null : $this->price($prior), 'query' => $search, 'now' => now('UTC')->format('Y-m-d\TH:i:s\Z')]);
    }

    public function priceStore(ValuationRequest $request, string $portfolio): JsonResponse|RedirectResponse
    {
        $row = app(PriceObservationService::class)->record($this->owner($request), $request->string('idempotency_key')->toString(),
            ['portfolio_account_id' => $portfolio, ...$request->safe()->except('idempotency_key')]);

        return $this->saved($request, $row, '/portfolio/'.$portfolio.'/prices');
    }

    /** @return array<string,mixed> */
    private function price(stdClass $r): array
    {
        return ['id' => (string) $r->id, 'asset_id' => (string) $r->asset_id, 'version' => (string) $r->version, 'series_id' => $r->series_id, 'unit_price' => (string) $r->unit_price,
            'source' => $r->source, 'reason' => $r->reason, ...ObservationRead::evidence($r), 'split_basis' => $r->split_basis];
    }

    /** @return array{base_currency:string,cutoff:string,start:string,account_id:string} */
    private function input(Request $request): array
    {
        $input = ['base_currency' => $request->query('base_currency', 'QAR'), 'cutoff' => $request->query('cutoff', now('UTC')->format('Y-m-d\TH:i:s\Z')), 'start' => $request->query('start', ''), 'account_id' => $request->query('account_id', '')];
        foreach ($input as $k => $v) {
            if (! is_string($v) || strlen($v) > 40) {
                LedgerInput::fail($k, 'Use an explicit text value within the supported range.');
            }
        }
        if ($input['account_id'] !== '') {
            $input['account_id'] = LedgerInput::identifier($input['account_id']);
        }

        return $input;
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function saved(Request $request, stdClass $row, string $url): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201);
        }

        return redirect($url, 303);
    }
}
