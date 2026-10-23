<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Integrations\MarketRefresh;
use App\Domain\Integrations\TwelveData;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PriceObservationService;
use App\Domain\Shared\Money\Currency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as Page;

final class MarketDataController extends Controller
{
    public function index(Request $request, MarketRefresh $refresh): Page
    {
        $owner = $this->owner($request);

        return PortfolioRead::coherent(function () use ($request, $owner, $refresh): Page {
            $query = $request->query('q', '');
            if (! is_string($query) || mb_strlen($query) > 120) {
                LedgerInput::fail('q', 'Use a search up to 120 characters.');
            }
            $prior = ($id = $request->query('edit')) === null ? null : LedgerInput::owned('market_mappings', $owner, LedgerInput::identifier($id));
            $defaults = ['kind' => 'fx', 'base' => 'USD', 'quote' => 'QAR', 'symbol' => '', 'currency' => '', 'mic_code' => '',
                'exchange_timezone' => '', 'portfolio_account_id' => '', 'asset_id' => '', 'context' => '', 'raw_confirmed' => '', 'enabled' => '1', 'supersedes_id' => ''];
            if ($prior !== null) {
                $defaults = [...$defaults, ...json_decode($prior->request_payload, true, flags: JSON_THROW_ON_ERROR),
                    ...json_decode($prior->feed, true, flags: JSON_THROW_ON_ERROR), ...json_decode($prior->target, true, flags: JSON_THROW_ON_ERROR),
                    'supersedes_id' => (string) $prior->id, 'enabled' => '1', 'raw_confirmed' => ''];
                unset($defaults['unit_basis'], $defaults['asset_family']);
            }
            $portfolioId = $request->query('portfolio', $defaults['portfolio_account_id']);
            $portfolio = $portfolioId === '' ? null : app(PortfolioRead::class)->account($owner, LedgerInput::identifier($portfolioId));
            if ($portfolio !== null) {
                $defaults['portfolio_account_id'] = (string) $portfolio->id;
                $defaults['currency'] = $portfolio->currency;
                $defaults['context'] = app(PriceObservationService::class)->context($owner, (string) $portfolio->id);
            }
            $accounts = DB::table('portfolio_accounts as p')->join('accounts as a', 'a.id', '=', 'p.account_id')
                ->where('p.owner_id', $owner->id)->where('a.owner_id', $owner->id)->whereNull('a.archived_at');
            $assets = DB::table('portfolio_assets')->where('owner_id', $owner->id)->where('kind', '<>', 'manual_investment')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('portfolio_assets as n')->whereColumn('n.supersedes_id', 'portfolio_assets.id'));
            if ($portfolio !== null) {
                $assets->where('currency', $portfolio->currency);
            }
            if ($query !== '') {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
                $accounts->where('a.name', 'ilike', $pattern);
                $assets->where('name', 'ilike', $pattern);
            }
            if (($after = $request->query('account_after')) !== null) {
                $accounts->where('p.id', '<', LedgerInput::identifier($after));
            }
            if (($after = $request->query('asset_after')) !== null) {
                $assets->where('id', '<', LedgerInput::identifier($after));
            }
            $accounts = $accounts->orderByDesc('p.id')->limit(21)->get(['p.id', 'a.name', 'p.currency']);
            $assets = $assets->orderByDesc('id')->limit(21)->get(['id', 'name', 'currency']);
            $accountNext = $accounts->count() > 20 ? (string) $accounts[19]->id : null;
            $assetNext = $assets->count() > 20 ? (string) $assets[19]->id : null;
            $accounts = $accounts->take(20);
            $assets = $assets->take(20);
            if ($portfolio !== null && ! $accounts->contains('id', $portfolio->id)) {
                $accounts->push($portfolio);
            }
            if ($defaults['asset_id'] !== '' && ! $assets->contains('id', $defaults['asset_id'])) {
                $assets->push(LedgerInput::owned('portfolio_assets', $owner, $defaults['asset_id']));
            }
            $after = $request->query('after');

            return Inertia::render('market/Index', [...$refresh->status($owner, $after === null ? null : LedgerInput::identifier($after)),
                'mode' => TwelveData::mockEnabled() ? 'mock' : 'disabled', 'defaults' => $defaults, 'query' => $query,
                'accounts' => $accounts->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency])->values()->all(),
                'assets' => $assets->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency])->values()->all(),
                'account_next' => $accountNext, 'asset_next' => $assetNext,
                'currencies' => array_map(fn (Currency $c) => $c->value, Currency::cases())]);
        });
    }

    public function store(Request $request, MarketRefresh $refresh): JsonResponse|RedirectResponse
    {
        $fields = ['kind', 'base', 'quote', 'symbol', 'currency', 'mic_code', 'exchange_timezone', 'portfolio_account_id', 'asset_id', 'context', 'raw_confirmed', 'enabled', 'supersedes_id'];
        $extras = array_diff(array_keys($request->except(['_token'])), [...$fields, 'idempotency_key']);
        if ($extras !== []) {
            throw ValidationException::withMessages(['mapping' => 'Unsupported mapping fields.']);
        }
        $rules = array_fill_keys($fields, ['present', 'nullable', 'string', 'max:128']);
        $rules['idempotency_key'] = ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{8,120}$/D'];
        $data = $request->validate($rules);
        $input = $data['kind'] === 'disable' ? ['enabled' => '0', 'supersedes_id' => $data['supersedes_id'] ?? '']
            : ($data['kind'] === 'fx' ? array_intersect_key($data, array_flip(['kind', 'base', 'quote', 'enabled', 'supersedes_id']))
                : array_intersect_key($data, array_flip(['kind', 'symbol', 'currency', 'mic_code', 'exchange_timezone', 'portfolio_account_id', 'asset_id', 'context', 'raw_confirmed', 'enabled', 'supersedes_id'])));
        $input = array_map(fn ($value) => $value ?? '', $input);
        $row = $refresh->map($this->owner($request), $data['idempotency_key'], $input);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => '/market-data'], 201);
        }

        return redirect('/market-data', 303);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
