<?php

namespace Tests\Feature\Web;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Models\User;
use App\Services\PaeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-037 — the PAE reporting desk (ADR-056): /admin/reports/pae pages,
 * the aggregates behind them (daily/monthly, missed meals + trend,
 * flagged attempts, enrollment summary, per-student history), PDF and
 * CSV exports, and the role walls.
 *
 * Fixture: one deterministic school day (2026-09-01) mirroring the demo
 * scenario matrix — served meals, a duplicate, not-enrolled rejections,
 * a no-attendance rejection, an out-of-window attempt, and a missed
 * meal.
 */
class PaeReportsTest extends TestCase
{
    use RefreshDatabase;

    private PaeReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->travelTo(Carbon::parse('2026-09-01 12:00:00'));
        $this->reports = app(PaeReportService::class);

        // The fixture owns ALL events.
        PresenceEvent::query()->delete();

        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        $cafeteria = Reader::where('type', 'pae')->firstOrFail();

        $attend = function (string $name, string $time) use ($classroom): void {
            PresenceEvent::create([
                'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', $name))->firstOrFail()->id,
                'reader_id' => $classroom->id,
                'type' => 'CLASS_ATTENDANCE',
                'occurred_at' => Carbon::parse($time),
            ]);
        };
        $meal = function (string $name, string $type, string $time, bool $served, ?string $reason = null) use ($cafeteria): void {
            PresenceEvent::create([
                'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', $name))->firstOrFail()->id,
                'reader_id' => $cafeteria->id,
                'type' => $type,
                'occurred_at' => Carbon::parse($time),
                'served' => $served,
                'reason' => $reason,
            ]);
        };

        // Maria (both): breakfast + lunch served, duplicate lunch.
        $attend('Maria González', '2026-09-01 07:05:00');
        $meal('Maria González', 'PAE_BREAKFAST', '2026-09-01 07:20:00', true);
        $meal('Maria González', 'PAE_LUNCH', '2026-09-01 12:10:00', true);
        $meal('Maria González', 'PAE_LUNCH', '2026-09-01 12:40:00', false, 'duplicate');

        // Carlos (breakfast-only): breakfast served; lunch not-enrolled;
        // an out-of-window attempt in the afternoon.
        $attend('Carlos Pérez', '2026-09-01 07:10:00');
        $meal('Carlos Pérez', 'PAE_BREAKFAST', '2026-09-01 07:30:00', true);
        $meal('Carlos Pérez', 'PAE_LUNCH', '2026-09-01 12:15:00', false, 'not_enrolled');
        $meal('Carlos Pérez', 'PAE_ATTEMPT', '2026-09-01 15:30:00', false, 'out_of_window');

        // Ana (lunch-only): present, no lunch → MISSED lunch.
        $attend('Ana Martínez', '2026-09-01 07:15:00');

        // Lucía (both): absent; lunch attempt without attendance.
        $meal('Lucía Fernández', 'PAE_LUNCH', '2026-09-01 12:25:00', false, 'no_attendance');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function admin()
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    // ---- Service aggregates ------------------------------------------------

    #[Test]
    public function daily_meals_count_served_rows_only(): void
    {
        $daily = $this->reports->dailyMeals('2026-09-01');

        $this->assertSame(2, $daily['breakfast']); // Maria + Carlos; Carlos' flagged lunch never counts
        $this->assertSame(1, $daily['lunch']); // Maria; the duplicate never counts
        $this->assertSame(3, $daily['total']);
    }

    #[Test]
    public function missed_meals_distinguishes_each_condition(): void
    {
        $missed = $this->reports->missedMeals('lunch', '2026-09-01');
        $names = array_column($missed, 'name');

        // Ana: present + lunch-enrolled + not served → missed.
        $this->assertSame(['Ana Martínez'], $names);
        // Lucía absent → not "missed"; Carlos not lunch-enrolled → not missed;
        // Maria served → not missed.
        $this->assertNotContains('Lucía Fernández', $names);
        $this->assertNotContains('Carlos Pérez', $names);
        $this->assertNotContains('Maria González', $names);
    }

    #[Test]
    public function missed_meal_trend_reports_per_day(): void
    {
        $trend = $this->reports->missedMealTrend('lunch', 2);

        $this->assertCount(2, $trend);
        $this->assertSame(0, $trend[0]['missed']);
        $this->assertSame(1, $trend[1]['missed']);
    }

    #[Test]
    public function flagged_attempts_break_down_by_reason(): void
    {
        $flagged = $this->reports->flaggedAttempts('2026-09-01', '2026-09-01');

        $this->assertSame(4, $flagged['total']);
        $this->assertSame(1, $flagged['by_reason']['duplicate']);
        $this->assertSame(1, $flagged['by_reason']['not_enrolled']);
        $this->assertSame(1, $flagged['by_reason']['out_of_window']);
        $this->assertSame(1, $flagged['by_reason']['no_attendance']);
    }

    #[Test]
    public function enrollment_summary_counts_the_four_buckets(): void
    {
        $summary = $this->reports->enrollmentSummary();

        $this->assertSame(5, $summary['total_students']);
        $this->assertSame(3, $summary['breakfast']);
        $this->assertSame(3, $summary['lunch']);
        $this->assertSame(2, $summary['both']);
        $this->assertSame(1, $summary['breakfast_only']);
        $this->assertSame(1, $summary['lunch_only']);
        $this->assertSame(1, $summary['neither']);
    }

    #[Test]
    public function student_history_shows_days_slots_and_totals(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $history = $this->reports->studentHistory($maria, 1);

        $this->assertSame(1, $history['totals']['breakfasts']);
        $this->assertSame(1, $history['totals']['lunches']);
        $this->assertSame(1, $history['totals']['flagged']); // the duplicate attempt
        $this->assertSame('served', $history['days'][0]['breakfast']['status']);
        $this->assertSame('served', $history['days'][0]['lunch']['status']);
        $this->assertSame('12:10', $history['days'][0]['lunch']['time']);
    }

    #[Test]
    public function monthly_meals_sums_the_month(): void
    {
        $monthly = $this->reports->monthlyMeals('2026-09');

        $this->assertSame(2, $monthly['totals']['breakfast']);
        $this->assertSame(1, $monthly['totals']['lunch']);
        $this->assertSame(3, $monthly['totals']['total']);
    }

    // ---- Pages ---------------------------------------------------------------

    #[Test]
    public function the_reports_page_renders_for_admins_with_charts(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/reports/pae?date=2026-09-01&meal=lunch')
            ->assertOk()
            ->getContent();

        // Graphics: the SVG trend chart + the missed trend chart.
        $this->assertStringContainsString('<svg class="report-chart"', $html);
        $this->assertStringContainsString('bar-breakfast', $html);
        $this->assertStringContainsString('bar-lunch', $html);
        // The missed table names Ana.
        $this->assertStringContainsString('Ana Martínez', $html);
        // Flagged reasons render localized.
        $this->assertStringContainsString(__('api.pae_reason_duplicate'), $html);
        // Export buttons.
        $this->assertStringContainsString(__('app.export_pdf'), $html);
    }

    #[Test]
    public function the_reports_page_translates_to_spanish(): void
    {
        $this->actingAs($this->admin())->get('/locale/es');

        $html = $this->actingAs($this->admin())
            ->get('/admin/reports/pae?date=2026-09-01')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('app.reports_pae'), $html);
        $this->assertStringContainsString(__('api.pae_reason_out_of_window'), $html);
    }

    #[Test]
    public function the_per_student_report_page_renders(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get("/admin/reports/pae/student/{$maria->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Maria González', $html);
        $this->assertStringContainsString(__('app.report_stat_breakfasts'), $html);
        // The participation strip (the graphical history).
        $this->assertStringContainsString('report-participation', $html);
    }

    #[Test]
    public function reports_are_walled_off_from_teachers_students_and_guests(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->get('/admin/reports/pae')->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->get('/admin/reports/pae')->assertForbidden();

        auth()->logout();
        $this->get('/admin/reports/pae')->assertRedirect(route('login'));
    }

    // ---- Exports ---------------------------------------------------------------

    #[Test]
    public function csv_exports_stream_structured_rows(): void
    {
        $response = $this->actingAs($this->admin())
            ->get('/admin/reports/pae/export/csv?type=daily&date=2026-09-01')
            ->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('class,breakfast_students,lunch_students', $csv);
        $this->assertStringContainsString('TOTAL,2,1', $csv);
    }

    #[Test]
    public function the_missed_csv_names_the_missing(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get('/admin/reports/pae/export/csv?type=missed&date=2026-09-01&meal=lunch')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('student,class,attended_at,meal', $csv);
        $this->assertStringContainsString('Ana Martínez', $csv);
    }

    #[Test]
    public function the_student_csv_carries_per_day_rows(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $csv = $this->actingAs($this->admin())
            ->get("/admin/reports/pae/student/{$maria->id}/export/csv")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('date,breakfast,breakfast_status', $csv);
        $this->assertStringContainsString('2026-09-01,07:20,served', $csv);
    }

    #[Test]
    public function pdf_exports_stream_valid_pdf_documents(): void
    {
        foreach ([
            ['type' => 'daily', 'date' => '2026-09-01'],
            ['type' => 'monthly', 'month' => '2026-09'],
            ['type' => 'missed', 'date' => '2026-09-01', 'meal' => 'lunch'],
            ['type' => 'flagged', 'from' => '2026-09-01', 'to' => '2026-09-01'],
        ] as $query) {
            $response = $this->actingAs($this->admin())
                ->get('/admin/reports/pae/export/pdf?'.http_build_query($query))
                ->assertOk();

            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    #[Test]
    public function the_student_pdf_streams_a_valid_document(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->get("/admin/reports/pae/student/{$maria->id}/export/pdf")
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function exports_are_walled_off_from_teachers(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();

        $this->actingAs($teacher)->get('/admin/reports/pae/export/csv?type=daily')->assertForbidden();
        $this->actingAs($teacher)->get('/admin/reports/pae/export/pdf?type=daily')->assertForbidden();
    }

    // ---- Parent meal badges ------------------------------------------------

    #[Test]
    public function the_parent_timeline_renders_meal_badges_and_flagged_attempts(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get("/parent/students/{$maria->id}")
            ->assertOk()
            ->getContent();

        // Meal badges (localized labels), the served lunch chip, and the
        // flagged duplicate with its reason — distinct from real meals.
        $this->assertStringContainsString(__('app.pae_breakfast'), $html);
        $this->assertStringContainsString(__('app.pae_lunch'), $html);
        $this->assertStringContainsString(__('api.pae_reason_duplicate'), $html);
        $this->assertStringContainsString('is-flagged', $html);
    }
}
