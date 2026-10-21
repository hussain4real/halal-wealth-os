<?php

namespace App\Http\Controllers;

use App\Domain\Decisions\DecisionRead;
use App\Domain\Decisions\DecisionService;
use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\PlanningInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

final class DecisionController extends Controller
{
    public function index(Request $request): Page
    {
        $status = $request->query('status', '');
        abort_unless(is_string($status) && in_array($status, ['', 'watchlist', 'planned', 'closed'], true), 422);

        return Inertia::render('opportunities/Index', app(DecisionRead::class)->index($this->owner($request), PlanningInput::cursor($request->query('before')), $status));
    }

    public function form(Request $request, ?string $opportunity = null): Page
    {
        return PortfolioRead::coherent(function () use ($request, $opportunity): Page {
            $owner = $this->owner($request);
            $prior = $opportunity === null ? null : LedgerInput::owned('decision_opportunities', $owner, $opportunity);
            $assets = DB::table('portfolio_assets')->where('owner_id', $owner->id)->orderByDesc('id')->limit(100)->get(['id', 'name', 'currency', 'version']);

            return Inertia::render('opportunities/Form', ['prior' => $prior === null ? null : app(DecisionRead::class)->row($prior), 'draft' => $prior === null ? null : app(DecisionRead::class)->draft($prior),
                'stale' => $prior !== null && ($prior->status === 'closed' || DB::table('decision_opportunities')->where('supersedes_id', $prior->id)->exists()),
                'assets' => $assets->map(fn (stdClass $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'currency' => $a->currency, 'version' => (string) $a->version])->all(), 'today' => now('Asia/Qatar')->toDateString()]);
        });
    }

    public function show(Request $request, string $opportunity): Page
    {
        $owner = $this->owner($request);

        return PortfolioRead::coherent(function () use ($request, $owner, $opportunity): Page {
            $view = app(DecisionRead::class)->detail($owner, $opportunity, PlanningInput::cursor($request->query('history_before')), PlanningInput::cursor($request->query('reservations_before')), PlanningInput::cursor($request->query('links_before')));
            $goals = DB::table('goal_plans as g')->where('g.owner_id', $owner->id)->where('g.mode', 'reserved')->where('g.closed', false)->where('g.currency', $view['opportunity']['currency'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_plans as n')->whereColumn('n.supersedes_id', 'g.id'))->orderByDesc('g.id')->limit(100)->get(['g.id', 'g.name', 'g.account_id']);

            return Inertia::render('opportunities/Show', [...$view, 'goals' => $goals->map(fn (stdClass $g): array => ['id' => (string) $g->id, 'name' => $g->name, 'account_id' => (string) $g->account_id])->all()]);
        });
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $row = app(DecisionService::class)->plan($this->owner($request), $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $row, (string) $row->id);
    }

    public function reserve(Request $request): JsonResponse|RedirectResponse
    {
        $r = app(DecisionService::class)->reserve($this->owner($request), $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $r, (string) $r->opportunity_id);
    }

    public function link(Request $request): JsonResponse|RedirectResponse
    {
        $r = app(DecisionService::class)->link($this->owner($request), $this->key($request), $request->except('idempotency_key'));

        return $this->receipt($request, $r, (string) $r->opportunity_id);
    }

    public function release(Request $request, string $reservation): JsonResponse|RedirectResponse
    {
        $owner = $this->owner($request);
        $r = app(DecisionService::class)->release($owner, $this->key($request), $reservation, $request->except('idempotency_key'));
        $link = LedgerInput::owned('decision_reservations', $owner, $reservation);

        return $this->receipt($request, $r, (string) $link->opportunity_id);
    }

    public function export(Request $request): Response
    {
        return response(json_encode(app(DecisionRead::class)->export($this->owner($request)), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), 200,
            ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="opportunities.json"', 'Cache-Control' => 'no-store, private']);
    }

    private function key(Request $request): string
    {
        $key = $request->input('idempotency_key');
        if (! is_string($key)) {
            LedgerInput::fail('idempotency_key', 'Use a valid request identity.');
        }

        return LedgerInput::key($key);
    }

    private function receipt(Request $request, stdClass $r, string $id): JsonResponse|RedirectResponse
    {
        $url = '/opportunities/'.$id;

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => (string) $r->id, 'reference' => $r->reference, 'url' => $url], 201) : redirect($url, 303);
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
