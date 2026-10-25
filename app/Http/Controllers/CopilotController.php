<?php

namespace App\Http\Controllers;

use App\Domain\Intelligence\Answers\AnswerPlanner;
use App\Domain\Intelligence\CopilotBoundary;
use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\AnswerSources;
use App\Domain\Reporting\Copilot\Answers\AnswerContext;
use App\Domain\Reporting\Copilot\ToolQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class CopilotController extends Controller
{
    public function index(CopilotBoundary $boundary): Response
    {
        return Inertia::render('copilot/Index', ['boundary' => $boundary->page()]);
    }

    public function consent(Request $request, CopilotBoundary $boundary): JsonResponse
    {
        $this->only($request, ['enabled', 'generation']);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'generation' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D']]);
        $boundary->consent((bool) $data['enabled'], $data['generation']);

        return response()->json(['status' => 'saved']);
    }

    public function preview(Request $request, CopilotBoundary $boundary): JsonResponse
    {
        $this->only($request, ['reference', 'tool', 'arguments']);
        $data = $request->validate(['reference' => ['required', 'string', 'uuid'], 'tool' => ['required', 'string'], 'arguments' => ['present', 'array']]);
        $query = ToolQuery::parse(['tool' => $data['tool'], 'arguments' => $data['arguments']]);

        return response()->json($boundary->preview($data['reference'], $query))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $request, CopilotBoundary $boundary): JsonResponse
    {
        $this->only($request, ['reference', 'question', 'document']);
        $data = $request->validate(['reference' => ['required', 'string', 'uuid'], 'question' => ['required', 'string'], 'document' => ['present', 'nullable', 'string']]);

        return response()->json($boundary->propose($data['reference'], UntrustedInput::parse($data['question'], $data['document'] ?? '')))->header('Cache-Control', 'private, no-store');
    }

    public function chat(Request $request, CopilotBoundary $boundary): Response
    {
        return Inertia::render('copilot/Chat', ['context_choices' => app(AnswerSources::class)->choices($request->user()), 'boundary' => $boundary->page(), 'answer_available' => app(AnswerPlanner::class)->available(), 'model' => config('copilot.answer_model'), 'context_defaults' => ['month' => now('Asia/Qatar')->format('Y-m'), 'cutoff' => now('UTC')->format('Y-m-d\TH:i:s\Z')]]);
    }

    public function answer(Request $request, CopilotBoundary $boundary): JsonResponse
    {
        $this->only($request, ['reference', 'question', 'document', 'context', 'disclosure_confirmed']);
        $v = $request->validate(['reference' => ['required', 'string', 'uuid'], 'question' => ['required', 'string'], 'document' => ['present', 'nullable', 'string'], 'context' => ['required', 'array'], 'disclosure_confirmed' => ['required', 'accepted']]);

        return response()->json($boundary->answer($v['reference'], UntrustedInput::parse($v['question'], $v['document'] ?? ''), AnswerContext::parse($v['context']), $request->boolean('disclosure_confirmed')))->header('Cache-Control', 'private, no-store');
    }

    public function source(Request $request, string $kind, string $id): JsonResponse
    {
        return response()->json(app(AnswerSources::class)->resolve($request->user(), $kind, $id))->header('Cache-Control', 'private, no-store');
    }

    /** @param list<string> $keys */
    private function only(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), [...$keys, '_token']) !== []) {
            throw ValidationException::withMessages(['request' => 'Additional request fields are forbidden.']);
        }
    }
}
