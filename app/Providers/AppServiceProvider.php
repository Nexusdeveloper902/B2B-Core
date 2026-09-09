<?php

namespace App\Providers;

use App\Contracts\MaterialClassifier;
use App\Services\NlQuery\DeepSeekClient;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\NlQuery\NlQueryService;
use App\Services\PairingService;
use App\Services\Recycling\ClassifierFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // MaterialClassifier contract -> configured driver (stub | local |
        // deepseek). Swapping classifiers is a .env change, never a code
        // change (ADR-003/ADR-007). Tests swap this binding with a fake.
        $this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make());

        // NL-query wiring (Phase E). DeepSeek deepseek-v4-flash per
        // ADR-030 (OpenAI-compatible Chat Completions + tool calls);
        // the live call is entirely skipped when no API key is
        // configured (ADR-005).
        $this->app->singleton(DeepSeekClient::class, function () {
            return new DeepSeekClient(
                config('recycling.nl_query.api_key'),
                (string) config('recycling.nl_query.model', 'deepseek-v4-flash'),
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
        // code: ADR-020 documents the 45 s default choice.
        $this->app->singleton(PairingService::class, function () {
            return new PairingService(
                (int) config('presence.pairing_window_seconds', 45),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
