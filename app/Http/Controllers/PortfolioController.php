<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Portfolio\PortfolioService;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\PortfolioRequest;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class PortfolioController extends Controller
{
    public function __construct(private readonly PortfolioRead $read, private readonly PortfolioService $service) {}

    public function assets(Request $request): Response
    {
        return Inertia::render('portfolio/Assets', ['choices' => $this->assetChoices($this->owner($request), $request), 'query' => $this->search($request)]);
    }

    public function assetForm(Request $request, ?string $asset = null): Response
    {
        return Inertia::render('portfolio/AssetForm', ['prior' => $asset === null ? null : $this->assetPayload(LedgerInput::owned('portfolio_assets', $this->owner($request), $asset)),
            'currencies' => array_map(fn (Currency $c): string => $c->value, Currency::cases())]);
    }

    public function assetShow(Request $request, string $asset): Response
    {
        $owner = $this->owner($request);
        $row = LedgerInput::owned('portfolio_assets', $owner, $asset);
        $current = DB::table('portfolio_assets')->where('owner_id', $owner->id)->where('family_reference', $row->family_reference)->orderByDesc('version')->first();
        $history = $this->page(DB::table('portfolio_assets')->where('owner_id', $owner->id)->where('family_reference', $row->family_reference), $request->query('after'));

        return Inertia::render('portfolio/Asset', ['asset' => $this->assetPayload($row), 'current_id' => (string) $current->id,
            'history' => ['items' => array_map(fn (stdClass $a): array => $this->assetPayload($a), $history['items']), 'next' => $history['next']]]);
    }

    public function assetStore(PortfolioRequest $request): JsonResponse|RedirectResponse
    {
        $row = $this->service->asset($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->saved($request, $row, '/assets/'.$row->id);
    }

    public function index(Request $request): Response
    {
        return PortfolioRead::coherent(function () use ($request): Response {
            $owner = $this->owner($request);
            $p = $this->page(DB::table('portfolio_accounts')->where('owner_id', $owner->id), $request->query('after'));
            $items = array_map(fn (stdClass $row): array => $this->summary($this->read->state($owner, (string) $row->id, $this->now())), $p['items']);
            $eligible = DB::table('accounts')->where('owner_id', $owner->id)->where('type', 'investment')->whereNull('system_role')->whereNull('archived_at')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('portfolio_accounts as p')->whereColumn('p.account_id', 'accounts.id'));
            if (($search = $this->search($request)) !== '') {
                $eligible->where('name', 'ilike', '%'.$this->escaped($search).'%');
            }
            $available = $this->page($eligible, $request->query('eligible_after'));

            return Inertia::render('portfolio/Index', ['accounts' => ['items' => $items, 'next' => $p['next']], 'eligible' => [
                'items' => array_map(fn (stdClass $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency_code], $available['items']), 'next' => $available['next']], 'query' => $search]);
        });
    }

    public function store(PortfolioRequest $request): JsonResponse|RedirectResponse
    {
        $row = $this->service->link($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->saved($request, $row, '/portfolio/'.$row->id);
    }

    public function show(Request $request, string $portfolio): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): Response {
            $owner = $this->owner($request);
            $state = $this->read->state($owner, $portfolio, $this->now());
            $rows = array_reverse($state['rows']);
            $after = $request->query('positions_after');
            if ($after !== null) {
                $after = LedgerInput::identifier($after);
                $rows = array_values(array_filter($rows, fn (array $row): bool => bccomp($row['id'], $after, 0) < 0));
            }
            $positions = array_slice($rows, 0, 20);
            $modePage = $this->page(DB::table('portfolio_modes')->where('owner_id', $owner->id)->where('portfolio_account_id', $portfolio), $request->query('modes_after'));
            $books = $this->page(DB::table('portfolio_openings')->where('owner_id', $owner->id)->where('portfolio_account_id', $portfolio), $request->query('openings_after'));

            return Inertia::render('portfolio/Show', ['account' => $this->summary($state), 'positions' => ['items' => $positions, 'next' => count($rows) > 20 ? $positions[19]['id'] : null],
                'mode_history' => ['items' => array_map(fn (stdClass $m): array => ['id' => (string) $m->id, 'mode' => $m->mode, 'version' => (string) $m->version,
                    'effective_date' => $m->effective_date, 'platform_label' => $m->platform_label, 'source' => $m->source, 'reason' => $m->reason], $modePage['items']), 'next' => $modePage['next']],
                'opening_history' => ['items' => array_map(fn (stdClass $b): array => ['id' => (string) $b->id, 'version' => (string) $b->version, 'as_of' => $b->as_of,
                    'source' => $b->source, 'reason' => $b->reason, 'row_count' => $b->row_count], $books['items']), 'next' => $books['next']]]);
        });
    }

    public function openingForm(Request $request, string $portfolio): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): Response {
            $owner = $this->owner($request);
            $state = $this->read->state($owner, $portfolio, $this->now());

            return Inertia::render('portfolio/Opening', ['account' => $this->summary($state), 'initial_rows' => $this->read->rows($owner, $this->read->opening($owner, $portfolio)),
                'choices' => $this->assetChoices($owner, $request, $state['currency']), 'query' => $this->search($request), 'today' => now('UTC')->toDateString()]);
        });
    }

    public function openingSave(PortfolioRequest $request, string $portfolio): JsonResponse|RedirectResponse
    {
        $row = $this->service->opening($this->owner($request), $portfolio, $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->saved($request, $row, '/portfolio/'.$portfolio);
    }

    public function modeForm(Request $request, string $portfolio): Response
    {
        return PortfolioRead::coherent(fn (): Response => Inertia::render('portfolio/Mode', ['account' => $this->summary($this->read->state($this->owner($request), $portfolio, $this->now())), 'today' => now('UTC')->toDateString()]));
    }

    public function preview(PortfolioRequest $request, string $portfolio): JsonResponse|RedirectResponse
    {
        $row = $this->service->preview($this->owner($request), $portfolio, $request->safe()->except('idempotency_key'));

        return $this->saved($request, $row, '/portfolio/'.$portfolio.'/previews/'.$row->id);
    }

    public function previewShow(Request $request, string $portfolio, string $preview): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio, $preview): Response {
            $owner = $this->owner($request);
            $row = LedgerInput::owned('portfolio_mode_previews', $owner, $preview);
            abort_unless((string) $row->portfolio_account_id === $portfolio, 404);
            $state = $this->read->state($owner, $portfolio, $this->now());
            $confirmed = DB::table('portfolio_modes')->where('owner_id', $owner->id)->where('preview_id', $row->id)->value('id');

            return Inertia::render('portfolio/Preview', ['account' => $this->summary($state), 'preview' => ['id' => (string) $row->id, 'reference' => $row->reference,
                'expires_at' => LedgerInput::utc($row->expires_at), 'business_date' => $row->business_date, 'payload' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR),
                'evidence' => json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR), 'confirmed_id' => $confirmed === null ? null : (string) $confirmed,
                'stale' => $row->business_date !== now('UTC')->toDateString() || now('UTC')->greaterThanOrEqualTo($row->expires_at) || $state['archived']
                    || $row->baseline_hash !== $this->read->fingerprint($state)]]);
        });
    }

    public function confirm(PortfolioRequest $request, string $portfolio): JsonResponse|RedirectResponse
    {
        $row = $this->service->confirm($this->owner($request), $portfolio, $request->string('idempotency_key')->toString(), $request->string('preview_id')->toString(), $request->string('acknowledge')->toString());

        return $this->saved($request, $row, '/portfolio/'.$portfolio);
    }

    public function export(Request $request, string $portfolio): HttpResponse
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): HttpResponse {
            $state = $this->read->state($this->owner($request), $portfolio, $this->now());
            unset($state['ledger_evidence']);

            return response(json_encode(['format' => 'portfolio-opening-v1', 'scope' => 'owner-private-manual-evidence;not-broker-or-compliance-verification', 'account' => $state], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200,
                ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="portfolio-'.$portfolio.'.json"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** @return array<string,mixed> */
    private function assetChoices(User $owner, Request $request, ?string $currency = null): array
    {
        $query = DB::table('portfolio_assets')->where('owner_id', $owner->id)->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('portfolio_assets as next')->whereColumn('next.supersedes_id', 'portfolio_assets.id'));
        if ($currency !== null) {
            $query->where('currency', $currency);
        }
        if (($search = $this->search($request)) !== '') {
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', '%'.$this->escaped($search).'%')->orWhere('identifier', 'ilike', '%'.$this->escaped($search).'%'));
        }
        $p = $this->page($query, $request->query('after'));

        return ['items' => array_map(fn (stdClass $a): array => $this->assetPayload($a), $p['items']), 'next' => $p['next']];
    }

    /** @return array{items:list<stdClass>,next:?string} */
    private function page(Builder $q, mixed $after): array
    {
        if ($after !== null && $after !== '') {
            $q->where('id', '<', LedgerInput::identifier($after));
        }
        $items = $q->orderByDesc('id')->limit(21)->get()->all();
        $next = count($items) > 20 ? (string) $items[19]->id : null;

        return ['items' => array_slice($items, 0, 20), 'next' => $next];
    }

    /** @return array<string,mixed> */
    private function assetPayload(stdClass $a): array
    {
        return ['id' => (string) $a->id, 'family' => $a->family_reference, 'version' => (string) $a->version, 'prior_id' => $a->supersedes_id === null ? '' : (string) $a->supersedes_id,
            'geography' => $a->geography, 'geography_source' => $a->geography_source, 'name' => $a->name, 'kind' => $a->kind, 'currency' => $a->currency, 'identifier' => $a->identifier, 'market' => $a->market, 'source' => $a->source, 'unsupported_terms' => $a->unsupported_terms, 'reason' => $a->reason];
    }

    /** @param array<string,mixed> $state
     * @return array<string,mixed> */
    private function summary(array $state): array
    {
        $state['position_count'] = count($state['rows']);
        unset($state['rows'], $state['ledger_evidence'], $state['investment']);

        return $state;
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function search(Request $request): string
    {
        $q = $request->query('q', '');
        if (! is_string($q) || mb_strlen($q) > 120) {
            LedgerInput::fail('q', 'Use a name or identifier up to 120 characters.');
        }

        return trim($q);
    }

    private function escaped(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function now(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s\Z');
    }

    private function saved(Request $request, stdClass $row, string $url): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your manual evidence is saved.']);

        return redirect($url);
    }
}
