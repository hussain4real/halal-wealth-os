<?php

namespace App\Providers;

use App\Domain\Imports\StatementCorrectionHistory;
use App\Domain\Integrations\FxProvider;
use App\Domain\Integrations\PriceProvider;
use App\Domain\Integrations\TwelveData;
use App\Domain\Intelligence\ToolProvider;
use App\Domain\Intelligence\UnavailableProvider;
use App\Domain\Ledger\AccountBalanceReader;
use App\Domain\Ledger\CorrectionHistory;
use App\Domain\Ledger\DeferredOpeningBalances;
use App\Domain\Ledger\OpeningBalanceGateway;
use App\Domain\Ledger\PostedAccountBalances;
use App\Models\Account;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ToolProvider::class, UnavailableProvider::class);
        $this->app->bind(PriceProvider::class, TwelveData::class);
        $this->app->bind(FxProvider::class, TwelveData::class);
        $this->app->bind(CorrectionHistory::class, StatementCorrectionHistory::class);
        $this->app->bind(AccountBalanceReader::class, PostedAccountBalances::class);
        $this->app->bind(OpeningBalanceGateway::class, DeferredOpeningBalances::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        RateLimiter::for('copilot', fn (Request $request) => Limit::perMinute(20)->by('copilot:'.($request->user()->id ?? $request->ip())));
        RateLimiter::for('drive-export', fn (Request $request) => Limit::perMinute(20)->by('drive:'.($request->user()->id ?? $request->ip())));
        RateLimiter::for('market-data', fn (Request $request) => Limit::perMinute(20)->by('market:'.($request->user()->id ?? $request->ip())));
        RateLimiter::for('statement-import', fn (Request $request) => Limit::perMinute(20)->by('statement:'.($request->user()->id ?? $request->ip())));
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::define('access-admin', fn (User $user): bool => $user->is_admin && $user->hasVerifiedEmail()
            && (! app()->isProduction() || $user->hasEnabledTwoFactorAuthentication()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
