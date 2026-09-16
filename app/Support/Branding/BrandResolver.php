<?php

namespace App\Support\Branding;

use App\Models\School;
use Illuminate\Support\Facades\Cookie;

/**
 * TASK-045 (ADR-065) — account → school → branding, resolved once.
 *
 * The decision lives HERE and nowhere else: no controller, service or
 * Blade template ever compares a school name to a string. Views consume
 * the resolved Brand; adding a school never touches them.
 *
 * Resolution order:
 *   1. the authenticated account's school (`users.school_id`) — always
 *      wins, so a session can never inherit another account's identity;
 *   2. for GUESTS only, the brand remembered on this device (see
 *      `remember()`), so a school's own shared devices show the school's
 *      login screen instead of flashing Pulse and switching after
 *      sign-in;
 *   3. default Pulse.
 *
 * An unknown brand_key (a future school whose profile has not shipped)
 * falls back to Pulse rather than failing — a missing profile must never
 * be able to take the dashboards down.
 */
class BrandResolver
{
    /** The device-remembered brand key (guest screens only, never authoritative). */
    public const COOKIE = 'pulse_brand';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    /** @var array{0: string, 1: Brand}|null [identity key, resolved brand] */
    private ?array $memo = null;

    /**
     * The brand for the CURRENT viewer.
     *
     * Memoized against the IDENTITY it was resolved for, not merely
     * "once": this object outlives a single request in a test runner
     * (and would under Octane), and a memo that ignored who is asking
     * is precisely how one account's branding leaks into the next
     * one's session.
     */
    public function current(): Brand
    {
        $key = $this->identityKey();

        if ($this->memo !== null && $this->memo[0] === $key) {
            return $this->memo[1];
        }

        $brand = $this->resolve();
        $this->memo = [$key, $brand];

        return $brand;
    }

    /** Drop the memo — used when the authenticated identity changes. */
    public function forget(): void
    {
        $this->memo = null;
    }

    /** Who this resolution belongs to: an account id, or a guest's remembered key. */
    private function identityKey(): string
    {
        $user = app()->bound('auth') ? auth()->user() : null;

        return $user !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'guest:'.($this->rememberedKey() ?? '');
    }

    /** The branding profile a school renders with (null school = Pulse). */
    public function for(?School $school): Brand
    {
        return $this->forKey($school?->brand_key);
    }

    /** The branding profile for a raw config key (unknown/null = Pulse). */
    public function forKey(?string $key): Brand
    {
        $default = (string) config('branding.default', 'pulse');
        $brands = (array) config('branding.brands', []);

        if ($key === null || $key === '' || ! isset($brands[$key])) {
            $key = $default;
        }

        if (! isset($brands[$key])) {
            // Config itself is broken (no pulse profile) — synthesize the
            // stock identity rather than 500 the whole application.
            return Brand::fromConfig('pulse', [], true);
        }

        return Brand::fromConfig($key, (array) $brands[$key], $key === $default);
    }

    /**
     * Queue the device memory of this brand.
     *
     * Called on successful login ONLY, so the remembered value always
     * describes the account that actually signed in here — including
     * when that account is an unbranded one, which CLEARS the memory
     * (a Pulse admin signing in on a school device leaves a Pulse
     * login screen behind, never the previous school's).
     */
    public function remember(Brand $brand): void
    {
        if ($brand->isDefault()) {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return;
        }

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $brand->key(),
            minutes: self::COOKIE_MINUTES,
            httpOnly: true,
        ));
    }

    private function resolve(): Brand
    {
        $user = app()->bound('auth') ? auth()->user() : null;

        if ($user !== null) {
            // The authenticated account is the ONLY authority once there
            // is one: a stale device cookie can never re-brand a session.
            return $this->for($user->school_id !== null ? School::find($user->school_id) : null);
        }

        return $this->forKey($this->rememberedKey());
    }

    private function rememberedKey(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $key = request()->cookie(self::COOKIE);

        return is_string($key) && $key !== '' ? $key : null;
    }
}
