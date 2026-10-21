<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\InvestmentService;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Http\Requests\InvestmentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use stdClass;

class InvestmentController extends Controller
{
    public function __construct(private readonly PortfolioRead $read, private readonly InvestmentService $service) {}

    public function index(Request $request, string $portfolio): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): Response {
            $owner = $this->owner($request);
            $s = $this->read->state($owner, $portfolio, $this->now());
            $query = DB::table('investment_events')->where('owner_id', $owner->id)->where('portfolio_account_id', $portfolio);
            if ($request->query('after') !== null) {
                $query->where('id', '<', LedgerInput::identifier($request->query('after')));
            }
            $events = $query->orderByDesc('id')->limit(21)->get()->all();
            $more = count($events) > 20;
            $events = array_slice($events, 0, 20);
            $book = $s['investment'];
            unset($book['rows'], $book['events'], $book['source_event_ids'], $book['cash_deficits'], $book['cash_frames']);
            $book['range_issues'] = [];
            foreach (['receivable_minor', 'reserved_minor'] as $field) {
                $name = str_replace('_minor', '', $field);
                $book[$name] = $this->boundedMoney($s['currency'], $book[$field]);
                if ($book[$name] === null) {
                    $book['range_issues'][] = $name.'_out_of_range';
                }
            }
            $free = $s['ledger_cash'] === null ? null : bcsub($s['ledger_cash']['minor'], $book['reserved_minor'], 0);
            $book['available'] = $free === null ? null : $this->money($s['currency'], bccomp($free, '0', 0) < 0 ? '0' : $free);
            $book['earmark_shortfall'] = $free === null ? null : $this->boundedMoney($s['currency'], bccomp($free, '0', 0) < 0 ? bcsub('0', $free, 0) : '0');
            if ($free !== null && $book['earmark_shortfall'] === null) {
                $book['range_issues'][] = 'earmark_shortfall_out_of_range';
            }
            $book['subscription_count'] = count($book['subscriptions']);
            $subscriptions = array_reverse($book['subscriptions']);
            if ($request->query('applications_after') !== null) {
                $cursor = LedgerInput::identifier($request->query('applications_after'));
                $subscriptions = array_values(array_filter($subscriptions, fn (array $row): bool => bccomp($row['id'], $cursor, 0) < 0));
            }
            $book['subscriptions'] = ['items' => array_slice($subscriptions, 0, 20), 'next' => count($subscriptions) > 20 ? $subscriptions[19]['id'] : null];
            foreach ($book['subscriptions']['items'] as &$application) {
                foreach (['planned', 'paid', 'reserved', 'receivable', 'allotted', 'refunded'] as $field) {
                    $application[$field] = $this->money($s['currency'], $application[$field.'_minor']);
                }
            }
            unset($application);

            return Inertia::render('portfolio/Events', ['account' => $this->summary($s), 'book' => $book,
                'events' => ['items' => array_map(fn (stdClass $e): array => $this->event($owner, $e), $events), 'next' => $more ? (string) $events[19]->id : null]]);
        });
    }

    public function create(Request $request, string $portfolio): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): Response {
            $owner = $this->owner($request);
            $s = $this->read->state($owner, $portfolio, $this->now());
            $prior = $request->query('replace') === null ? null : $this->member($owner, $portfolio, LedgerInput::identifier($request->query('replace')));
            $selectedId = $request->query('asset');
            $selected = $prior === null && $selectedId !== null ? LedgerInput::owned('portfolio_assets', $owner, LedgerInput::identifier($selectedId)) : null;
            $assets = DB::table('portfolio_assets as a')->where('a.owner_id', $owner->id)->where('a.currency', $s['currency'])->whereIn('a.kind', ['equity', 'unitized_fund'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('portfolio_assets as n')->whereColumn('n.supersedes_id', 'a.id'));
            $search = $request->query('q', '');
            if (! is_string($search) || mb_strlen($search) > 120) {
                LedgerInput::fail('q', 'Search with at most 120 characters.');
            }
            if ($search !== '') {
                $assets->where('a.name', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
            }
            $choices = $assets->orderByDesc('a.id')->limit(20)->get(['a.id', 'a.name', 'a.kind'])->all();
            if ($prior !== null) {
                $selected = LedgerInput::owned('portfolio_assets', $owner, (string) $prior->asset_id);
            }
            if ($selected !== null && ! in_array($selected->id, array_column($choices, 'id'), true)) {
                $choices[] = $selected;
            }

            return Inertia::render('portfolio/EventForm', ['account' => $this->summary($s), 'choices' => array_map(fn (stdClass $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'kind' => $a->kind], $choices),
                'applications' => array_reverse($s['investment']['subscriptions']), 'prior' => $prior === null ? null : $this->event($owner, $prior),
                'today' => now('UTC')->toDateString(), 'query' => $search, 'selected_asset' => $selected === null ? '' : (string) $selected->id]);
        });
    }

    public function store(InvestmentRequest $request, string $portfolio): JsonResponse|RedirectResponse
    {
        $event = $this->service->record($this->owner($request), $portfolio, $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));

        return $this->saved($request, $portfolio, $event);
    }

    public function show(Request $request, string $portfolio, string $event): Response
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio, $event): Response {
            $owner = $this->owner($request);

            return Inertia::render('portfolio/Event', ['account' => $this->summary($this->read->state($owner, $portfolio, $this->now())),
                'event' => $this->event($owner, $this->member($owner, $portfolio, $event))]);
        });
    }

    public function reverse(InvestmentRequest $request, string $portfolio, string $event): JsonResponse|RedirectResponse
    {
        $row = $this->service->reverse($this->owner($request), $portfolio, $event, $request->string('idempotency_key')->toString(), $request->string('reason')->toString());

        return $this->saved($request, $portfolio, $row);
    }

    public function export(Request $request, string $portfolio): HttpResponse
    {
        return PortfolioRead::coherent(function () use ($request, $portfolio): HttpResponse {
            $owner = $this->owner($request);
            $state = $this->read->state($owner, $portfolio, $this->now());
            unset($state['ledger_evidence']);
            $state['event_history'] = DB::table('investment_events')->where('owner_id', $owner->id)->where('portfolio_account_id', $portfolio)->orderBy('id')->get()
                ->map(fn (stdClass $e): array => $this->event($owner, $e))->all();

            return response(json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="investment-evidence-'.$portfolio.'.json"', 'Cache-Control' => 'private, no-store']);
        });
    }

    /** @return array<string,mixed> */
    private function event(User $owner, stdClass $e): array
    {
        $asset = LedgerInput::owned('portfolio_assets', $owner, (string) $e->asset_id);
        $member = DB::table('investment_event_journals')->where('owner_id', $owner->id)->where('event_id', $e->id)->first();
        $reverse = DB::table('investment_events')->where('owner_id', $owner->id)->where('reverses_id', $e->id)->value('id');
        $replacement = DB::table('investment_events')->where('owner_id', $owner->id)->where('replaces_id', $e->id)->value('id');
        $decision = DB::table('decision_event_links')->where('owner_id', $owner->id)->where('event_id', $e->id)->first();
        $cash = $member === null ? '0' : DB::table('postings')->where('journal_id', $member->journal_id)
            ->where('account_id', DB::table('portfolio_accounts')->where('owner_id', $owner->id)->where('id', $e->portfolio_account_id)->value('account_id'))
            ->selectRaw('coalesce(sum(amount_minor::numeric),0)::text as minor')->first()->minor;

        return ['id' => (string) $e->id, 'reference' => $e->reference, 'kind' => $e->kind, 'effective_date' => $e->effective_date, 'sort_id' => (string) $e->sort_id,
            'asset_id' => (string) $e->asset_id, 'asset_name' => $asset->name, 'asset_version' => (string) $asset->version, 'asset_family' => $e->asset_family, 'currency' => $e->currency,
            'quantity' => $e->quantity === null ? '' : (string) $e->quantity, 'unit_price' => $e->unit_price === null ? '' : (string) $e->unit_price,
            'amount' => $this->money($e->currency, (string) $e->amount_minor), 'fee' => $this->money($e->currency, (string) $e->fee_minor), 'tax' => $this->money($e->currency, (string) $e->tax_minor),
            'split_numerator' => $e->split_numerator === null ? '' : (string) $e->split_numerator, 'split_denominator' => $e->split_denominator === null ? '' : (string) $e->split_denominator,
            'subscription_id' => $e->subscription_id === null ? '' : (string) $e->subscription_id, 'action_reference' => $e->action_reference, 'source' => $e->source, 'reason' => $e->reason,
            'cash_effect' => $this->money($e->currency, $cash), 'journal_id' => $member === null ? null : (string) $member->journal_id, 'reverses_id' => $e->reverses_id === null ? null : (string) $e->reverses_id,
            'replaces_id' => $e->replaces_id === null ? null : (string) $e->replaces_id, 'reversed_by' => $reverse === null ? null : (string) $reverse,
            'replacement_id' => $replacement === null ? null : (string) $replacement,
            'decision' => $decision === null ? null : ['opportunity_id' => (string) $decision->opportunity_id, 'tranche_key' => $decision->tranche_key, 'reason' => $decision->reason]];
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function summary(array $s): array
    {
        $s['position_count'] = count($s['rows']);
        unset($s['rows'], $s['ledger_evidence'], $s['investment']);

        return $s;
    }

    /** @return array<string,string> */
    private function money(string $currency, string $minor): array
    {
        return Money::fromMinor(Currency::from($currency), $minor)->jsonSerialize();
    }

    /** @return array<string,string>|null */
    private function boundedMoney(string $currency, string $minor): ?array
    {
        try {
            return $this->money($currency, $minor);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function member(User $owner, string $portfolio, string $id): stdClass
    {
        $event = LedgerInput::owned('investment_events', $owner, $id);
        abort_unless((string) $event->portfolio_account_id === $portfolio, 404);

        return $event;
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function now(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s\Z');
    }

    private function saved(Request $request, string $portfolio, stdClass $event): JsonResponse|RedirectResponse
    {
        $url = '/portfolio/'.$portfolio.'/events/'.$event->id;

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => (string) $event->id, 'reference' => $event->reference, 'url' => $url], 201) : redirect($url);
    }
}
