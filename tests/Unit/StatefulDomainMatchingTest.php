<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-012 — stateful-domain matching for dashboard session auth.
 *
 * The dashboard (pairing desk) is opened on more than localhost: the
 * owner's bench uses a phone over the classroom LAN
 * (http://192.168.1.6:8000). Sanctum only authenticates /api/* requests
 * through the session when the request's Referer/Origin host matches
 * sanctum.stateful — and until TASK-012 that list was localhost-only,
 * so a phone that logged in fine (web routes) got 401 "Unauthenticated"
 * on its very next arm-pairing fetch.
 *
 * The matching rule is pinned directly via fromFrontend() because a
 * full-stack session test cannot produce the differential honestly:
 * within one test the auth guard and session store are shared across
 * requests, which keeps the user authenticated regardless of this
 * middleware (a testing pitfall recorded in RUN-2026-09-05-core-010).
 *
 * Default list (TASK-012): localhost/127.0.0.1/::1 + APP_URL + the host
 * actually serving the request. Device endpoints are unaffected: readers
 * send no Referer/Origin, so they stay purely stateless Bearer flows.
 */
class StatefulDomainMatchingTest extends TestCase
{
    /**
     * The exact request a phone's browser makes when the pairing desk's
     * JS calls the status endpoint from http://192.168.1.6:8000.
     */
    private function fromPhone(string $referer = 'http://192.168.1.6:8000/admin/pairing'): bool
    {
        $request = Request::create('http://192.168.1.6:8000/api/v1/admin/pairing/status');
        $request->headers->set('Referer', $referer);

        return EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    #[Test]
    public function a_lan_host_serving_the_request_is_stateful(): void
    {
        // TASK-012: the request's own host joins the stateful list, so the
        // phone's same-origin dashboard fetch authenticates via session.
        $this->assertTrue($this->fromPhone());
    }

    #[Test]
    public function localhost_remains_stateful(): void
    {
        $request = Request::create('http://localhost:8000/api/v1/admin/pairing/status');
        $request->headers->set('Referer', 'http://localhost:8000/admin/pairing');

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    #[Test]
    public function a_referer_from_an_unrelated_host_is_not_stateful(): void
    {
        // Forging a Referer from another site must not unlock session auth:
        // only the host actually serving the request counts, never the
        // client-supplied referer alone.
        $this->assertFalse($this->fromPhone('http://evil.example.com/phishing'));
    }

    #[Test]
    public function requests_without_referer_or_origin_are_not_stateful(): void
    {
        // Device endpoints (readers, curl, tests) send no Referer/Origin:
        // they stay purely stateless Bearer flows.
        $request = Request::create('http://192.168.1.6:8000/api/v1/admin/cards/pair');

        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    #[Test]
    public function an_explicit_stateful_domains_list_is_respected(): void
    {
        // Restricting via SANCTUM_STATEFUL_DOMAINS replaces the default
        // entirely (including the request-host entry): the phone's host
        // is then NOT first-party.
        config(['sanctum.stateful' => ['localhost', 'localhost:8000']]);

        $this->assertFalse($this->fromPhone());
    }

    #[Test]
    public function the_request_host_placeholder_is_in_the_default_config(): void
    {
        // Guards the config wiring itself: the placeholder must survive in
        // sanctum.stateful (it is what the middleware swaps for the request
        // host at runtime). If this fails, the default was narrowed.
        $this->assertContains(
            Sanctum::$currentRequestHostPlaceholder,
            config('sanctum.stateful'),
        );
        $this->assertTrue(Str::contains(implode(',', config('sanctum.stateful')), 'localhost'));
    }
}
