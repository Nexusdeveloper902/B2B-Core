<?php

namespace Tests\Feature\Web;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-029 — the realtime passover + GUI completion, pinned at the
 * VIEW layer: every page that shows mutable state must boot the
 * realtime client and carry the hooks its live handler needs; the
 * students desk's grade SELECT and class-creation form replace the
 * type-the-degree-sign chore and the hand-written-SQL workflow; the
 * design-system pagination replaces the stock Tailwind pager.
 *
 * (Needles containing quotes/JSP syntax use assertSee(…, false) —
 * the escaped default would never match the literal attribute.)
 */
class RealtimePassoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@presence.test')->firstOrFail();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@presence.test')->firstOrFail();
    }

    private function studentUser(): User
    {
        return User::where('role', 'student')->firstOrFail();
    }

    #[Test]
    public function the_students_desk_has_a_grade_select_class_form_and_realtime_boot(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/students');

        $page->assertOk()
            // The grade SELECT (no more typing "5°" with the degree sign)
            ->assertSee('id="student-grade"', false)
            ->assertSee('<option value="1°">1°</option>', false)
            ->assertSee('>5° A</option>', false)
            ->assertSee('>11° B</option>', false)
            ->assertSee('<option value="11°">11°</option>', false)
            ->assertDontSee('<option value="0°">', false)
            // The class creation form (with the optional teacher select)
            ->assertSee('id="class-create-form"', false)
            ->assertSee('id="class-name"', false)
            ->assertSee('id="class-teacher"', false)
            ->assertSee(__('app.create_class'))
            // The realtime boot + the live roster contract
            ->assertSee('id="students-realtime"', false)
            ->assertSee('data-realtime', false)
            ->assertSee('js/realtime.js')
            ->assertSee('id="roster-body"', false)
            ->assertSee('data-student-row', false)
            ->assertSee('realtime:roster')
            // The styled file input (was the last bare form control)
            ->assertSee('class="file-row"', false)
            // The class API the form calls
            ->assertSee('/api/v1/admin/classes');
    }

    #[Test]
    public function the_readers_desk_boots_realtime_and_follows_reader_updates(): void
    {
        $page = $this->actingAs($this->admin())->get('/admin/readers');

        $page->assertOk()
            ->assertSee('id="readers-realtime"', false)
            ->assertSee('js/realtime.js')
            ->assertSee('reader_updated')
            ->assertSee('realtime:roster');
    }

    #[Test]
    public function the_teacher_dashboard_class_panels_carry_live_summary_hooks(): void
    {
        $page = $this->actingAs($this->teacher())->get('/teacher');

        $page->assertOk()
            ->assertSee('data-class-panel', false)
            ->assertSee('data-count', false)
            ->assertSee('data-stat="present"', false)
            ->assertSee('data-stat="absent"', false)
            ->assertSee('data-label-absent', false)
            // the chips/KPI bump logic rides the tap frames that already
            // arrive for this role (scoped to the teacher's classes)
            ->assertSee('bumpChips(')
            ->assertSee('bumpStat(');
    }

    #[Test]
    public function the_admin_dashboard_kpi_strip_goes_live_without_a_readers_table(): void
    {
        // TASK-035 — the readers table left the admin dashboard (the
        // /admin/readers desk is the readers surface now): KPIs stay
        // live, no reader rows/mode forms ride this page anymore.
        $page = $this->actingAs($this->admin())->get('/admin');

        $page->assertOk()
            ->assertSee('data-stat="attendance"', false)
            ->assertSee('data-stat="pae_breakfast"', false)
            ->assertSee('data-stat="pae_lunch"', false)
            ->assertSee('data-stat="recycling_items"', false)
            ->assertSee('data-stat="recycling_points"', false)
            ->assertDontSee('data-reader-row', false)
            ->assertDontSee('mode-form', false)
            // the PAE/attendance taps are distinct-student counts — the
            // JS must keep per-student seen sets, not raw ++ counters
            ->assertSee('seen.attendance')
            ->assertSee('realtime:recycling');
    }

    #[Test]
    public function the_parent_timeline_prepends_this_students_live_taps(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        $page = $this->actingAs($this->admin())->get(route('parent.timeline', $student));

        $page->assertOk()
            ->assertSee('id="timeline-realtime"', false)
            ->assertSee('js/realtime.js')
            ->assertSee('data-student-live', false)
            ->assertSee('realtime:tap')
            // the live row is idempotent (replayed frames never duplicate)
            ->assertSee('data-live-id', false);
    }

    #[Test]
    public function the_student_history_ledger_and_balance_go_live(): void
    {
        $page = $this->actingAs($this->studentUser())->get('/student/history');

        $page->assertOk()
            ->assertSee('id="history-realtime"', false)
            ->assertSee('js/realtime.js')
            ->assertSee('data-ledger-body', false)
            ->assertSee('data-student-live', false)
            ->assertSee('realtime:recycling')
            ->assertSee('points_awarded');
    }

    #[Test]
    public function the_student_leaderboard_re_sorts_from_committed_frames(): void
    {
        $page = $this->actingAs($this->studentUser())->get('/student/leaderboard');

        $page->assertOk()
            ->assertSee('id="leaderboard-realtime"', false)
            ->assertSee('js/realtime.js')
            ->assertSee('data-board-student', false)
            ->assertSee('data-podium-student', false)
            ->assertSee('data-stat="rank"', false)
            ->assertSee('data-stat="balance"', false)
            ->assertSee('realtime:recycling');
    }

    #[Test]
    public function the_student_balance_listener_reads_the_real_frame_shape(): void
    {
        // TASK-029 regression: the inline listener read frame.payload,
        // but the server sends {type:'recycling', update:{payload}} —
        // the balance NEVER went live. Pin the fixed shape.
        $html = $this->actingAs($this->studentUser())->get('/student')->getContent();

        $this->assertStringContainsString('frame.update.payload', $html);
        $this->assertStringContainsString('data-stat="balance"', $html);
        $this->assertStringNotContainsString('var p = frame.payload', $html);
    }

    #[Test]
    public function pagination_renders_in_the_design_system_not_stock_tailwind(): void
    {
        // 30 students → the students desk paginates (page size 25).
        $class = SchoolClass::firstOrFail();
        foreach (range(1, 30) as $i) {
            Student::create([
                'name' => 'Pager Student '.$i,
                'grade' => '5°',
                'class_id' => $class->id,
                'pae_enrolled' => false,
            ]);
        }

        $page = $this->actingAs($this->admin())->get('/admin/students');

        $page->assertOk()
            ->assertSee('class="pagination"', false)
            ->assertSee('aria-label="'.__('app.pagination_nav').'"', false);

        $html = $page->getContent();
        $this->assertStringNotContainsString('class="flex', $html, 'the stock Tailwind pager classes must never render');
    }
}
