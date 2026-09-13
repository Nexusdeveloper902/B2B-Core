<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TASK-037 — runtime settings (ADR-055).
 *
 * Administrators configure the safe presence/PAE environment knobs
 * through /admin/settings instead of editing .env. Every read resolves
 * through this service with a three-level fallback chain:
 *
 *     settings table row  →  config/presence.php default (env-overridable)
 *
 * Values are cached per request (one DB query max, lazily). Secrets and
 * credentials are deliberately NOT part of this surface — only safe
 * behavioral configuration (meal windows, cutoffs, pairing window,
 * student account conventions).
 *
 * The canonical key registry + validation lives here so the admin API,
 * the seeder, and the engine all agree on shape. HH:MM values are
 * school-local wall time (America/Bogota, no DST — ADR-025).
 */
class SettingsService
{
    /** @var array<string, mixed>|null per-request cache (null = not loaded) */
    private static ?array $cache = null;

    /**
     * The canonical registry: key => [default config path, rule, label].
     * Rules are Laravel validator strings; labels are lang keys.
     */
    private const REGISTRY = [
        'pae.breakfast_start' => ['presence.pae.breakfast_start', 'required|date_format:H:i'],
        'pae.breakfast_end' => ['presence.pae.breakfast_end', 'required|date_format:H:i|after:pae.breakfast_start'],
        'pae.lunch_start' => ['presence.pae.lunch_start', 'required|date_format:H:i'],
        'pae.lunch_end' => ['presence.pae.lunch_end', 'required|date_format:H:i|after:pae.lunch_start'],
        'attendance.late_cutoff' => ['presence.late_cutoff', 'required|date_format:H:i'],
        'pairing.window_seconds' => ['presence.pairing_window_seconds', 'required|integer|between:10,600'],
        'accounts.student_email_domain' => ['presence.student_email_domain', 'required|string|max:190'],
        'accounts.student_initial_password' => ['presence.student_initial_password', 'required|string|min:4|max:120'],
    ];

    /** Flush the per-request cache (tests, seeder, after external writes). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** Every canonical key in registry order. @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * Resolve one setting: settings row override → config default.
     */
    public function get(string $key): mixed
    {
        $this->load();

        return self::$cache[$key] ?? $this->default($key);
    }

    /**
     * All settings as key => effective value (the admin UI's read model).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->load();

        $all = [];
        foreach (array_keys(self::REGISTRY) as $key) {
            $all[$key] = self::$cache[$key] ?? $this->default($key);
        }

        return $all;
    }

    /**
     * Which keys have a stored override row (UI hint: "customized").
     *
     * @return array<int, string>
     */
    public function customizedKeys(): array
    {
        $this->load();

        return array_values(array_filter(
            array_keys(self::REGISTRY),
            fn (string $key): bool => array_key_exists($key, self::$cache),
        ));
    }

    /**
     * Validate + persist a batch of overrides. Unknown keys and
     * cross-field violations (meal windows overlapping, end <= start)
     * throw ValidationException — the admin API maps them to 422.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the effective values after the write
     */
    public function setMany(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys(self::REGISTRY));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => trans('api.settings_unknown_key', ['key' => implode(', ', $unknown)]),
            ]);
        }

        // Validate the merged view (stored overrides + incoming), so a
        // partial update can never produce an invalid combined state.
        $effective = array_merge($this->all(), $values);

        $rules = [];
        foreach (self::REGISTRY as $key => [, $rule]) {
            $rules[$key] = $rule;
        }

        $breakfast = [$effective['pae.breakfast_start'], $effective['pae.breakfast_end']];
        $lunch = [$effective['pae.lunch_start'], $effective['pae.lunch_end']];
        if ($this->windowsOverlap($breakfast, $lunch)) {
            throw ValidationException::withMessages([
                'pae.lunch_start' => trans('api.settings_windows_overlap'),
            ]);
        }

        // Dotted keys are nested-array paths to Laravel's validator (a
        // flat ['pae.breakfast_start' => …] payload would look EMPTY to
        // the 'pae.breakfast_start' rule). Convert to the nested shape
        // the rules actually address; error keys come back dotted.
        $nested = [];
        foreach ($effective as $key => $value) {
            data_set($nested, $key, $value);
        }

        $validator = validator($nested, $rules);
        $validator->validate();

        // Normalize types so the JSON round-trip is stable.
        if (array_key_exists('pairing.window_seconds', $values)) {
            $values['pairing.window_seconds'] = (int) $values['pairing.window_seconds'];
        }

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        self::flushCache();

        return $this->all();
    }

    /**
     * The meal serving windows, resolved. Shape:
     * ['breakfast' => ['start' => 'HH:MM', 'end' => 'HH:MM'], 'lunch' => [...]]
     *
     * @return array<string, array<string, string>>
     */
    public function mealWindows(): array
    {
        return [
            'breakfast' => [
                'start' => (string) $this->get('pae.breakfast_start'),
                'end' => (string) $this->get('pae.breakfast_end'),
            ],
            'lunch' => [
                'start' => (string) $this->get('pae.lunch_start'),
                'end' => (string) $this->get('pae.lunch_end'),
            ],
        ];
    }

    /** Attendance late cutoff (HH:MM, school-local). */
    public function lateCutoff(): string
    {
        return (string) $this->get('attendance.late_cutoff');
    }

    /** Card-pairing window in seconds (ADR-020). */
    public function pairingWindowSeconds(): int
    {
        return (int) $this->get('pairing.window_seconds');
    }

    /** Student account email domain (ADR-044). */
    public function studentEmailDomain(): string
    {
        return (string) $this->get('accounts.student_email_domain');
    }

    /** Student account shared initial password (ADR-044). */
    public function studentInitialPassword(): string
    {
        return (string) $this->get('accounts.student_initial_password');
    }

    /**
     * Do two HH:MM windows overlap? (Touching — one's end == the other's
     * start — is NOT an overlap: end is exclusive.)
     *
     * @param  array{0: string, 1: string}  $a
     * @param  array{0: string, 1: string}  $b
     */
    public function windowsOverlap(array $a, array $b): bool
    {
        return max($a[0], $b[0]) < min($a[1], $b[1]);
    }

    private function default(string $key): mixed
    {
        [$config] = self::REGISTRY[$key] ?? [null];

        return $config !== null ? config($config) : null;
    }

    /**
     * Lazy-load every override row into the per-request cache. A missing
     * settings table (pre-migration boot, migrate:fresh races) degrades
     * to config defaults — never a fatal boot.
     */
    private function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $rows = DB::table('settings')->get(['key', 'value']);
        } catch (\Throwable) {
            return; // table not there yet — config defaults carry the app
        }

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->value, true);
            self::$cache[(string) $row->key] = $decoded;
        }
    }
}
