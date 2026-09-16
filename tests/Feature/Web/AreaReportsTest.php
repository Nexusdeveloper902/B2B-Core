<?php

namespace Tests\Feature\Web;

use App\Models\Card;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use App\Services\Pdf\BrandedReportPdf;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The attendance + recycling reporting desks mirror the PAE desk:
 * general reports with charts, per-student reports, CSV + PDF
 * exports, admin-only walls — and every PDF wears the generating
 * school's colors and crest.
 *
 * Fixture: one deterministic school day (2026-09-02) — two on-time
 * attendances, one late arrival, one absence, one classified recycling
 * deposit with ledger points.
 */
class AreaReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        $this->travelTo(Carbon::parse('2026-09-02 12:00:00'));

        PresenceEvent::query()->delete();

        $classroom = Reader::where('type', 'classroom')->firstOrFail();
        $eco = Reader::where('type', 'recycling')->firstOrFail();

        $attend = function (string $name, string $time) use ($classroom): void {
            PresenceEvent::create([
                'card_id' => Card::whereHas('student', fn ($q) => $q->where('name', $name))->firstOrFail()->id,
                'reader_id' => $classroom->id,
                'type' => 'CLASS_ATTENDANCE',
                'occurred_at' => Carbon::parse($time),
            ]);
        };

        $attend('Maria González', '2026-09-02 07:05:00');
        $attend('Carlos Pérez', '2026-09-02 07:10:00');
        $attend('Ana Martínez', '2026-09-02 08:30:00'); // late (cutoff 08:15)
        // Diego López: absent. Lucía Fernández: absent.

        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $event = PresenceEvent::create([
            'card_id' => $maria->cards()->firstOrFail()->id,
            'reader_id' => $eco->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => Carbon::parse('2026-09-02 10:00:00'),
        ]);
        $points = (int) config('recycling.points.plastic', 0);
        RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => 'plastic',
            'confidence' => 0.9,
            'points_awarded' => $points,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);
        PointsLedger::create([
            'student_id' => $maria->id,
            'delta' => $points,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    // ---- Attendance pages --------------------------------------------------

    #[Test]
    public function the_attendance_report_page_renders_with_charts_and_lists(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/reports/attendance?date=2026-09-02')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg class="report-chart"', $html);
        $this->assertStringContainsString('5° B', $html);
        $this->assertStringContainsString('Ana Martínez', $html); // late list
        $this->assertStringContainsString('Diego López', $html); // absent list
        $this->assertStringContainsString(__('app.export_pdf'), $html);
    }

    #[Test]
    public function the_attendance_report_page_translates_to_spanish(): void
    {
        $this->actingAs($this->admin())->get('/locale/es');

        $html = $this->actingAs($this->admin())
            ->get('/admin/reports/attendance?date=2026-09-02')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('app.reports_attendance'), $html);
    }

    #[Test]
    public function the_per_student_attendance_report_page_renders(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get("/admin/reports/attendance/student/{$maria->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Maria González', $html);
        $this->assertStringContainsString('report-participation', $html);
    }

    #[Test]
    public function attendance_reports_are_walled_off_from_teachers_students_and_guests(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();
        $this->actingAs($teacher)->get('/admin/reports/attendance')->assertForbidden();

        $student = User::where('role', 'student')->firstOrFail();
        $this->actingAs($student)->get('/admin/reports/attendance')->assertForbidden();

        auth()->logout();
        $this->get('/admin/reports/attendance')->assertRedirect(route('login'));
    }

    // ---- Attendance exports --------------------------------------------------

    #[Test]
    public function attendance_csv_exports_stream_structured_rows(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get('/admin/reports/attendance/export/csv?type=daily&date=2026-09-02')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('class,enrolled,present,absent,rate_pct', $csv);
        $this->assertStringContainsString('5° B', $csv);
    }

    #[Test]
    public function attendance_absentees_csv_names_no_one_yet_but_stays_structured(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get('/admin/reports/attendance/export/csv?type=absentees')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('student,class,absences,school_days', $csv);
    }

    #[Test]
    public function attendance_pdf_exports_stream_valid_documents(): void
    {
        foreach ([
            ['type' => 'daily', 'date' => '2026-09-02'],
            ['type' => 'monthly', 'month' => '2026-09'],
            ['type' => 'absentees'],
        ] as $query) {
            $response = $this->actingAs($this->admin())
                ->get('/admin/reports/attendance/export/pdf?'.http_build_query($query))
                ->assertOk();

            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        $maria = Student::where('name', 'Maria González')->firstOrFail();
        $response = $this->actingAs($this->admin())
            ->get("/admin/reports/attendance/student/{$maria->id}/export/pdf")
            ->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ---- Recycling pages -----------------------------------------------------

    #[Test]
    public function the_recycling_report_page_renders_with_charts_mix_and_board(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/reports/recycling?date=2026-09-02')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg class="report-chart"', $html);
        $this->assertStringContainsString(__('app.material_plastic'), $html);
        $this->assertStringContainsString('Maria González', $html); // leaderboard
        $this->assertStringContainsString(__('app.export_pdf'), $html);
    }

    #[Test]
    public function the_per_student_recycling_report_page_renders(): void
    {
        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get("/admin/reports/recycling/student/{$maria->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Maria González', $html);
        $this->assertStringContainsString(__('app.material_plastic'), $html);
    }

    #[Test]
    public function recycling_reports_are_walled_off_from_teachers(): void
    {
        $teacher = User::where('email', 'teacher@presence.test')->firstOrFail();

        $this->actingAs($teacher)->get('/admin/reports/recycling')->assertForbidden();
        $this->actingAs($teacher)->get('/admin/reports/recycling/export/csv?type=daily')->assertForbidden();
        $this->actingAs($teacher)->get('/admin/reports/recycling/export/pdf?type=daily')->assertForbidden();
    }

    // ---- Recycling exports -----------------------------------------------------

    #[Test]
    public function recycling_csv_exports_stream_structured_rows(): void
    {
        $csv = $this->actingAs($this->admin())
            ->get('/admin/reports/recycling/export/csv?type=daily&date=2026-09-02')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('material,items,points,rate', $csv);
        $this->assertStringContainsString('plastic,1,', $csv);
    }

    #[Test]
    public function recycling_pdf_exports_stream_valid_documents(): void
    {
        foreach ([
            ['type' => 'daily', 'date' => '2026-09-02'],
            ['type' => 'monthly', 'month' => '2026-09'],
            ['type' => 'leaderboard'],
        ] as $query) {
            $response = $this->actingAs($this->admin())
                ->get('/admin/reports/recycling/export/pdf?'.http_build_query($query))
                ->assertOk();

            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    // ---- Branded PDFs ------------------------------------------------------------

    #[Test]
    public function pdfs_generated_under_a_school_wear_its_colors_crest_and_name(): void
    {
        $probe = new class extends BrandedReportPdf
        {
            public function headerFor(string $title, string $period): string
            {
                return $this->header($title, $period);
            }
        };

        // The demo admin belongs to IE Concejo de Sabaneta (the seeders
        // provision it), so its exports render branded.
        $this->actingAs($this->admin());
        $branded = $probe->headerFor('Report', '2026-09-02');

        $this->assertStringContainsString('#80193c', $branded);
        $this->assertStringContainsString('data:image/png;base64,', $branded);
        $this->assertStringContainsString('IE Concejo de Sabaneta', $branded);

        // An account with no school renders stock Pulse: ink band, no
        // school color, the product name alone. Created without scoping
        // so it does not inherit the school of the session above.
        $systemAdmin = app(CurrentSchool::class)->withoutScoping(
            fn () => User::factory()->create(['school_id' => null])
        );
        $this->actingAs($systemAdmin);
        $plain = $probe->headerFor('Report', '2026-09-02');

        $this->assertStringContainsString('#242423', $plain);
        $this->assertStringNotContainsString('#80193c', $plain);
        $this->assertStringContainsString('Pulse', $plain);
    }
}
