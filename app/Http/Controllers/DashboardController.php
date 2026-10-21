<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioInput;
use App\Domain\Reporting\DashboardRules;
use App\Domain\Reporting\DashboardSnapshot;
use App\Domain\Shared\Money\Currency;
use App\Http\Requests\DashboardRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

class DashboardController extends Controller
{
    public function index(Request $request): Page
    {
        $owner = $this->owner($request);
        $base = PortfolioInput::currency($request->query('base_currency', 'QAR'));
        $evidence = app(DashboardSnapshot::class)->current($owner, $base);

        return $this->page($owner, $evidence);
    }

    public function store(DashboardRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(DashboardSnapshot::class)->save($this->owner($request), $request->string('idempotency_key')->toString(), $this->payload($request));

        return $this->saved($request, $row, '/dashboard/snapshots/'.$row->id);
    }

    public function show(Request $request, string $snapshot): Page
    {
        $owner = $this->owner($request);
        $row = LedgerInput::owned('dashboard_snapshots', $owner, $snapshot);

        return $this->page($owner, app(DashboardSnapshot::class)->open($owner, $snapshot), ['id' => $snapshot, 'reference' => $row->reference, 'hash' => $row->evidence_hash]);
    }

    public function export(Request $request, string $snapshot): Response
    {
        return response(json_encode(app(DashboardSnapshot::class)->open($this->owner($request), $snapshot), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200,
            ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="dashboard-snapshot.json"', 'Cache-Control' => 'private, no-store']);
    }

    public function rules(Request $request): Page
    {
        return Inertia::render('dashboard/Rules', ['settings' => app(DashboardSnapshot::class)->settings($this->owner($request)), 'rules' => DashboardRules::RULES, 'currencies' => array_map(fn (Currency $c): string => $c->value, Currency::cases())]);
    }

    public function configure(DashboardRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(DashboardSnapshot::class)->configure($this->owner($request), $request->string('idempotency_key')->toString(), $this->payload($request));

        return $this->saved($request, $row, '/dashboard/rules');
    }

    public function dismiss(DashboardRequest $request): JsonResponse|RedirectResponse
    {
        $row = app(DashboardSnapshot::class)->dismiss($this->owner($request), $request->string('idempotency_key')->toString(), $this->payload($request));

        return $this->saved($request, $row, '/dashboard?base_currency='.rawurlencode($request->string('base_currency')->toString()));
    }

    /** @param array<string,mixed> $evidence
     * @param array<string,string>|null $snapshot */
    private function page(User $owner, array $evidence, ?array $snapshot = null): Page
    {
        $facts = $evidence['facts'];
        $runs = DB::table('dashboard_snapshots')->where('owner_id', $owner->id)->orderByDesc('id')->limit(20)->get(['id', 'reference', 'recorded_at']);

        return Inertia::render('Dashboard', ['cash' => $facts['cash'], 'dashboard' => ['cutoff' => $facts['cutoff'], 'planning_date' => $facts['planning_date'], 'base_currency' => $facts['base_currency'],
            'time_policy' => $facts['time_policy'], 'policy' => $evidence['policy'], 'widgets' => $facts['widgets'], 'sources' => $facts['sources'], 'allocation' => $facts['valuation']['ending']['allocation'],
            'reconciliations' => $facts['reconciliations'], 'settings' => $evidence['settings'], 'actions' => $evidence['actions']], 'snapshot' => $snapshot, 'current_settings_id' => app(DashboardSnapshot::class)->settings($owner)['id'],
            'snapshots' => $runs->map(fn (stdClass $r): array => ['id' => (string) $r->id, 'reference' => $r->reference, 'recorded_at' => LedgerInput::utc($r->recorded_at)])->all(),
            'currencies' => array_map(fn (Currency $c): string => $c->value, Currency::cases())]);
    }

    /** @return array<string,string> */
    private function payload(DashboardRequest $request): array
    {
        return array_map(fn ($value): string => $value ?? '', $request->safe()->except('idempotency_key'));
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
