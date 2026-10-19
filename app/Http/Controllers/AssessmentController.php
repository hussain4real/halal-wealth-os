<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\LedgerInput;
use App\Domain\Portfolio\PortfolioRead;
use App\Domain\Shariah\AssessmentRead;
use App\Domain\Shariah\AssessmentService;
use App\Http\Requests\AssessmentRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as Page;
use stdClass;

class AssessmentController extends Controller
{
    public function index(Request $request, string $asset): Page
    {
        $after = $request->query('after');
        if ($after !== null) {
            $after = LedgerInput::identifier($after);
        }

        return Inertia::render('shariah/Index', app(AssessmentRead::class)->view($this->owner($request), $asset, $this->cutoff($request), $after));
    }

    public function form(Request $request, string $asset, ?string $assessment = null): Page
    {
        return PortfolioRead::coherent(function () use ($request, $asset, $assessment): Page {
            $owner = $this->owner($request);
            $row = LedgerInput::owned('portfolio_assets', $owner, $asset);
            $prior = $assessment === null ? null : $this->member($owner, $asset, $assessment);
            $latest = $prior === null ? null : DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('series_id', $prior->series_id)->orderByDesc('version')->first(['id']);

            return Inertia::render('shariah/Form', ['asset' => ['id' => $asset, 'name' => $row->name, 'version' => (string) $row->version],
                'prior' => $prior === null ? null : app(AssessmentRead::class)->row($prior, now('UTC')->toDateString()),
                'stale' => $latest !== null && (string) $latest->id !== $assessment,
                'context' => app(AssessmentService::class)->context($owner, $row), 'today' => now('UTC')->toDateString()]);
        });
    }

    public function show(Request $request, string $asset, string $assessment): Page
    {
        $row = $this->member($this->owner($request), $asset, $assessment);
        $latest = DB::table('shariah_assessments')->where('owner_id', $row->owner_id)->where('series_id', $row->series_id)->orderByDesc('version')->first(['id']);

        return Inertia::render('shariah/Record', ['assessment' => app(AssessmentRead::class)->row($row, now('UTC')->toDateString()), 'latest_id' => (string) $latest->id]);
    }

    public function store(AssessmentRequest $request, string $asset): JsonResponse|RedirectResponse
    {
        $row = app(AssessmentService::class)->record($this->owner($request), $asset, $request->string('idempotency_key')->toString(), $request->safe()->except('idempotency_key'));
        $url = '/assets/'.$asset.'/assessments/'.$row->id;

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json(['id' => (string) $row->id, 'reference' => $row->reference, 'url' => $url], 201) : redirect($url, 303);
    }

    public function export(Request $request, string $asset): Response
    {
        return PortfolioRead::coherent(function () use ($request, $asset): Response {
            $owner = $this->owner($request);
            $cutoff = $this->cutoff($request);
            $view = app(AssessmentRead::class)->view($owner, $asset, $cutoff);
            $view['history'] = DB::table('shariah_assessments')->where('owner_id', $owner->id)->where('asset_family', $view['asset']['family_reference'])
                ->where('recorded_at', '<=', $cutoff)->where('effective_on', '<=', substr($cutoff, 0, 10))->orderBy('id')->get()
                ->map(fn ($r): array => app(AssessmentRead::class)->row($r, substr($cutoff, 0, 10)))->all();

            return response(json_encode($view, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200,
                ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="assessment-evidence.json"', 'Cache-Control' => 'private, no-store']);
        });
    }

    private function member(User $owner, string $asset, string $assessment): stdClass
    {
        LedgerInput::owned('portfolio_assets', $owner, $asset);
        $row = LedgerInput::owned('shariah_assessments', $owner, $assessment);
        abort_unless((string) $row->asset_id === $asset, 404);

        return $row;
    }

    private function cutoff(Request $request): string
    {
        $cutoff = $request->query('cutoff', now('UTC')->format('Y-m-d\TH:i:s\Z'));

        return LedgerInput::instant($cutoff, 'cutoff');
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403);

        return $owner;
    }
}
