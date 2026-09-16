<?php

namespace Tests\Feature\Api;

use App\Enums\CardStatus;
use App\Enums\ReaderType;
use App\Enums\UserRole;
use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Reward;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Realtime\RealtimeFeed;
use App\Support\Tenancy\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-045 (ADR-064) — organization isolation is an AUTHORIZATION
 * property, not a UI one.
 *
 * Two complete schools are built side by side and every surface is
 * asked the hostile question: "what happens when a user of school A
 * simply types school B's id?" The answer must be a refusal from the
 * server, on every verb, without the frontend being involved.
 */
class OrganizationScopingTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    /** @var array<string, mixed> */
    private array $a;

    /** @var array<string, mixed> */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = School::factory()->ieConcejoDeSabaneta()->create();
        $this->beta = School::factory()->create(['name' => 'IE Otra Institución', 'slug' => 'ie-otra']);

        $this->a = $this->buildSchool($this->alpha, 'alpha');
        $this->b = $this->buildSchool($this->beta, 'beta');
    }

    /**
     * A whole small school: admin, teacher, class, student, card,
     * classroom reader and a reward — every root the wall protects.
     *
     * @return array<string, mixed>
     */
    private function buildSchool(School $school, string $tag): array
    {
        return app(CurrentSchool::class)->withoutScoping(function () use ($school, $tag): array {
            $admin = User::factory()->create([
                'role' => UserRole::Admin->value,
                'email' => "admin.{$tag}@presence.test",
                'school_id' => $school->id,
            ]);
            $teacher = User::factory()->create([
                'role' => UserRole::Teacher->value,
                'email' => "teacher.{$tag}@presence.test",
                'school_id' => $school->id,
            ]);
            $class = SchoolClass::create(['name' => "5° {$tag}", 'teacher_user_id' => $teacher->id, 'school_id' => $school->id]);
            $student = Student::create([
                'name' => "Estudiante {$tag}", 'grade' => '5°', 'class_id' => $class->id, 'school_id' => $school->id,
            ]);
            $card = Card::create([
                'credential_uid' => strtoupper(Str::random(8)), 'student_id' => $student->id, 'status' => CardStatus::Active->value,
            ]);
            $reader = Reader::create([
                'label' => "Aula {$tag}", 'type' => ReaderType::Classroom->value,
                'active_event_type' => 'CLASS_ATTENDANCE', 'api_key' => Str::random(32), 'school_id' => $school->id,
            ]);
            $reward = Reward::create([
                'name' => "Premio {$tag}", 'point_cost' => 10, 'active' => true, 'school_id' => $school->id,
            ]);

            return compact('admin', 'teacher', 'class', 'student', 'card', 'reader', 'reward');
        });
    }

    // ----------------------------------------------------------------
    // Reads: index / show
    // ----------------------------------------------------------------

    #[Test]
    public function list_queries_return_only_the_callers_own_organization(): void
    {
        $this->actingAs($this->a['admin']);

        $this->assertSame([$this->a['student']->id], Student::pluck('id')->all());
        $this->assertSame([$this->a['class']->id], SchoolClass::pluck('id')->all());
        $this->assertSame([$this->a['reader']->id], Reader::pluck('id')->all());
        $this->assertSame([$this->a['reward']->id], Reward::pluck('id')->all());
        // Child tables inherit the wall through their parent.
        $this->assertSame([$this->a['card']->id], Card::pluck('id')->all());
    }

    #[Test]
    public function the_students_desk_never_renders_another_organizations_roster(): void
    {
        $html = $this->actingAs($this->a['admin'])->get('/admin/students')->getContent();

        $this->assertStringContainsString('Estudiante alpha', $html);
        $this->assertStringNotContainsString('Estudiante beta', $html);
    }

    #[Test]
    public function search_cannot_reach_across_organizations(): void
    {
        $html = $this->actingAs($this->a['admin'])->get('/admin/students?q=Estudiante')->getContent();

        $this->assertStringNotContainsString('Estudiante beta', $html);
    }

    #[Test]
    public function the_readers_and_staff_desks_stay_inside_the_organization(): void
    {
        $readers = $this->actingAs($this->a['admin'])->get('/admin/readers')->getContent();
        $this->assertStringContainsString('Aula alpha', $readers);
        $this->assertStringNotContainsString('Aula beta', $readers);

        $staff = $this->actingAs($this->a['admin'])->get('/admin/staff')->getContent();
        $this->assertStringContainsString('teacher.alpha@presence.test', $staff);
        $this->assertStringNotContainsString('teacher.beta@presence.test', $staff);
    }

    #[Test]
    public function a_foreign_id_in_the_url_is_refused_not_merely_hidden(): void
    {
        $this->actingAs($this->a['admin']);

        // Route-model binding resolves through the scope, so the row is
        // not found at all — a refusal, and one that does not confirm
        // the row exists elsewhere.
        $this->get('/parent/students/'.$this->b['student']->id)->assertNotFound();
        $this->get('/admin/reports/pae/student/'.$this->b['student']->id)->assertNotFound();
        $this->get('/admin/reports/pae/student/'.$this->b['student']->id.'/export/csv')->assertNotFound();
    }

    // ----------------------------------------------------------------
    // Writes: create / update / delete
    // ----------------------------------------------------------------

    #[Test]
    public function creating_a_resource_inherits_the_callers_organization(): void
    {
        $response = $this->actingAs($this->a['admin'])->postJson('/api/v1/admin/students', [
            'name' => 'Nueva Estudiante',
            'grade' => '5°',
            'class_id' => $this->a['class']->id,
        ]);

        $response->assertOk();

        $created = app(CurrentSchool::class)->withoutScoping(
            fn () => Student::withoutGlobalScopes()->where('name', 'Nueva Estudiante')->firstOrFail(),
        );

        // The client never sent an organization — the server derived it.
        $this->assertSame($this->alpha->id, $created->school_id);
        // And so did the login minted alongside it.
        $this->assertSame($this->alpha->id, $created->account?->school_id);
    }

    #[Test]
    public function a_client_cannot_place_a_new_resource_in_another_organization(): void
    {
        $response = $this->actingAs($this->a['admin'])->postJson('/api/v1/admin/students', [
            'name' => 'Intruso',
            'grade' => '5°',
            // Another school's class id: this must be INVALID, not merely
            // ineffective — otherwise the row would land unparented.
            'class_id' => $this->b['class']->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('class_id');
        $this->assertDatabaseMissing('students', ['name' => 'Intruso']);
    }

    #[Test]
    public function a_reader_created_by_a_school_admin_belongs_to_that_school(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/admin/readers', [
            'label' => 'Aula nueva',
            'type' => ReaderType::Classroom->value,
            'active_event_type' => 'CLASS_ATTENDANCE',
        ])->assertOk();

        $reader = app(CurrentSchool::class)->withoutScoping(
            fn () => Reader::withoutGlobalScopes()->where('label', 'Aula nueva')->firstOrFail(),
        );

        $this->assertSame($this->alpha->id, $reader->school_id);
    }

    #[Test]
    public function a_class_created_by_a_school_admin_belongs_to_that_school(): void
    {
        $this->actingAs($this->a['admin'])
            ->postJson('/api/v1/admin/classes', ['name' => '6° nuevo'])
            ->assertOk();

        $class = app(CurrentSchool::class)->withoutScoping(
            fn () => SchoolClass::withoutGlobalScopes()->where('name', '6° nuevo')->firstOrFail(),
        );

        $this->assertSame($this->alpha->id, $class->school_id);
    }

    #[Test]
    public function a_staff_account_created_by_a_school_admin_joins_that_school(): void
    {
        $this->actingAs($this->a['admin'])->postJson('/api/v1/admin/staff', [
            'name' => 'Nueva Profesora',
            'email' => 'nueva.profesora@presence.test',
            'role' => UserRole::Teacher->value,
            'password' => 'temporal-01',
            'password_confirmation' => 'temporal-01',
            // A school admin naming another organization is IGNORED, not obeyed.
            'school_id' => $this->beta->id,
        ])->assertOk();

        $created = User::withoutGlobalScopes()->where('email', 'nueva.profesora@presence.test')->firstOrFail();

        $this->assertSame($this->alpha->id, $created->school_id);
    }

    #[Test]
    public function updates_and_deletes_cannot_cross_the_organization_wall(): void
    {
        $this->actingAs($this->a['admin']);

        $this->putJson('/api/v1/admin/readers/'.$this->b['reader']->id, [
            'label' => 'Secuestrado',
            'active_event_type' => 'CLASS_ATTENDANCE',
        ])->assertNotFound();

        $this->deleteJson('/api/v1/admin/readers/'.$this->b['reader']->id)->assertNotFound();
        $this->postJson('/api/v1/admin/readers/'.$this->b['reader']->id.'/rotate-key')->assertNotFound();
        $this->deleteJson('/api/v1/admin/cards/'.$this->b['card']->id)->assertNotFound();
        $this->postJson('/api/v1/admin/students/'.$this->b['student']->id.'/arm-pairing')->assertNotFound();

        $this->assertDatabaseHas('readers', ['id' => $this->b['reader']->id, 'label' => 'Aula beta']);
        $this->assertDatabaseHas('cards', ['id' => $this->b['card']->id]);
    }

    #[Test]
    public function redemption_cannot_spend_another_organizations_catalog_or_student(): void
    {
        $this->actingAs($this->a['admin']);

        $this->postJson('/api/v1/students/'.$this->b['student']->id.'/redeem', [
            'reward_id' => $this->b['reward']->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/students/'.$this->a['student']->id.'/redeem', [
            'reward_id' => $this->b['reward']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('reward_id');
    }

    // ----------------------------------------------------------------
    // Devices
    // ----------------------------------------------------------------

    #[Test]
    public function a_reader_cannot_tap_a_card_from_another_organization(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->a['reader']->api_key])
            ->postJson('/api/v1/events/tap', ['credential_uid' => $this->b['card']->credential_uid]);

        // The card is simply not recognized on this school's reader —
        // the same answer an unknown card gets, with no hint that it is
        // enrolled somewhere else.
        $response->assertStatus(404)->assertJsonPath('reason', 'not_found');
        $this->assertSame(0, PresenceEvent::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_tap_event_inherits_the_readers_organization(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->a['reader']->api_key])
            ->postJson('/api/v1/events/tap', ['credential_uid' => $this->a['card']->credential_uid])
            ->assertOk();

        $event = PresenceEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($this->alpha->id, $event->school_id);
    }

    // ----------------------------------------------------------------
    // Derived surfaces
    // ----------------------------------------------------------------

    #[Test]
    public function the_leaderboard_ranks_inside_one_organization_only(): void
    {
        $board = $this->actingAs($this->a['admin'])
            ->getJson('/api/v1/recycling/leaderboard')
            ->assertOk()
            ->json('entries');

        $names = array_column($board, 'student_name');

        $this->assertContains('Estudiante alpha', $names);
        $this->assertNotContains('Estudiante beta', $names);
    }

    #[Test]
    public function the_realtime_feed_is_walled_per_organization(): void
    {
        foreach ([[$this->a, $this->alpha], [$this->b, $this->beta]] as [$school, $row]) {
            $this->withHeaders(['Authorization' => 'Bearer '.$school['reader']->api_key])
                ->postJson('/api/v1/events/tap', ['credential_uid' => $school['card']->credential_uid])
                ->assertOk();
        }

        $this->actingAs($this->a['admin']);
        $rows = app(RealtimeFeed::class)->recent(50);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($this->alpha->id, $row['school_id']);
            $this->assertSame('Estudiante alpha', $row['student_name']);
        }
    }

    // ----------------------------------------------------------------
    // The one deliberate cross-organization capability
    // ----------------------------------------------------------------

    #[Test]
    public function the_system_administrator_sees_every_organization(): void
    {
        $systemAdmin = app(CurrentSchool::class)->withoutScoping(fn () => User::factory()->create([
            'role' => UserRole::Admin->value,
            'email' => 'system@presence.test',
            'school_id' => null,
        ]));

        $this->assertTrue($systemAdmin->isSystemAdmin());

        $this->actingAs($systemAdmin);

        $this->assertEqualsCanonicalizing(
            [$this->a['student']->id, $this->b['student']->id],
            Student::pluck('id')->all(),
        );
        $this->get('/parent/students/'.$this->b['student']->id)->assertOk();
    }

    #[Test]
    public function the_system_administrator_may_place_a_new_account_in_a_named_school(): void
    {
        $systemAdmin = app(CurrentSchool::class)->withoutScoping(fn () => User::factory()->create([
            'role' => UserRole::Admin->value,
            'email' => 'system@presence.test',
            'school_id' => null,
        ]));

        $this->actingAs($systemAdmin)->postJson('/api/v1/admin/staff', [
            'name' => 'Rector Beta',
            'email' => 'rector.beta@presence.test',
            'role' => UserRole::Admin->value,
            'password' => 'temporal-01',
            'password_confirmation' => 'temporal-01',
            'school_id' => $this->beta->id,
        ])->assertOk();

        $created = User::withoutGlobalScopes()->where('email', 'rector.beta@presence.test')->firstOrFail();

        $this->assertSame($this->beta->id, $created->school_id);
        $this->assertFalse($created->isSystemAdmin(), 'a school admin is not a system admin');
    }

    #[Test]
    public function a_teacher_without_a_school_never_falls_open_to_the_whole_database(): void
    {
        // users.school_id is nullable by design; a NON-admin with no
        // organization must see the unassigned data set, never everything
        // (the StudentScope fail-closed rule, applied to organizations).
        $orphan = app(CurrentSchool::class)->withoutScoping(fn () => User::factory()->create([
            'role' => UserRole::Teacher->value,
            'email' => 'orphan@presence.test',
            'school_id' => null,
        ]));

        $this->actingAs($orphan);

        $this->assertSame([], Student::pluck('id')->all());
        $this->assertFalse(app(CurrentSchool::class)->isSystemWide());
    }
}
