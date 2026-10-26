<?php

namespace App\Http\Controllers;

use App\Domain\Operations\Channel;
use App\Domain\Operations\OperationBlocked;
use App\Domain\Operations\Operations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OperationsController extends Controller
{
    public function index(Request $request, Operations $operations): Response
    {
        $response = Inertia::render('operations/Index', ['operations' => $operations->page($request->user())])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function configure(Request $request, string $channel, Operations $operations): RedirectResponse
    {
        $selected = Channel::tryFrom($channel);
        abort_if($selected === null, 404);
        if (array_diff(array_keys($request->all()), ['generation', 'paused', 'requests_daily', 'units_daily', 'retention_days', '_token']) !== []) {
            throw ValidationException::withMessages(['operations' => 'Additional request fields are forbidden.']);
        }
        $data = $request->validate(['generation' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D'],
            'paused' => ['required', 'boolean'], 'requests_daily' => ['required', 'integer'],
            'units_daily' => ['required', 'integer'], 'retention_days' => ['required', 'integer']]);
        $generation = $data['generation'];
        unset($data['generation']);
        try {
            $operations->configure($request->user(), $selected, $generation, $data);
        } catch (OperationBlocked $error) {
            if ($request->expectsJson()) {
                throw $error;
            }
            throw ValidationException::withMessages(['generation' => 'Retained export capacity reached. Settings were not saved; pause-all remains available.']);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409 || $request->expectsJson()) {
                throw $error;
            }
            throw ValidationException::withMessages(['generation' => 'Settings changed. Refresh before saving or explicitly resuming.']);
        }

        return to_route('operations.index', status: 303);
    }

    public function pause(Request $request, Operations $operations): RedirectResponse
    {
        if (array_diff(array_keys($request->all()), ['_token']) !== []) {
            throw ValidationException::withMessages(['operations' => 'Pause accepts no owner or settings fields.']);
        }
        $operations->pauseAll($request->user());

        return to_route('operations.index', status: 303);
    }
}
