<?php

namespace App\Providers;

use App\Contracts\MaterialClassifier;
use App\Services\NlQuery\DeepSeekClient;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\NlQuery\NlQueryService;
use App\Services\PairingService;
use App\Services\Recycling\ClassifierFactory;
use App\Support\Branding\BrandResolver;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TASK-045 (ADR-064) — "which organization is this request acting
        // for?" is resolved ONCE per request and consulted by every
        // organization scope and every organization-owned insert.
        $this->app->singleton(CurrentSchool::class, fn () => new CurrentSchool);

        // TASK-045 (ADR-065) — account -> school -> branding, memoized
        // for the request (the shell asks for it on every view render).
        $this->app->singleton(BrandResolver::class, fn () => new BrandResolver);

        // MaterialClassifier contract -> configured driver (stub | local |
        // deepseek). Swapping classifiers is a .env change, never a code
        // change (ADR-003/ADR-007). Tests swap this binding with a fake.
        $this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make());

        // NL-query wiring (Phase E). DeepSeek deepseek-flash per
        // ADR-046 (OpenAI-compatible Chat Completions + tool calls);
        // the live call is entirely skipped when no API key is
        // configured (ADR-005).
        $this->app->singleton(DeepSeekClient::class, function () {
            return new DeepSeekClient(
                config('recycling.nl_query.api_key'),
                (string) config('recycling.nl_query.model', 'deepseek-flash'),
                (float) config('recycling.nl_query.timeout', 20),
            );
        });

        $this->app->singleton(NlQueryService::class, function ($app) {
            return new NlQueryService(
                $app->make(DeepSeekClient::class),
                $app->make(FunctionRegistry::class),
            );
        });

        // TASK-010 — card pairing window (seconds) is configuration, not
        // code: ADR-020 documents the 45 s default choice. TASK-037: the
        // effective value is runtime-configurable through /admin settings
        // (ADR-055); the service resolves it per arm call.
        $this->app->singleton(PairingService::class, fn () => new PairingService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TASK-045 (ADR-065) — every view receives the resolved brand, so
        // no template ever asks which school it is rendering for. The
        // resolver memoizes, so this costs one lookup per request even
        // though partials re-enter it.
        View::composer('*', function ($view): void {
            $view->with('brand', app(BrandResolver::class)->current());
        });

        // Brute-force guard on the session login: 6 attempts per minute
        // per IP (shared staff-room NATs stay comfortably under it; a
        // credential-stuffing burst does not).
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip()),
        ]);

        // Cost guard on the LLM endpoint (every forwarded question burns
        // DeepSeek balance): per-user, not per-IP — staff share NATs, so
        // an IP limit would let one desk starve another.
        RateLimiter::for('nl-query', function (Request $request) {
            return [
                Limit::perMinute(20)->by('nlq:'.$request->user()?->id ?: $request->ip()),
            ];
        });
    }
}
