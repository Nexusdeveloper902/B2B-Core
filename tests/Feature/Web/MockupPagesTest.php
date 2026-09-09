<?php

namespace Tests\Feature\Web;

use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-026 — the two mockup-driven pages (read-only views over existing
 * data): the student standings page and the admin EcoStation hub. Role
 * guards mirror the rest of the platform; content is ledger truth.
 */
#[RefreshDatabase]
class MockupPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();

        // One real classified deposit drives every number on both pages.
        PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader('recycling')->id,
            'type' => 'RECYCLING_DEPOSIT',
            'occurred_at' => now()->setTime(7, 15),
        ]);
        RecyclingDeposit::create([
            'event_id' => PresenceEvent::latest('id')->firstOrFail()->id,
            'material_class' => 'plastic',
            'confidence' => 0.99,
            'points_awarded' => 10,
            'is_bottle' => true,
            'is_recyclable' => true,
        ]);
        // ... and its ledger twin (classifyAndAward writes both in one
        // transaction in production — the board ranks from the LEDGER).
        PointsLedger::create([
            'student_id' => $this->cardOf('Maria González')->student_id,
            'delta' => 10,
            'reason' => 'recycling_deposit',
        ]);
    }

    #[Test]
    public function guests_are_redirected_from_both_pages(): void
    {
        $this->get('/student/leaderboard')->assertRedirect(route('login'));
        $this->get('/admin/ecostation')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_standings_page_renders_for_students_only(): void
    {
        // The DemoSeeder already mints 1:1 student accounts.
        $studentUser = User::where('role', 'student')->firstOrFail();

        $page = $this->actingAs($studentUser)->get('/student/leaderboard');
        $page->assertOk()
            ->assertSee(__('app.leaderboard_title'))
            ->assertSee(__('app.leaderboard_board'))
            ->assertSee(__('app.leaderboard_classes'))
            ->assertSee('10');             // ledger points

        // Staff roles do not belong to the student hub.
        $this->actingAs($this->user('teacher'))->get('/student/leaderboard')->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/student/leaderboard')->assertForbidden();
    }

    #[Test]
    public function the_ecostation_hub_renders_for_admins_only(): void
    {
        $page = $this->actingAs($this->user('admin'))->get('/admin/ecostation');
        $page->assertOk()
            ->assertSee(__('app.ecostation'))
            ->assertSee(__('app.ecostation_rates'))
            ->assertSee('plastic')
            ->assertSee('+10 PTS')  // the rate table's config truth
            ->assertSee(__('app.ecostation_ledger_note'));

        // UI pass 2026-09-09 — "Total awarded to students" is a LEDGER sum
        // (the same source as balances and the standings board), not a
        // deposits sum: a ledger row without a deposit twin (a redemption
        // offset, or history from before the deposits table) used to make
        // this hero contradict the students' own numbers.
        PointsLedger::create([
            'student_id' => $this->cardOf('Maria González')->student_id,
            'delta' => 5,
            'reason' => 'recycling_deposit',
        ]);
        $this->actingAs($this->user('admin'))->get('/admin/ecostation')
            // the hero element itself: ledger sum 15, not the deposits' 10
            // (the +15 METAL rate row must not satisfy this)
            ->assertSee('id="metric-points">15<', false);

        // Teachers keep the dashboards; the hub is an admin surface.
        $this->actingAs($this->user('teacher'))->get('/admin/ecostation')->assertForbidden();

        // Students neither.
        $studentUser = User::where('role', 'student')->firstOrFail();
        $this->actingAs($studentUser)->get('/admin/ecostation')->assertForbidden();
    }

    #[Test]
    public function the_ecostation_numbers_derive_from_the_deposit_ledger(): void
    {
        $html = $this->actingAs($this->user('admin'))->get('/admin/ecostation')->getContent();

        // 1 deposit · 10 points · 1 material — nothing invented.
        $this->assertStringContainsString(__('app.ecostation_items_foot'), $html);
        $this->assertStringContainsString(__('app.ecostation_confidence'), $html);
        $this->assertStringContainsString('99%', $html);
        $this->assertStringContainsString('10', $html);
    }

    private function user(string $role): User
    {
        return User::where('email', $role.'@presence.test')->firstOrFail();
    }
}
