<?php

namespace Tests\Unit;

use App\Models\Card;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\NlQuery\FunctionRegistry;
use App\Services\StudentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030 (Fix C) — the analytical half of the NL function surface,
 * executed directly (zero LLM): absence totals, absentee lists, late
 * counts, trends, repeat absentees, time-in-school and name lookup —
 * each with its scope fence pinned. The old half (attendance / PAE /
 * recycling / timeline counts) was already covered; these six rode
 * declarations-only until now.
 */
class AnalyticalFunctionsTest extends TestCase
{
    use RefreshDatabase;

    private FunctionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        // Same frozen noon as FunctionRegistryTest: the "N minutes ago"
        // fixtures always land on the queried calendar date.
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00'));
        $this->registry = app(FunctionRegistry::class);
        $this->seedDemo();
        $this->generateDay();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function generateDay(): void
    {
        // TASK-037 — the fixture owns ALL events: the demo seeder's
        // past-day PAE scenario rows are wiped so counts stay exact.
        PresenceEvent::query()->delete();

        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        $maria = Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail();
        $carlos = Card::whereHas('student', fn ($q) => $q->where('name', 'Carlos Pérez'))->firstOrFail();
        $ana = Card::whereHas('student', fn ($q) => $q->where('name', 'Ana Martínez'))->firstOrFail();

        // Maria 08:40 (late), Carlos 09:00 (late), Ana 07:30 (present);
        // Diego + Lucía absent.
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $classroom->id, 'type' => 'CLASS_ATTENDANCE', 'occurred_at' => now()->subMinutes(200)]);
        PresenceEvent::create(['card_id' => $carlos->id, 'reader_id' => $classroom->id, 'type' => 'CLASS_ATTENDANCE', 'occurred_at' => now()->subMinutes(180)]);
        PresenceEvent::create(['card_id' => $ana->id, 'reader_id' => $classroom->id, 'type' => 'CLASS_ATTENDANCE', 'occurred_at' => Carbon::parse('2026-09-01 07:30:00')]);

        // TASK-037 — Maria (both meals enrolled + present) gets breakfast
        // and lunch served, then a flagged duplicate; Carlos (breakfast
        // only) gets breakfast; Ana is lunch-only + present but does NOT
        // get lunch → the missed-meal signal; Diego/Lucía absent.
        $cafeteria = Reader::where('type', 'pae')->firstOrFail();
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $cafeteria->id, 'type' => 'PAE_BREAKFAST', 'occurred_at' => Carbon::parse('2026-09-01 07:20:00'), 'served' => true]);
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $cafeteria->id, 'type' => 'PAE_LUNCH', 'occurred_at' => Carbon::parse('2026-09-01 12:10:00'), 'served' => true]);
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $cafeteria->id, 'type' => 'PAE_LUNCH', 'occurred_at' => Carbon::parse('2026-09-01 12:40:00'), 'served' => false, 'reason' => 'duplicate']);
        PresenceEvent::create(['card_id' => $carlos->id, 'reader_id' => $cafeteria->id, 'type' => 'PAE_BREAKFAST', 'occurred_at' => Carbon::parse('2026-09-01 07:30:00'), 'served' => true]);
        PresenceEvent::create(['card_id' => $carlos->id, 'reader_id' => $cafeteria->id, 'type' => 'PAE_LUNCH', 'occurred_at' => Carbon::parse('2026-09-01 12:15:00'), 'served' => false, 'reason' => 'not_enrolled']);
    }

    #[Test]
    public function absence_count_counts_the_students_with_no_tap(): void
    {
        $result = $this->registry->execute('get_absence_count', ['date' => '2026-09-01']);

        $this->assertSame('2026-09-01', $result['date']);
        // Ana is present now (the missed-meal fixture); Diego + Lucía absent.
        $this->assertSame(2, $result['absence_count']);
    }

    #[Test]
    public function absent_students_names_the_missing(): void
    {
        $result = $this->registry->execute('get_absent_students', ['date' => '2026-09-01']);
        $names = array_column($result['absent_students'], 'name');

        $this->assertContains('Diego López', $names);
        $this->assertContains('Lucía Fernández', $names);
        $this->assertNotContains('Maria González', $names);
        $this->assertNotContains('Carlos Pérez', $names);
        $this->assertNotContains('Ana Martínez', $names);
    }

    #[Test]
    public function present_students_names_the_present(): void
    {
        // Mirror of absent_students: Maria + Carlos + Ana tapped in;
        // Diego + Lucía did not — "quién ha venido" must name the
        // tappers, never the absentees (polarity-flip regression).
        $result = $this->registry->execute('get_present_students', ['date' => '2026-09-01']);
        $names = array_column($result['present_students'], 'name');

        $this->assertContains('Maria González', $names);
        $this->assertContains('Carlos Pérez', $names);
        $this->assertContains('Ana Martínez', $names);
        $this->assertNotContains('Diego López', $names);
        $this->assertNotContains('Lucía Fernández', $names);
    }

    #[Test]
    public function late_count_counts_distinct_students_once(): void
    {
        // Both tappers arrived after the 08:15 cutoff.
        $result = $this->registry->execute('get_late_count', ['date' => '2026-09-01']);

        $this->assertSame(2, $result['late_count']);
    }

    #[Test]
    public function attendance_trend_returns_one_point_per_day(): void
    {
        $result = $this->registry->execute('get_attendance_trend', ['days' => 3]);

        $this->assertSame(3, $result['days']);
        $this->assertCount(3, $result['trend']);
        $this->assertSame('2026-09-01', $result['trend'][2]['date']);
        $this->assertSame(3, $result['trend'][2]['students']);
        $this->assertSame(0, $result['trend'][0]['students']);
    }

    #[Test]
    public function trend_days_are_bounded(): void
    {
        $result = $this->registry->execute('get_attendance_trend', ['days' => 500]);

        $this->assertSame(90, $result['days']);
        $this->assertCount(90, $result['trend']);
    }

    #[Test]
    public function repeatedly_absent_flags_the_chronic_cases(): void
    {
        $result = $this->registry->execute(
            'get_repeatedly_absent_students',
            ['days' => 1, 'min_absences' => 1]
        );
        $names = array_column($result['students'], 'name');

        $this->assertSame(1, $result['days']);
        $this->assertSame(1, $result['min_absences']);
        $this->assertContains('Diego López', $names);
        $this->assertContains('Lucía Fernández', $names);
        $this->assertNotContains('Maria González', $names);
        $this->assertNotContains('Ana Martínez', $names);
    }

    #[Test]
    public function missed_meals_names_present_enrolled_not_served(): void
    {
        // Fixture (generateDay): Ana Martínez is lunch-only, present, and
        // got NO lunch → missed. Maria/Carlos ate; Lucía (absent) is not
        // "missed" (no attendance); Carlos is not lunch-enrolled.
        $result = $this->registry->execute('get_missed_meals', ['meal' => 'lunch', 'date' => '2026-09-01']);
        $names = array_column($result['missed_students'], 'name');

        $this->assertSame('lunch', $result['meal']);
        $this->assertSame(1, $result['missed_count']);
        $this->assertSame(['Ana Martínez'], $names);
    }

    #[Test]
    public function missed_meal_count_matches_the_list(): void
    {
        $count = $this->registry->execute('get_missed_meal_count', ['meal' => 'lunch', 'date' => '2026-09-01']);
        $list = $this->registry->execute('get_missed_meals', ['meal' => 'lunch', 'date' => '2026-09-01']);

        $this->assertSame($list['missed_count'], $count['missed_count']);
        $this->assertNull($count['missed_students']);
    }

    #[Test]
    public function missed_meals_on_a_non_school_day_is_empty(): void
    {
        // 2026-09-05 is a Saturday: no school day, no missed meals —
        // honest empty, never an invented list.
        $result = $this->registry->execute('get_missed_meals', ['meal' => 'lunch', 'date' => '2026-09-05']);

        $this->assertSame(0, $result['missed_count']);
        $this->assertSame([], $result['missed_students']);
    }

    #[Test]
    public function missed_meal_trend_counts_per_day(): void
    {
        $result = $this->registry->execute('get_missed_meal_trend', ['meal' => 'lunch', 'days' => 2]);

        $this->assertSame('lunch', $result['meal']);
        $this->assertSame(2, $result['days']);
        $this->assertSame(0, $result['trend'][0]['missed']);
        $this->assertSame(1, $result['trend'][1]['missed']);
    }

    #[Test]
    public function pae_enrollment_summarizes_per_meal(): void
    {
        // Demo roster: Maria both, Carlos breakfast-only, Ana lunch-only,
        // Diego neither, Lucía both.
        $result = $this->registry->execute('get_pae_enrollment', []);

        $this->assertSame(5, $result['total_students']);
        $this->assertSame(3, $result['breakfast']);
        $this->assertSame(3, $result['lunch']);
        $this->assertSame(2, $result['both']);
        $this->assertSame(1, $result['breakfast_only']);
        $this->assertSame(1, $result['lunch_only']);
        $this->assertSame(1, $result['neither']);
    }

    #[Test]
    public function student_pae_history_returns_per_day_rows(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $result = $this->registry->execute('get_student_pae_history', ['student_id' => $maria->id, 'days' => 1]);

        $this->assertSame('Maria González', $result['student']['name']);
        $this->assertTrue($result['student']['breakfast_enrolled']);
        $this->assertTrue($result['student']['lunch_enrolled']);
        $this->assertSame(1, $result['totals']['breakfasts']);
        $this->assertSame(1, $result['totals']['lunches']);
        // The flagged duplicate attempt counts as flagged, not as a lunch.
        $this->assertSame(1, $result['totals']['flagged']);
        $this->assertCount(1, $result['days']);
        $this->assertSame('served', $result['days'][0]['breakfast']['status']);
        $this->assertSame('served', $result['days'][0]['lunch']['status']);
    }

    #[Test]
    public function student_meals_on_answers_did_they_eat(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $carlos = Student::where('name', 'Carlos Pérez')->firstOrFail();

        $mariaResult = $this->registry->execute('get_student_meals_on', ['student_id' => $maria->id, 'date' => '2026-09-01']);
        $this->assertTrue($mariaResult['breakfast']);
        $this->assertTrue($mariaResult['lunch']);

        $carlosResult = $this->registry->execute('get_student_meals_on', ['student_id' => $carlos->id, 'date' => '2026-09-01']);
        $this->assertTrue($carlosResult['breakfast']);
        $this->assertFalse($carlosResult['lunch']); // flagged attempt ≠ meal
    }

    #[Test]
    public function flagged_meal_attempts_reports_reasons(): void
    {
        $result = $this->registry->execute('get_flagged_meal_attempts', ['date_from' => '2026-09-01', 'date_to' => '2026-09-01']);

        $this->assertSame(2, $result['flagged_count']);
        $this->assertSame(1, $result['by_reason']['duplicate']);
        $this->assertSame(1, $result['by_reason']['not_enrolled']);
    }

    #[Test]
    public function find_student_resolves_names_the_model_cannot_guess(): void
    {
        $result = $this->registry->execute('find_student', ['name' => 'maria']);
        $names = array_column($result['matches'], 'name');

        $this->assertSame('maria', $result['name']);
        $this->assertContains('Maria González', $names);
    }

    #[Test]
    public function late_students_names_the_late_with_tap_times(): void
    {
        $result = $this->registry->execute('get_late_students', ['date' => '2026-09-01']);
        $byName = collect($result['late_students'])->keyBy('name');

        $this->assertSame('08:40', $byName['Maria González']['tapped_at']);
        $this->assertSame('09:00', $byName['Carlos Pérez']['tapped_at']);
        $this->assertFalse($byName->has('Ana Martínez'));
    }

    #[Test]
    public function class_status_boards_one_class(): void
    {
        $classId = Student::where('name', 'Maria González')->firstOrFail()->class_id;

        $result = $this->registry->execute('get_class_status', ['class_id' => $classId, 'date' => '2026-09-01']);

        // TASK-037 — 5 students: Ana on time, Maria + Carlos late,
        // Diego + Lucía absent.
        $this->assertSame(['present' => 1, 'late' => 2, 'absent' => 2], $result['totals']);
        $this->assertCount(5, $result['students']);
        $byName = collect($result['students'])->keyBy('name');
        $this->assertSame('late', $byName['Maria González']['status']);
        $this->assertSame('present', $byName['Ana Martínez']['status']);
    }

    #[Test]
    public function attendance_by_class_breaks_down_every_class(): void
    {
        $result = $this->registry->execute('get_attendance_by_class', ['date' => '2026-09-01']);

        $this->assertNotEmpty($result['classes']);
        $row = collect($result['classes'])->firstWhere('class_name', '5° B');
        $this->assertSame(5, $row['enrolled']);
        $this->assertSame(3, $row['present']);
        $this->assertSame(2, $row['absent']);
        $this->assertSame(60, $row['rate']);
    }

    #[Test]
    public function enrollment_count_counts_the_roster(): void
    {
        $this->assertSame(5, $this->registry->execute('get_enrollment_count', [])['enrollment_count']);

        $classId = Student::where('name', 'Maria González')->firstOrFail()->class_id;
        $this->assertSame(5, $this->registry->execute('get_enrollment_count', ['class_id' => $classId])['enrollment_count']);
    }

    #[Test]
    public function pae_students_lists_who_ate(): void
    {
        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        foreach (['Maria González', 'Carlos Pérez'] as $name) {
            PresenceEvent::create([
                'card_id' => $this->cardOf($name)->id,
                'reader_id' => $classroom->id,
                'type' => 'PAE_BREAKFAST',
                'occurred_at' => now()->subMinutes(100),
            ]);
        }

        $result = $this->registry->execute('get_pae_students', ['meal' => 'breakfast', 'date' => '2026-09-01']);
        $names = array_column($result['pae_students'], 'name');

        $this->assertContains('Maria González', $names);
        $this->assertContains('Carlos Pérez', $names);
        $this->assertNotContains('Ana Martínez', $names);
    }

    #[Test]
    public function pae_trend_counts_per_day(): void
    {
        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $classroom->id,
            'type' => 'PAE_LUNCH',
            'occurred_at' => now()->subMinutes(60),
        ]);

        $result = $this->registry->execute('get_pae_trend', ['meal' => 'lunch', 'days' => 2]);

        $this->assertSame(2, $result['days']);
        $this->assertSame(0, $result['trend'][0]['students']);
        $this->assertSame(1, $result['trend'][1]['students']);
    }

    #[Test]
    public function recycling_leaderboard_ranks_by_points(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail()->id;
        $carlos = Student::where('name', 'Carlos Pérez')->firstOrFail()->id;
        PointsLedger::create(['student_id' => $maria, 'delta' => 10, 'reason' => 'recycling_deposit']);
        PointsLedger::create(['student_id' => $carlos, 'delta' => 25, 'reason' => 'recycling_deposit']);

        $result = $this->registry->execute('get_recycling_leaderboard', ['limit' => 2]);

        $this->assertSame('Carlos Pérez', $result['leaders'][0]['student_name']);
        $this->assertSame(1, $result['leaders'][0]['rank']);
        $this->assertSame(25, $result['leaders'][0]['points']);
        $this->assertSame('Maria González', $result['leaders'][1]['student_name']);
    }

    #[Test]
    public function student_points_reports_balance_earned_spent(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        PointsLedger::create(['student_id' => $maria->id, 'delta' => 10, 'reason' => 'recycling_deposit']);
        PointsLedger::create(['student_id' => $maria->id, 'delta' => 15, 'reason' => 'recycling_deposit']);
        PointsLedger::create(['student_id' => $maria->id, 'delta' => -5, 'reason' => 'reward_redeem']);

        $result = $this->registry->execute('get_student_points', ['student_id' => $maria->id]);

        $this->assertSame(20, $result['balance']);
        $this->assertSame(25, $result['earned']);
        $this->assertSame(5, $result['spent']);
    }

    #[Test]
    public function perfect_attendance_names_the_never_absent(): void
    {
        $result = $this->registry->execute('get_perfect_attendance', ['days' => 1]);
        $names = array_column($result['students'], 'name');

        $this->assertContains('Maria González', $names);
        $this->assertContains('Carlos Pérez', $names);
        $this->assertContains('Ana Martínez', $names);
        $this->assertNotContains('Diego López', $names);
        $this->assertNotContains('Lucía Fernández', $names);
    }

    #[Test]
    public function unknown_functions_are_structured_errors_never_exceptions(): void
    {
        $result = $this->registry->execute('get_breakfast_menu', []);

        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function a_teacher_scope_fences_every_analytical_call(): void
    {
        $other = SchoolClass::create(['name' => '9° Z']);
        $outsider = Student::create([
            'name' => 'Fuera Alcance',
            'grade' => '9°',
            'class_id' => $other->id,
            'pae_breakfast_enrolled' => false, 'pae_lunch_enrolled' => false,
        ]);

        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $scope = StudentScope::forUser($teacher);

        // Out-of-scope class requested explicitly: hard error, never data.
        $denied = $this->registry->execute(
            'get_absence_count',
            ['date' => '2026-09-01', 'class_id' => $other->id],
            $scope
        );
        $this->assertArrayHasKey('error', $denied);

        // Out-of-scope student for the PAE history: hard error.
        $walled = $this->registry->execute(
            'get_student_pae_history',
            ['student_id' => $outsider->id],
            $scope
        );
        $this->assertArrayHasKey('error', $walled);

        // Missed meals ride the same class fence.
        $missedDenied = $this->registry->execute(
            'get_missed_meals',
            ['meal' => 'lunch', 'date' => '2026-09-01', 'class_id' => $other->id],
            $scope
        );
        $this->assertArrayHasKey('error', $missedDenied);

        // New list functions ride the same fence.
        $lateDenied = $this->registry->execute(
            'get_late_students',
            ['date' => '2026-09-01', 'class_id' => $other->id],
            $scope
        );
        $this->assertArrayHasKey('error', $lateDenied);

        $pointsWalled = $this->registry->execute(
            'get_student_points',
            ['student_id' => $outsider->id],
            $scope
        );
        $this->assertArrayHasKey('error', $pointsWalled);

        // In-scope calls still answer (scoped to the teacher's class).
        $allowed = $this->registry->execute('get_absence_count', ['date' => '2026-09-01'], $scope);
        $this->assertSame(2, $allowed['absence_count']);

        $found = $this->registry->execute('find_student', ['name' => 'Fuera'], $scope);
        $this->assertSame([], $found['matches']);
    }
}
