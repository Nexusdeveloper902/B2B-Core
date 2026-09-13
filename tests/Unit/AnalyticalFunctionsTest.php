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
        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        $maria = Card::whereHas('student', fn ($q) => $q->where('name', 'Maria González'))->firstOrFail();
        $carlos = Card::whereHas('student', fn ($q) => $q->where('name', 'Carlos Pérez'))->firstOrFail();

        // Maria 08:40 (late), Carlos 09:00 (late); Ana + Diego absent.
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $classroom->id, 'type' => 'CLASS_ATTENDANCE', 'occurred_at' => now()->subMinutes(200)]);
        PresenceEvent::create(['card_id' => $carlos->id, 'reader_id' => $classroom->id, 'type' => 'CLASS_ATTENDANCE', 'occurred_at' => now()->subMinutes(180)]);

        // Maria's gate session: in 07:00, out 12:00 → 300 minutes.
        $entry = Reader::where('type', 'entry')->first();
        if ($entry === null) {
            $entry = Reader::create([
                'label' => 'Gate',
                'type' => 'entry',
                'active_event_type' => 'ENTRY',
                'api_key' => 'analytical-test-gate-key-00000001',
            ]);
        }
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $entry->id, 'type' => 'ENTRY', 'occurred_at' => Carbon::parse('2026-09-01 07:00:00')]);
        PresenceEvent::create(['card_id' => $maria->id, 'reader_id' => $entry->id, 'type' => 'EXIT', 'occurred_at' => Carbon::parse('2026-09-01 12:00:00')]);
    }

    #[Test]
    public function absence_count_counts_the_students_with_no_tap(): void
    {
        $result = $this->registry->execute('get_absence_count', ['date' => '2026-09-01']);

        $this->assertSame('2026-09-01', $result['date']);
        $this->assertSame(2, $result['absence_count']);
    }

    #[Test]
    public function absent_students_names_the_missing(): void
    {
        $result = $this->registry->execute('get_absent_students', ['date' => '2026-09-01']);
        $names = array_column($result['absent_students'], 'name');

        $this->assertContains('Ana Martínez', $names);
        $this->assertContains('Diego López', $names);
        $this->assertNotContains('Maria González', $names);
        $this->assertNotContains('Carlos Pérez', $names);
    }

    #[Test]
    public function present_students_names_the_present(): void
    {
        // Mirror of absent_students_names_the_missing: Maria + Carlos
        // tapped in, Ana + Diego did not — "quién ha venido" must name
        // the tappers, never the absentees (polarity-flip regression).
        $result = $this->registry->execute('get_present_students', ['date' => '2026-09-01']);
        $names = array_column($result['present_students'], 'name');

        $this->assertContains('Maria González', $names);
        $this->assertContains('Carlos Pérez', $names);
        $this->assertNotContains('Ana Martínez', $names);
        $this->assertNotContains('Diego López', $names);
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
        $this->assertSame(2, $result['trend'][2]['students']);
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
        $this->assertContains('Ana Martínez', $names);
        $this->assertContains('Diego López', $names);
        $this->assertNotContains('Maria González', $names);
    }

    #[Test]
    public function time_in_school_pairs_the_gate_session(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $result = $this->registry->execute(
            'get_student_time_in_school',
            ['student_id' => $maria->id, 'days' => 1]
        );

        $this->assertSame($maria->id, $result['student_id']);
        $this->assertSame('Maria González', $result['student_name']);
        $this->assertCount(1, $result['sessions_by_day']);
        $this->assertSame(300, $result['sessions_by_day'][0]['minutes_in_school']);
        $this->assertFalse($result['sessions_by_day'][0]['open_session']);
    }

    #[Test]
    public function time_in_school_for_an_unknown_student_is_an_error(): void
    {
        $result = $this->registry->execute('get_student_time_in_school', ['student_id' => 999999]);

        $this->assertArrayHasKey('error', $result);
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

        $this->assertSame(['present' => 0, 'late' => 2, 'absent' => 2], $result['totals']);
        $this->assertCount(4, $result['students']);
        $byName = collect($result['students'])->keyBy('name');
        $this->assertSame('late', $byName['Maria González']['status']);
        $this->assertSame('absent', $byName['Ana Martínez']['status']);
    }

    #[Test]
    public function attendance_by_class_breaks_down_every_class(): void
    {
        $result = $this->registry->execute('get_attendance_by_class', ['date' => '2026-09-01']);

        $this->assertNotEmpty($result['classes']);
        $row = collect($result['classes'])->firstWhere('class_name', '5° B');
        $this->assertSame(4, $row['enrolled']);
        $this->assertSame(2, $row['present']);
        $this->assertSame(2, $row['absent']);
        $this->assertSame(50, $row['rate']);
    }

    #[Test]
    public function enrollment_count_counts_the_roster(): void
    {
        $this->assertSame(4, $this->registry->execute('get_enrollment_count', [])['enrollment_count']);

        $classId = Student::where('name', 'Maria González')->firstOrFail()->class_id;
        $this->assertSame(4, $this->registry->execute('get_enrollment_count', ['class_id' => $classId])['enrollment_count']);
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
    public function students_in_school_lists_the_not_yet_exited(): void
    {
        // Fixture: Maria entered AND exited (outside). Carlos enters with
        // no exit (inside).
        $gate = Reader::where('type', 'entry')->firstOrFail();
        PresenceEvent::create([
            'card_id' => $this->cardOf('Carlos Pérez')->id,
            'reader_id' => $gate->id,
            'type' => 'ENTRY',
            'occurred_at' => Carbon::parse('2026-09-01 07:30:00'),
        ]);

        $result = $this->registry->execute('get_students_in_school', []);
        $byName = collect($result['inside'])->keyBy('name');

        $this->assertSame(1, $result['inside_count']);
        $this->assertSame('07:30', $byName['Carlos Pérez']['entry_at']);
        $this->assertFalse($byName->has('Maria González'));
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
        $this->assertNotContains('Ana Martínez', $names);
        $this->assertNotContains('Diego López', $names);
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
            'pae_enrolled' => false,
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

        // Out-of-scope student for time-in-school: hard error.
        $walled = $this->registry->execute(
            'get_student_time_in_school',
            ['student_id' => $outsider->id],
            $scope
        );
        $this->assertArrayHasKey('error', $walled);

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
