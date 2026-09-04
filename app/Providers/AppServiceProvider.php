<?php

namespace App\Providers;

use App\Contracts\MaterialClassifier;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\NlQuery\GeminiClient;
use App\Services\NlQuery\NlQueryService;
use App\Services\PairingService;
use App\Services\Recycling\ClassifierFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // MaterialClassifier contract -> configured driver (stub | local |
        // gemini). Swapping classifiers is a .env change, never a code
        // change (ADR-003/ADR-007). Tests swap this binding with a fake.
        $this->app->bind(MaterialClassifier::class, fn () => ClassifierFactory::make());

        // NL-query wiring (Phase E). Gemini flash-family models only, per the
        // free-tier constraint; the live call is entirely skipped when no
        // API key is configured (ADR-005/ADR-006).
        $this->app->singleton(GeminiClient::class, function () {
            return new GeminiClient(
                config('recycling.nl_query.api_key'),
                (string) config('recycling.nl_query.model', 'gemini-3.1-flash-lite'),
                (float) config('recycling.nl_query.timeout', 20),
            );
        });

        $this->app->singleton(NlQueryService::class, function ($app) {
            return new NlQueryService(
                $app->make(GeminiClient::class),
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
        //
    }
}
