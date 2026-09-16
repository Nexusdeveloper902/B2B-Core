<?php

namespace Tests\Feature\Seeders;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-045 (ADR-064/ADR-065) — the demo environment's shape.
 *
 * The realistic dataset must read as ONE coherent institution, and the
 * platform operator must live outside it. These pins are the automated
 * half of the §16 seeder validation (the manual half is `./run
 * seed-realistic` + `./run status`).
 */
class RealisticSeederOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL = 'IE Concejo de Sabaneta J.M.C.B';

    /** Every table that owns a `school_id` column. */
    private const OWNED_TABLES = [
        'classes', 'students', 'readers', 'rewards', 'events',
        'roster_updates', 'recycling_updates',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A small but complete semester: same code path, seconds not minutes.
        putenv('REALISTIC_STUDENTS=24');
        putenv('REALISTIC_MONTHS=1');

        $this->artisan('db:seed', ['--class' => 'Database\Seeders\RealisticSeeder', '--force' => true]);
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\SystemAdminSeeder', '--force' => true]);
    }

    protected function tearDown(): void
    {
        putenv('REALISTIC_STUDENTS');
        putenv('REALISTIC_MONTHS');

        parent::tearDown();
    }

    #[Test]
    public function there_is_exactly_one_realistic_school_organization(): void
    {
        $schools = School::all();

        $this->assertCount(1, $schools);
        $this->assertSame(self::SCHOOL, $schools->first()->name);
        $this->assertSame('ie-concejo-de-sabaneta', $schools->first()->slug);
        $this->assertSame('ie-concejo-de-sabaneta', $schools->first()->brand_key);
    }

    #[Test]
    public function every_organization_owned_row_belongs_to_that_school(): void
    {
        $schoolId = School::firstOrFail()->id;

        foreach (self::OWNED_TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} should not be empty");
            $this->assertSame(
                0,
                DB::table($table)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', '!=', $schoolId))->count(),
                "{$table} carries rows outside the school organization",
            );
        }
    }

    #[Test]
    public function derived_rows_hang_off_parents_inside_the_same_school(): void
    {
        $schoolId = School::firstOrFail()->id;

        // Each child table is joined back to its organization-owning
        // parent: a row whose parent is in another school (or gone)
        // would be an orphan the wall could not classify.
        $orphans = [
            'cards' => DB::table('cards')->leftJoin('students', 'students.id', '=', 'cards.student_id')
                ->where(fn ($q) => $q->whereNull('students.school_id')->orWhere('students.school_id', '!=', $schoolId))->count(),
            'points_ledger' => DB::table('points_ledger')->leftJoin('students', 'students.id', '=', 'points_ledger.student_id')
                ->where(fn ($q) => $q->whereNull('students.school_id')->orWhere('students.school_id', '!=', $schoolId))->count(),
            'recycling_deposits' => DB::table('recycling_deposits')->leftJoin('events', 'events.id', '=', 'recycling_deposits.event_id')
                ->where(fn ($q) => $q->whereNull('events.school_id')->orWhere('events.school_id', '!=', $schoolId))->count(),
            'reward_redemptions' => DB::table('reward_redemptions')->leftJoin('students', 'students.id', '=', 'reward_redemptions.student_id')
                ->where(fn ($q) => $q->whereNull('students.school_id')->orWhere('students.school_id', '!=', $schoolId))->count(),
            'pending_pairings' => DB::table('pending_pairings')->leftJoin('students', 'students.id', '=', 'pending_pairings.student_id')
                ->where(fn ($q) => $q->whereNull('students.school_id')->orWhere('students.school_id', '!=', $schoolId))->count(),
            'pending_captures' => DB::table('pending_captures')->leftJoin('readers', 'readers.id', '=', 'pending_captures.reader_id')
                ->where(fn ($q) => $q->whereNull('readers.school_id')->orWhere('readers.school_id', '!=', $schoolId))->count(),
        ];

        $this->assertSame(array_fill_keys(array_keys($orphans), 0), $orphans);
    }

    #[Test]
    public function every_event_agrees_with_its_own_readers_organization(): void
    {
        $mismatched = DB::table('events')
            ->join('readers', 'readers.id', '=', 'events.reader_id')
            ->whereColumn('events.school_id', '!=', 'readers.school_id')
            ->count();

        $this->assertSame(0, $mismatched);
    }

    #[Test]
    public function there_is_exactly_one_admin_and_it_lives_outside_the_school(): void
    {
        $admins = User::withoutGlobalScopes()->where('role', UserRole::Admin->value)->get();

        $this->assertCount(1, $admins, 'the realistic seeder must not mint administrators');
        $this->assertNull($admins->first()->school_id);
        $this->assertTrue($admins->first()->isSystemAdmin());
        $this->assertSame('admin@presence.test', $admins->first()->email);
    }

    #[Test]
    public function every_other_account_belongs_to_the_school(): void
    {
        $schoolId = School::firstOrFail()->id;

        $strays = User::withoutGlobalScopes()
            ->where('email', '!=', 'admin@presence.test')
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', '!=', $schoolId))
            ->pluck('email');

        $this->assertSame([], $strays->all());
    }

    #[Test]
    public function reseeding_the_system_admin_never_forks_the_operator_account(): void
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\SystemAdminSeeder', '--force' => true]);

        $this->assertSame(
            1,
            User::withoutGlobalScopes()->where('role', UserRole::Admin->value)->count(),
        );
    }

    #[Test]
    public function a_school_user_gets_the_school_branded_application(): void
    {
        $teacher = User::withoutGlobalScopes()->where('role', UserRole::Teacher->value)->firstOrFail();

        $html = $this->actingAs($teacher)->get('/teacher')->getContent();

        $this->assertStringContainsString('#80193c', $html);
        $this->assertStringContainsString('brand/schools/ie-concejo-de-sabaneta/crest-96.png', $html);
        $this->assertStringContainsString(self::SCHOOL, $html);
    }

    #[Test]
    public function the_system_admin_gets_the_stock_pulse_application_and_sees_the_school_data(): void
    {
        $admin = User::withoutGlobalScopes()->where('email', 'admin@presence.test')->firstOrFail();

        $html = $this->actingAs($admin)->get('/admin')->getContent();

        $this->assertStringNotContainsString('#80193c', $html);
        $this->assertStringNotContainsString('brand-theme', $html);
        $this->assertStringContainsString('brand/mark-96.png', $html);

        // Non-school shell, but full visibility: the operator can verify
        // (and administer) the seeded institution.
        $this->assertTrue(app(CurrentSchool::class)->isSystemWide());
        $this->assertGreaterThan(0, Student::count());
    }
}
