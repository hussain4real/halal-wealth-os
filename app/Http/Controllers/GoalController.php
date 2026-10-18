<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\BudgetCalendar;
use App\Domain\Planning\GoalService;
use App\Domain\Planning\GoalView;
use App\Domain\Planning\PlanningInput;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\GoalRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class GoalController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('goals/Index', app(GoalView::class)->index($this->owner($request), $this->month($request), PlanningInput::cursor($request->input('before'))));
    }

    public function show(Request $request, string $goal): Response
    {
        return Inertia::render('goals/Show', app(GoalView::class)->show($this->owner($request), $goal, $this->month($request), PlanningInput::cursor($request->input('before')), PlanningInput::cursor($request->input('reservation_before')), PlanningInput::cursor($request->input('version_before'))));
    }

    public function form(Request $request, ?string $goal = null): Response
    {
        $prior = $goal === null ? null : app(GoalView::class)->show($this->owner($request), $goal, $this->month($request))['goal'];

        return Inertia::render('goals/Form', ['prior' => $prior, 'operation_goal' => $goal, 'accounts' => app(GoalView::class)->accounts($this->owner($request), PlanningInput::search($request->input('q')), PlanningInput::cursor($request->input('before'))),
            'q' => PlanningInput::search($request->input('q')), 'currencies' => array_map(fn ($c): string => $c->value, Currency::cases())]);
    }

    public function store(GoalRequest $request, ?string $goal = null): JsonResponse|RedirectResponse
    {
        $row = app(GoalService::class)->plan($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except(['idempotency_key']), $goal);

        return $this->saved($request, $row, (string) $row->id);
    }

    public function reserve(GoalRequest $request, string $goal): JsonResponse|RedirectResponse
    {
        $row = app(GoalService::class)->reserve($this->owner($request), $request->string('idempotency_key')->toString(), $goal, $request->validated('amount'), $request->string('reason')->toString());

        return $this->saved($request, $row, $goal);
    }

    public function release(GoalRequest $request, string $reservation): JsonResponse|RedirectResponse
    {
        $row = app(GoalService::class)->release($this->owner($request), $request->string('idempotency_key')->toString(), $reservation, $request->string('reason')->toString());
        $reservationRow = LedgerInput::owned('goal_reservations', $this->owner($request), $reservation);

        return $this->saved($request, $row, (string) $reservationRow->goal_id);
    }

    public function link(GoalRequest $request, string $goal): JsonResponse|RedirectResponse
    {
        $row = app(GoalService::class)->link($this->owner($request), $request->string('idempotency_key')->toString(), $goal, $request->string('posting_id')->toString(), $request->string('reason')->toString());

        return $this->saved($request, $row, $goal);
    }

    private function month(Request $request): string
    {
        return PlanningInput::month($request->input('month') ?: substr(BudgetCalendar::today(), 0, 7));
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }

    private function saved(GoalRequest $request, stdClass $row, string $goal): JsonResponse|RedirectResponse
    {
        $url = '/goals/'.$goal;
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Goal record saved. Your ledger is unchanged.']);

        return redirect($url);
    }
}
