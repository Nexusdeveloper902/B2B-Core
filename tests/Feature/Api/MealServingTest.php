<?php

namespace Tests\Feature\Api;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Setting;
use App\Services\AttendanceService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-037 — the meal-serving engine contract (ADR-053), exercised over
 * real HTTP with a real cafeteria reader: auto meal detection from the
 * configured windows, weekday-only service, America/Bogota school-local
 * dates, window boundaries (start inclusive / end exclusive),
 * out-of-window rejection, the same-day CLASS_ATTENDANCE prerequisite,
 * per-meal enrollment with meal-specific messages, duplicate detection
 * (flagged, counted once), and the served/flagged counting discipline.
 *
 * Every test freezes time (travelTo a known Tuesday) and sets explicit
 * windows — the engine never depends on the machine's wall clock.
 */
class MealServingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        // The fixture owns ALL events: the demo seeder's past-day PAE
        // scenario rows would leak into the counts below.
        PresenceEvent::query()->delete();
        $this->travelTo(Carbon::parse('2026-09-01 07:10:00')); // Tuesday, school-local
        $this->windows('06:30', '08:30', '11:30', '13:30');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function windows(string $bStart, string $bEnd, string $lStart, string $lEnd): void
    {
        app(SettingsService::class)->setMany([
            'pae.breakfast_start' => $bStart,
            'pae.breakfast_end' => $bEnd,
            'pae.lunch_start' => $lStart,
            'pae.lunch_end' => $lEnd,
        ]);
    }

    private function cafeteria(): Reader
    {
        return $this->schoolReader(
            'Engine Test — Cafeteria',
            [
                'type' => 'pae',
                'active_event_type' => 'PAE_LUNCH', // deliberately "wrong": auto-detection owns the meal
                'api_key' => 'meal-engine-test-key-000000000001',
            ],
        );
    }

    private function attend(string $student, ?string $at = null): void
    {
        PresenceEvent::create([
            'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', $student))->firstOrFail()->id,
            'reader_id' => Reader::where('type', 'classroom')->firstOrFail()->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse($at ?? '2026-09-01 07:00:00'),
        ]);
    }

    private function tapAt(string $student, string $time)
    {
        $reader = $this->cafeteria();
        $uid = Card::whereHas('student', fn ($q) => $q->where('name', $student))->firstOrFail()->credential_uid;

        return $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-01 '.$time.':00',
        ], ['Authorization' => "Bearer {$reader->api_key}"]);
    }

    // ---- Automatic meal selection ------------------------------------

    #[Test]
    public function the_meal_is_auto_detected_from_the_windows_not_the_reader_mode(): void
    {
        $this->attend('Maria González');

        // Reader's active_event_type is PAE_LUNCH, but it is 07:15 —
        // the clock, not the label, owns the meal.
        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $this->tapAt('Maria González', '07:15')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'PAE_BREAKFAST', 'meal' => 'breakfast']);
    }

    #[Test]
    public function a_lunch_time_tap_auto_detects_lunch(): void
    {
        $this->attend('Maria González');

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Maria González', '12:15')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'PAE_LUNCH', 'meal' => 'lunch']);
    }

    #[Test]
    public function a_relabelled_classroom_reader_also_auto_detects_the_meal(): void
    {
        // The legacy manual workflow (classroom reader relabeled to a
        // PAE mode) routes through the engine too — same clock truth.
        $reader = Reader::where('type', 'classroom')->firstOrFail();
        $reader->update(['active_event_type' => 'PAE_LUNCH']);

        $uid = Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail()->credential_uid;
        $this->attend('Maria González');

        $this->travelTo(Carbon::parse('2026-09-01 07:20:00'));
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-01 07:20:00',
        ], ['Authorization' => "Bearer {$reader->api_key}"])
            ->assertOk()
            ->assertJson(['event_type' => 'PAE_BREAKFAST']);
    }

    // ---- Window boundaries --------------------------------------------

    #[Test]
    public function window_boundaries_are_start_inclusive_end_exclusive(): void
    {
        $this->attend('Maria González', '2026-09-01 06:00:00');

        $this->tapAt('Maria González', '06:30')->assertOk()->assertJson(['meal' => 'breakfast']);
        $this->tapAt('Maria González', '08:29')->assertStatus(422); // second breakfast-in-window tap → duplicate (still in-window)
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_BREAKFAST')->where('served', true)->count());

        // 08:30 is the exclusive end: no meal window is active.
        $this->tapAt('Maria González', '08:30')
            ->assertStatus(422)
            ->assertJson(['reason' => 'out_of_window']);
    }

    #[Test]
    public function the_gap_between_meals_rejects_with_a_hint(): void
    {
        $this->attend('Maria González');

        $response = $this->tapAt('Maria González', '10:00');

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'out_of_window']);
        $this->assertStringContainsString('06:30', (string) $response->json('message'));
        $this->assertStringContainsString('11:30', (string) $response->json('message'));
    }

    #[Test]
    public function an_out_of_window_tap_is_recorded_as_a_flagged_attempt(): void
    {
        $this->attend('Maria González');
        $before = PresenceEvent::count();

        $this->tapAt('Maria González', '15:00')->assertStatus(422);

        $this->assertSame($before + 1, PresenceEvent::count());
        $this->assertDatabaseHas('events', [
            'type' => 'PAE_ATTEMPT',
            'served' => false,
            'reason' => 'out_of_window',
        ]);
    }

    #[Test]
    public function overlapping_windows_reject_loudly_never_silently_assign(): void
    {
        // Bypass validation (direct row) to test the engine's safety net.
        Setting::updateOrCreate(['key' => 'pae.lunch_start'], ['value' => '07:00']);
        SettingsService::flushCache();

        $this->attend('Maria González');

        // 07:30 is inside BOTH windows: flagged window_overlap, no meal.
        $this->tapAt('Maria González', '07:30')
            ->assertStatus(422)
            ->assertJson(['reason' => 'window_overlap']);
        $this->assertDatabaseHas('events', ['type' => 'PAE_ATTEMPT', 'served' => false, 'reason' => 'window_overlap']);
    }

    // ---- Weekday + timezone -------------------------------------------

    #[Test]
    public function weekend_taps_are_rejected_and_flagged(): void
    {
        $this->attend('Maria González');

        $this->travelTo(Carbon::parse('2026-09-05 12:15:00')); // Saturday
        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail()->credential_uid,
            'client_timestamp' => '2026-09-05 12:15:00',
        ], ['Authorization' => 'Bearer '.$this->cafeteria()->api_key]);

        $response->assertStatus(422)->assertJson(['reason' => 'weekend']);
        $this->assertDatabaseHas('events', ['type' => 'PAE_ATTEMPT', 'served' => false, 'reason' => 'weekend']);
    }

    #[Test]
    public function sunday_bogota_evening_is_still_the_weekend_in_bogota_time(): void
    {
        // 2026-09-06 is Sunday in America/Bogota at every hour (COT has
        // no transitions — ADR-025): the engine works in school-local
        // wall time, so an evening tap is a weekend rejection.
        $this->travelTo(Carbon::parse('2026-09-06 18:30:00'));
        $this->assertTrue(now()->isSunday());

        $uid = Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail()->credential_uid;
        $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-06 18:30:00',
        ], ['Authorization' => 'Bearer '.$this->cafeteria()->api_key])
            ->assertStatus(422)
            ->assertJson(['reason' => 'weekend']);
    }

    // ---- Attendance prerequisite ---------------------------------------

    #[Test]
    public function a_meal_requires_earlier_same_day_class_attendance(): void
    {
        // Maria never tapped attendance today.
        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $response = $this->tapAt('Maria González', '12:15');

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'no_attendance']);
        $this->assertDatabaseHas('events', ['type' => 'PAE_LUNCH', 'served' => false, 'reason' => 'no_attendance']);
    }

    #[Test]
    public function yesterdays_attendance_does_not_count(): void
    {
        // Attendance yesterday (2026-08-31): today's meal is still rejected.
        PresenceEvent::create([
            'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail()->id,
            'reader_id' => Reader::where('type', 'classroom')->firstOrFail()->id,
            'type' => 'CLASS_ATTENDANCE',
            'occurred_at' => Carbon::parse('2026-08-31 07:00:00'),
        ]);

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Maria González', '12:15')
            ->assertStatus(422)
            ->assertJson(['reason' => 'no_attendance']);
    }

    #[Test]
    public function attendance_later_than_the_meal_tap_does_not_count(): void
    {
        // Attendance 13:00 (after the 12:15 lunch tap): rejected — the
        // prerequisite is STRICTLY earlier.
        $this->attend('Maria González', '2026-09-01 13:00:00');

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Maria González', '12:15')
            ->assertStatus(422)
            ->assertJson(['reason' => 'no_attendance']);
    }

    #[Test]
    public function an_absent_student_can_not_receive_a_meal(): void
    {
        // Diego: neither meal, no attendance — but the attendance rule
        // fires first only for enrolled students; the enrollment rule
        // answers here (both are correct honest rejections).
        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Diego López', '12:15')
            ->assertStatus(422)
            ->assertJson(['reason' => 'not_enrolled']);
        $this->assertSame(0, PresenceEvent::where('type', 'PAE_LUNCH')->where('served', true)->count());
    }

    // ---- Per-meal enrollment -------------------------------------------

    #[Test]
    public function breakfast_not_enrolled_is_rejected_with_a_breakfast_specific_message(): void
    {
        $this->attend('Ana Martínez'); // lunch-only

        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $response = $this->tapAt('Ana Martínez', '07:15');

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'not_enrolled', 'meal' => 'breakfast']);
        $this->assertStringContainsString('Breakfast', (string) $response->json('message'));
        $this->assertDatabaseHas('events', ['type' => 'PAE_BREAKFAST', 'served' => false, 'reason' => 'not_enrolled']);
    }

    #[Test]
    public function lunch_not_enrolled_is_rejected_with_a_lunch_specific_message(): void
    {
        $this->attend('Carlos Pérez'); // breakfast-only

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $response = $this->tapAt('Carlos Pérez', '12:15');

        $response->assertStatus(422)->assertJson(['reason' => 'not_enrolled', 'meal' => 'lunch']);
        $this->assertStringContainsString('Lunch', (string) $response->json('message'));
    }

    #[Test]
    public function breakfast_and_lunch_enrollment_are_independent(): void
    {
        // Carlos: breakfast yes, lunch no.
        $this->attend('Carlos Pérez');

        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $this->tapAt('Carlos Pérez', '07:15')->assertOk()->assertJson(['meal' => 'breakfast']);

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Carlos Pérez', '12:15')->assertStatus(422)->assertJson(['reason' => 'not_enrolled']);

        // Ana: breakfast no, lunch yes.
        $this->attend('Ana Martínez');
        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $this->tapAt('Ana Martínez', '07:15')->assertStatus(422)->assertJson(['reason' => 'not_enrolled']);

        $this->travelTo(Carbon::parse('2026-09-01 12:15:00'));
        $this->tapAt('Ana Martínez', '12:15')->assertOk()->assertJson(['meal' => 'lunch']);
    }

    #[Test]
    public function breakfast_rejection_replies_in_spanish_when_asked(): void
    {
        $this->attend('Ana Martínez'); // lunch-only

        $uid = Card::whereHas('student', fn ($q) => $q->where('name', 'Ana Martínez'))->firstOrFail()->credential_uid;

        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $response = $this->postJson('/api/v1/events/tap', [
            'credential_uid' => $uid,
            'client_timestamp' => '2026-09-01 07:15:00',
        ], ['Authorization' => 'Bearer '.$this->cafeteria()->api_key, 'Accept-Language' => 'es']);

        $response->assertStatus(422)->assertJson(['reason' => 'not_enrolled', 'meal' => 'breakfast']);
        $this->assertSame('Ana no está inscrito para el Desayuno', $response->json('message'));
    }

    // ---- Duplicates -----------------------------------------------------

    #[Test]
    public function a_duplicate_meal_is_flagged_and_counted_once(): void
    {
        $this->attend('Maria González');

        $this->travelTo(Carbon::parse('2026-09-01 12:10:00'));
        $first = $this->tapAt('Maria González', '12:10');
        $first->assertOk();

        $this->travelTo(Carbon::parse('2026-09-01 12:40:00'));
        $second = $this->tapAt('Maria González', '12:40');
        $second->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'duplicate', 'meal' => 'lunch']);
        $this->assertStringContainsString('already received', (string) $second->json('message'));

        // One served meal, one flagged attempt; the original row is intact.
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_LUNCH')->where('served', true)->count());
        $this->assertSame(1, PresenceEvent::where('type', 'PAE_LUNCH')->where('served', false)->where('reason', 'duplicate')->count());
        $this->assertSame(
            $first->json('event_id'),
            PresenceEvent::where('type', 'PAE_LUNCH')->where('served', true)->first()->id
        );

        $service = new AttendanceService;
        $this->assertSame(1, $service->paeCount('lunch', '2026-09-01'));
    }

    #[Test]
    public function a_flagged_attempt_does_not_create_a_duplicate_entitlement(): void
    {
        // First tap (no attendance) is rejected; after attending, the
        // SAME meal is still servable — the flag never counted.
        $this->travelTo(Carbon::parse('2026-09-01 12:10:00'));
        $this->tapAt('Maria González', '12:10')->assertStatus(422)->assertJson(['reason' => 'no_attendance']);

        $this->attend('Maria González', '2026-09-01 12:12:00');
        $this->tapAt('Maria González', '12:15')->assertOk()->assertJson(['meal' => 'lunch']);
    }

    // ---- Counting discipline --------------------------------------------

    #[Test]
    public function rejected_attempts_never_inflate_served_meal_metrics(): void
    {
        $this->attend('Ana Martínez');
        $this->attend('Maria González');
        $this->attend('Carlos Pérez');

        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $this->tapAt('Ana Martínez', '07:15')->assertStatus(422); // not_enrolled
        $this->tapAt('Maria González', '07:15')->assertOk();
        $this->tapAt('Maria González', '07:25')->assertStatus(422); // duplicate

        $this->travelTo(Carbon::parse('2026-09-01 10:00:00'));
        $this->tapAt('Carlos Pérez', '10:00')->assertStatus(422); // out_of_window

        $service = new AttendanceService;

        $this->assertSame(1, $service->paeCount('breakfast', '2026-09-01'));
        $this->assertSame(3, PresenceEvent::where('served', false)->count()); // all auditable
    }

    #[Test]
    public function the_accepted_response_carries_the_meal_and_a_localized_message(): void
    {
        $this->attend('Maria González');

        $this->travelTo(Carbon::parse('2026-09-01 07:15:00'));
        $response = $this->tapAt('Maria González', '07:15');

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'event_type' => 'PAE_BREAKFAST', 'meal' => 'breakfast'])
            ->assertJsonStructure(['message', 'student_first_name']);
        $this->assertStringContainsString('Maria', (string) $response->json('message'));
    }
}
