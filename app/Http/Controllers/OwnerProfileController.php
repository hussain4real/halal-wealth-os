<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class OwnerProfileController extends Controller
{
    public function show(Request $request, string $owner): Response
    {
        $record = User::query()->ownedBy($request->user())->findOrFail($owner);
        Gate::authorize('view', $record);

        return app(ProfileController::class)->edit($request);
    }

    public function update(ProfileUpdateRequest $request, string $owner): RedirectResponse
    {
        $record = User::query()->ownedBy($request->user())->findOrFail($owner);
        Gate::authorize('update', $record);

        return app(ProfileController::class)->update($request);
    }
}
