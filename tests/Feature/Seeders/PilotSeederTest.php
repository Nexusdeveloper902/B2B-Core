<?php

namespace Tests\Feature\Seeders;

use App\Models\Card;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RewardRedemption;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix 3) — the pilot seeder: a school that looks alive.
 * DemoSeeder stays the small test fixture; PilotSeeder is the
 * human-facing dataset (fresh DBs only). Deterministic: same seed,
 * same story, every reseed — counts are pinned exactly.
 */
class PilotSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\PilotSeeder', '--force' => true]);
    }

    #[Test]
    public function the_pilot_world_has_the_documented_shape(): void
    {
        $this->assertSame(3, SchoolClass::count());
        $this->assertSame(24, Student::count());
        $this->assertSame(24, Card::count());
        $this->assertSame(5, Reader::count());
        $this->assertSame(24, User::where('role', 'student')->count());
        $this->assertSame(4, User::whereIn('role', ['admin', 'teacher'])->count());

        // Deterministic story: same seed, same volumes.
        $this->assertSame(993, PresenceEvent::count());
        $this->assertSame(69, RecyclingDeposit::count());
        $this->assertSame(2, RewardRedemption::count());
    }

    #[Test]
    public function every_student_can_log_in_and_tap(): void
    {
        foreach (Student::all() as $student) {
            $this->assertNotNull($student->account, "{$student->name} has no login");
            $this->assertFalse(
                (bool) $student->account->must_change_password,
                "{$student->name} fixture login must stay one-tap"
            );
            $this->assertTrue(
                Hash::check('password', $student->account->password),
                "{$student->name} fixture login must use the demo password"
            );
            $this->assertSame(1, $student->cards()->count(), "{$student->name} must hold exactly one card");
        }

        // Convention emails, one per student, no collisions.
        $this->assertSame(24, User::where('role', 'student')->pluck('email')->unique()->count());
    }

    #[Test]
    public function pae_taps_honor_enrollment(): void
    {
        $paeStudentIds = Student::where('pae_enrolled', true)->pluck('id')->all();

        $offenders = PresenceEvent::query()
            ->whereIn('type', ['PAE_BREAKFAST', 'PAE_LUNCH'])
            ->whereHas('card', fn ($q) => $q->whereNotIn('student_id', $paeStudentIds))
            ->count();

        $this->assertSame(0, $offenders, 'no PAE tap may belong to a non-enrolled student');
        $this->assertGreaterThan(100, PresenceEvent::where('type', 'PAE_BREAKFAST')->count());
        $this->assertGreaterThan(100, PresenceEvent::where('type', 'PAE_LUNCH')->count());
    }

    #[Test]
    public function every_deposit_has_matching_ledger_points(): void
    {
        foreach (RecyclingDeposit::all() as $deposit) {
            $ledger = PointsLedger::where('event_id', $deposit->event_id)
                ->where('reason', 'recycling_deposit')
                ->first();

            $this->assertNotNull($ledger, "deposit {$deposit->id} has no ledger row");
            $this->assertSame($deposit->points_awarded, $ledger->delta);
            $this->assertSame(
                (int) config("recycling.points.{$deposit->material_class->value}"),
                $deposit->points_awarded,
                'points come from config, never invention'
            );
        }

        $this->assertSame(
            PointsLedger::where('reason', 'recycling_deposit')->sum('delta'),
            RecyclingDeposit::sum('points_awarded')
        );
    }

    #[Test]
    public function all_taps_land_on_school_days_in_bogota_wall_time(): void
    {
        foreach (PresenceEvent::all() as $event) {
            $when = Carbon::parse($event->occurred_at, 'America/Bogota');
            $this->assertFalse($when->isWeekend(), "{$event->occurred_at} falls on a weekend");
            $this->assertSame('-05:00', $when->format('P'));
        }
    }

    #[Test]
    public function entry_exit_days_pair_into_sessions(): void
    {
        $service = new AttendanceService;
        $rows = 0;
        $minutes = 0;

        foreach (Student::all() as $student) {
            foreach ($service->studentSessions($student, 10) as $day) {
                $this->assertGreaterThanOrEqual(0, $day['minutes_in_school']);
                $rows++;
                $minutes += $day['minutes_in_school'];
            }
        }

        $this->assertGreaterThan(100, $rows, 'ten days must pair into plenty of day-rows');
        $this->assertGreaterThan(0, $minutes, 'paired sessions must accumulate time in school');
    }

    #[Test]
    public function the_story_has_real_absence_gaps(): void
    {
        $service = new AttendanceService;
        $gaps = 0;

        foreach ($service->attendanceTrend(10) as $point) {
            $absent = $service->absentStudents($point['date']);
            if ($absent !== []) {
                $gaps++;
            }
        }

        $this->assertGreaterThan(0, $gaps, 'ten days must contain real absence gaps');
    }

    #[Test]
    public function reseeding_refuses_instead_of_doubling(): void
    {
        $events = PresenceEvent::count();

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\PilotSeeder', '--force' => true]);

        $this->assertSame($events, PresenceEvent::count());
        $this->assertSame(24, Student::count());
    }

    #[Test]
    public function the_dashboards_render_with_live_data(): void
    {
        $admin = User::where('email', 'admin@presence.test')->firstOrFail();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/admin/ecostation')->assertOk();

        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->get('/teacher')->assertOk();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->get('/student')->assertOk();
    }
}
