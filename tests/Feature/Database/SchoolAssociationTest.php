<?php

namespace Tests\Feature\Database;

use App\Models\Reader;
use App\Models\Reward;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-045 (ADR-064) — the school association at the schema level.
 *
 * The load-bearing promise of this migration is that it is ADDITIVE:
 * every account and every row that existed before it keeps working with
 * no school at all, because NULL is a real, supported value and not a
 * half-migrated state.
 */
class SchoolAssociationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_school_table_exists_with_its_branding_pointer(): void
    {
        $this->assertTrue(Schema::hasTable('schools'));
        $this->assertTrue(Schema::hasColumns('schools', ['id', 'name', 'slug', 'brand_key']));
    }

    #[Test]
    public function every_organization_owned_table_carries_the_association(): void
    {
        foreach (['users', 'classes', 'students', 'readers', 'rewards', 'events', 'roster_updates', 'recycling_updates'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'school_id'),
                "{$table} must carry the organization association",
            );
        }
    }

    #[Test]
    public function an_account_can_be_associated_with_a_school(): void
    {
        $school = School::provision('IE Concejo de Sabaneta J.M.C.B', 'ie-concejo-de-sabaneta', 'ie-concejo-de-sabaneta');

        $user = User::factory()->create(['school_id' => $school->id]);

        $this->assertSame($school->id, $user->fresh()->school_id);
        $this->assertSame('IE Concejo de Sabaneta J.M.C.B', $user->school->name);
        $this->assertTrue($school->users->contains($user));
    }

    #[Test]
    public function provisioning_a_school_twice_returns_the_same_organization(): void
    {
        $first = School::provision('IE Concejo de Sabaneta J.M.C.B', 'ie-concejo-de-sabaneta', 'ie-concejo-de-sabaneta');
        $second = School::provision('IE Concejo de Sabaneta J.M.C.B', 'ie-concejo-de-sabaneta', 'ie-concejo-de-sabaneta');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, School::count());
    }

    #[Test]
    public function pre_existing_rows_without_a_school_stay_valid_and_usable(): void
    {
        // Exactly the shape the demo fixture (and every pre-feature
        // install) has: no schools table entries at all.
        $this->seedDemo();

        $this->assertSame(0, School::count());
        $this->assertGreaterThan(0, Student::count());

        $admin = User::where('email', 'admin@presence.test')->firstOrFail();
        $this->assertNull($admin->school_id);
        $this->assertNull($admin->school);

        // And the application still works for them, end to end.
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/students')->assertOk();
        $this->actingAs($admin)->get('/admin/readers')->assertOk();
    }

    #[Test]
    public function a_school_deletion_never_cascades_into_its_rows(): void
    {
        // nullOnDelete by design: losing the organization record must not
        // destroy a school's roster or its history.
        $school = School::provision('IE Temporal', 'ie-temporal');

        $student = app(CurrentSchool::class)->withoutScoping(fn () => Student::create([
            'name' => 'Persistente', 'grade' => '5°', 'school_id' => $school->id,
        ]));

        $school->delete();

        $this->assertNull($student->fresh()->school_id);
        $this->assertDatabaseHas('students', ['name' => 'Persistente']);
    }

    #[Test]
    public function the_organization_relations_resolve_from_the_school_side(): void
    {
        $school = School::provision('IE Relaciones', 'ie-relaciones');

        app(CurrentSchool::class)->actAs($school, function () use ($school): void {
            SchoolClass::create(['name' => '1° A']);
            Student::create(['name' => 'Alumna', 'grade' => '1°']);
            Reader::create(['label' => 'Aula 1', 'type' => 'classroom', 'active_event_type' => 'CLASS_ATTENDANCE', 'api_key' => 'k-relaciones-1']);
            Reward::create(['name' => 'Premio', 'point_cost' => 5]);

            $this->assertCount(1, $school->classes);
            $this->assertCount(1, $school->students);
            $this->assertCount(1, $school->readers);
            $this->assertCount(1, $school->rewards);
        });
    }
}
