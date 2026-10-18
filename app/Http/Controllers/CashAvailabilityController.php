<?php

namespace App\Http\Controllers;

use App\Domain\Planning\AvailabilitySettings;
use App\Domain\Planning\CashAvailability;
use App\Domain\Planning\PlanningInput;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\CashAvailabilityRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashAvailabilityController extends Controller
{
    public function dashboard(Request $request): Response
    {
        return Inertia::render('Dashboard', ['cash' => app(CashAvailability::class)->capture($this->owner($request))]);
    }

    public function settings(Request $request): Response
    {
        $owner = $this->owner($request);
        $cash = app(CashAvailability::class)->capture($owner, PlanningInput::cursor($request->input('plan_after')));
        $goalAfter = PlanningInput::cursor($request->input('goal_after'));
        $reservationAfter = PlanningInput::cursor($request->input('reservation_after'));
        $goals = DB::table('goal_plans as root')->join('goal_plans as g', function ($q): void {
            $q->on('g.family_reference', '=', 'root.family_reference')->on('g.owner_id', '=', 'root.owner_id');
        })->where('root.owner_id', $owner->id)->whereNull('root.supersedes_id')->where('root.mode', 'reserved')->when($goalAfter, fn ($q) => $q->where('root.id', '>', $goalAfter))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_plans as n')->whereColumn('n.supersedes_id', 'g.id'))
            ->selectRaw('root.id::text AS id,g.name,root.currency,g.closed')->orderBy('root.id')->limit(21)->get()->all();
        $goalNext = count($goals) > 20 ? (string) $goals[19]->id : null;
        $goals = array_slice($goals, 0, 20);
        $reservations = DB::table('goal_reservations as r')->join('goal_plans as g', 'g.id', '=', 'r.goal_id')->where('r.owner_id', $owner->id)->when($reservationAfter, fn ($q) => $q->where('r.id', '>', $reservationAfter))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('goal_releases as rel')->whereColumn('rel.reservation_id', 'r.id'))
            ->selectRaw('r.id::text AS id,r.goal_id::text AS goal_id,r.reference,g.name,g.currency,r.amount_minor::text AS minor')->orderBy('r.id')->limit(21)->get()->all();
        $reservationNext = count($reservations) > 20 ? (string) $reservations[19]->id : null;
        $reservations = array_slice($reservations, 0, 20);

        $historyBefore = PlanningInput::cursor($request->input('settings_before'));
        $history = DB::table('cash_availability_settings')->where('owner_id', $owner->id)->when($historyBefore, fn ($q) => $q->where('id', '<', $historyBefore))->orderByDesc('id')->limit(21)->get(['id', 'version', 'currency', 'reason', 'created_at'])->all();
        $historyNext = count($history) > 20 ? (string) $history[19]->id : null;
        $history = array_slice($history, 0, 20);

        return Inertia::render('cash/Settings', ['cash' => $cash, 'goals' => $goals, 'reservations' => $reservations, 'goal_next_cursor' => $goalNext, 'reservation_next_cursor' => $reservationNext, 'settings_history' => $history, 'settings_history_next' => $historyNext,
            'currencies' => array_map(fn (Currency $c): string => $c->value, Currency::cases())]);
    }

    public function save(CashAvailabilityRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(AvailabilitySettings::class)->save($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except(['idempotency_key']));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'version' => (string) $row->version, 'url' => '/dashboard'], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings version '.$row->version.' is retained. Availability uses the latest saved version.']);

        return redirect('/dashboard');
    }

    public function scenarios(Request $request): Response
    {
        return Inertia::render('cash/Scenarios', ['cash' => app(CashAvailability::class)->capture($this->owner($request))]);
    }

    public function calculate(CashAvailabilityRequest $request): JsonResponse
    {
        return response()->json(app(CashAvailability::class)->scenario($this->owner($request), $request->string('fingerprint')->toString(), $request->validated('overrides')));
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
