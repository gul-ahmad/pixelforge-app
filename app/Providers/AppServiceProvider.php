<?php

namespace App\Providers;

use App\Models\User;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Operators only: what every user was charged, our provider cost and profit.
        Gate::define('view-admin-report', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Named limiters, each with its own key. (Plain "throttle:10,1" keys only by user, so every
     * throttled route shares one counter: live estimates while typing used up the submit limit.)
     */
    protected function configureRateLimiting(): void
    {
        // Each submit can cost money and uses the account's shared 60 requests/minute at Pixelforge.
        RateLimiter::for('generation-submits', fn (Request $request) => Limit::perMinute(10)
            ->by('submit:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn () => back()->withErrors([
                'submit' => "You're submitting too quickly. Please wait a minute and try again.",
            ])));

        // Fake purchases: generous but bounded.
        RateLimiter::for('credit-purchases', fn (Request $request) => Limit::perMinute(10)
            ->by('credits:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            ->response(fn () => back()->withErrors([
                'pack_micro_usd' => 'Too many purchases in a minute. Please wait a moment.',
            ])));

        // The live estimate fires while typing; cheap (cached pricing, no Pixelforge call).
        RateLimiter::for('generation-estimates', fn (Request $request) => Limit::perMinute(120)
            ->by('estimate:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
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
