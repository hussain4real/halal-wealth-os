<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\SecurityAudit;
use App\Domain\Identity\SecurityEvent;
use App\Domain\Ledger\LedgerInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Account;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        Gate::authorize('view', $request->user());

        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();
        app(SecurityAudit::class)->record(SecurityEvent::ProfileUpdated, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            LedgerInput::lock($user);
            if (Account::query()->ownedBy($user)->exists() || DB::table('planning_categories')->where('owner_id', $user->id)->exists() || DB::table('cash_availability_settings')->where('owner_id', $user->id)->exists() || DB::table('portfolio_assets')->where('owner_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['password' => 'Your financial accounts and planning history must be retained. This profile cannot be deleted.']);
            }
            // Logout rotates remember-token state while the model still exists.
            // Saving it after deletion would recreate an otherwise empty profile.
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
