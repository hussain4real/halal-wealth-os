<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Planning\BudgetCalendar;
use App\Domain\Planning\BudgetService;
use App\Domain\Planning\BudgetView;
use App\Domain\Planning\PlanningInput;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\BudgetRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $month = PlanningInput::month($request->query('month', substr(BudgetCalendar::today(), 0, 7)));

        return Inertia::render('budgets/Index', ['budget' => app(BudgetView::class)->month($this->owner($request), $month)]);
    }

    public function categoryForm(Request $request, ?string $category = null): Response
    {
        $prior = $category === null ? null : LedgerInput::owned('planning_categories', $this->owner($request), $category);

        return Inertia::render('budgets/Category', ['prior' => $prior === null ? null : app(BudgetView::class)->category($prior)]);
    }

    public function scheduleForm(Request $request, ?string $schedule = null): Response
    {
        $data = app(BudgetView::class)->month($this->owner($request), substr(BudgetCalendar::today(), 0, 7));
        if ($schedule !== null) {
            LedgerInput::owned('planning_schedules', $this->owner($request), $schedule);
        }
        $prior = $schedule === null ? null : collect($data['rules'])->firstWhere('id', $schedule);

        return Inertia::render('budgets/Schedule', ['prior' => $prior, 'categories' => $data['categories'],
            'currencies' => array_map(fn (Currency $currency): string => $currency->value, Currency::cases()), 'today' => BudgetCalendar::today()]);
    }

    public function categoryStore(BudgetRequest $request, ?string $category = null): JsonResponse|RedirectResponse
    {
        $row = app(BudgetService::class)->category($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except(['idempotency_key', 'month']), $category);

        return $this->saved($request, $row);
    }

    public function scheduleStore(BudgetRequest $request, ?string $schedule = null): JsonResponse|RedirectResponse
    {
        $row = app(BudgetService::class)->schedule($this->owner($request), $request->string('idempotency_key')->toString(), $request->safe()->except(['idempotency_key', 'month']), $schedule);

        return $this->saved($request, $row, substr($row->first_due_date, 0, 7));
    }

    public function attribute(BudgetRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(BudgetService::class)->attribute($this->owner($request), $request->string('idempotency_key')->toString(),
            $request->string('journal_id')->toString(), $request->string('category_id')->toString(), $request->string('reason')->toString());

        return $this->saved($request, $row);
    }

    public function allocate(BudgetRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(BudgetService::class)->allocate($this->owner($request), $request->string('idempotency_key')->toString(),
            $request->string('schedule_id')->toString(), $request->string('due_date')->toString(), $request->string('journal_id')->toString(), $request->validated('amount'));

        return $this->saved($request, $row);
    }

    public function release(BudgetRequest $request, string $allocation): JsonResponse|RedirectResponse
    {
        $row = app(BudgetService::class)->release($this->owner($request), $request->string('idempotency_key')->toString(), $allocation, $request->string('reason')->toString());

        return $this->saved($request, $row);
    }

    private function owner(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function saved(Request $request, stdClass $row, ?string $month = null): JsonResponse|RedirectResponse
    {
        $month = PlanningInput::month($request->input('month') ?: $month ?? substr(BudgetCalendar::today(), 0, 7));
        $url = '/budgets?month='.$month;
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Planning record saved. Your ledger is unchanged.']);

        return redirect($url);
    }
}
