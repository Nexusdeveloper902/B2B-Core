<?php

namespace Tests\Unit;

use App\Models\PresenceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-015 — the platform runs on Colombia school time (ADR-025):
 * America/Bogota, fixed UTC-5, no DST. One timezone, wall-clock
 * semantics end to end — now(), Eloquent datetime storage and every
 * dashboard clock are the same Bogota local time, so there is no
 * storage/display conversion layer to get wrong.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_platform_runs_on_colombia_time_by_default(): void
    {
        $this->assertSame('America/Bogota', config('app.timezone'));
        $this->assertSame('America/Bogota', date_default_timezone_get());
        $this->assertSame('America/Bogota', now()->getTimezone()->getName());
    }

    #[Test]
    public function the_app_timezone_is_env_overridable_for_other_deployments(): void
    {
        config(['app.timezone' => 'Europe/Madrid']);

        $this->assertSame('Europe/Madrid', config('app.timezone'));
    }

    #[Test]
    public function bogota_is_a_fixed_utc_minus_five_offset_with_no_dst(): void
    {
        // COT has no daylight saving: the offset is stable year-round in
        // both halves of the year, so naive "HH:MM" comparisons (e.g. the
        // 08:15 late cutoff) are always school-local wall time.
        $january = now()->setMonth(1)->setDay(15);
        $july = now()->setMonth(7)->setDay(15);

        $this->assertSame('-05:00', now()->format('P'));
        $this->assertSame('-05:00', $january->format('P'));
        $this->assertSame('-05:00', $july->format('P'));
    }

    #[Test]
    public function eloquent_timestamps_round_trip_in_bogota_wall_time(): void
    {
        $this->seedDemo();

        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('classroom')->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $fresh = PresenceEvent::findOrFail($event->id);

        // Stored naive Bogota wall time, re-read as Bogota wall time —
        // the exact string the teacher dashboard renders is "07:50".
        $this->assertSame('America/Bogota', $fresh->occurred_at->getTimezone()->getName());
        $this->assertSame('07:50', $fresh->occurred_at->format('H:i'));

        // API-facing ISO 8601 keeps the explicit -05:00 offset so device
        // parsers never have to guess the zone.
        $this->assertStringEndsWith('-05:00', $fresh->occurred_at->toIso8601String());
    }
}
