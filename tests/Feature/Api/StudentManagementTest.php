<?php

namespace Tests\Feature\Api;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-027 — student management through the GUI (POST /api/v1/admin/students
 * and the CSV bulk import): the end of hand-written SQL INSERTs for roster
 * onboarding. Covers the happy path, duplicate rejection, validation, the
 * role wall, the CSV contract (header, class-by-name, pae truthiness,
 * per-row errors) and the no-rows / bad-header / too-many-rows guards.
 */
class StudentManagementTest extends TestCase
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

    #[Test]
    public function an_admin_creates_a_student(): void
    {
        $class = SchoolClass::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'Nueva Estudiante',
                'grade' => '5°',
                'class_id' => $class->id,
                'pae_enrolled' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'student' => [
                    'name' => 'Nueva Estudiante',
                    'grade' => '5°',
                    'class_name' => $class->name,
                    'pae_enrolled' => true,
                ],
            ]);

        $this->assertDatabaseHas('students', [
            'name' => 'Nueva Estudiante',
            'class_id' => $class->id,
            'pae_enrolled' => true,
        ]);
    }

    #[Test]
    public function a_duplicate_name_in_the_same_class_is_rejected(): void
    {
        $class = SchoolClass::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'Maria González',
                'grade' => '5°',
                'class_id' => $class->id,
            ]);

        $response->assertStatus(422)
            ->assertJson(['status' => 'error', 'reason' => 'duplicate']);

        // Still exactly four seeded students — nothing was written.
        $this->assertSame(4, Student::count());
    }

    #[Test]
    public function validation_errors_are_structured(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => '',
                'grade' => '5°',
                'class_id' => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'class_id']);
    }

    #[Test]
    public function guests_never_reach_the_write_surface(): void
    {
        // Must run BEFORE any actingAs in this test (session stickiness).
        $payload = ['name' => 'X', 'grade' => '5°', 'class_id' => SchoolClass::firstOrFail()->id];

        $this->postJson('/api/v1/admin/students', $payload)
            ->assertUnauthorized();

        $csv = "name,grade,class\nAlguien,5°,5° B\n";
        $this->post('/api/v1/admin/students/import', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
        ])->assertUnauthorized();

        $this->assertSame(4, Student::count());
    }

    #[Test]
    public function teachers_never_reach_the_write_surface(): void
    {
        $payload = ['name' => 'X', 'grade' => '5°', 'class_id' => SchoolClass::firstOrFail()->id];

        $this->actingAs($this->teacher())
            ->postJson('/api/v1/admin/students', $payload)
            ->assertForbidden();

        $csv = "name,grade,class\nAlguien,5°,5° B\n";
        $this->actingAs($this->teacher())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ])->assertForbidden();

        $this->assertSame(4, Student::count());
    }

    #[Test]
    public function an_admin_imports_a_csv_roster(): void
    {
        $csv = "name,grade,class,pae_enrolled\n"
            ."Importada Uno,5°,5° B,yes\n"
            ."Importada Dos,5°,5° B,no\n"
            ."\n"
            ."Importada Tres,5°,5° b,si\n";

        $response = $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'created' => 3,
                'failed' => 0,
            ]);

        $this->assertDatabaseHas('students', ['name' => 'Importada Uno', 'pae_enrolled' => true]);
        $this->assertDatabaseHas('students', ['name' => 'Importada Dos', 'pae_enrolled' => false]);
        // "si" is a Spanish yes; the class name matched case-insensitively.
        $this->assertDatabaseHas('students', ['name' => 'Importada Tres', 'pae_enrolled' => true]);
    }

    #[Test]
    public function bad_rows_fail_per_row_without_blocking_the_good_ones(): void
    {
        $csv = "name,grade,class\n"
            ."Buena Fila,5°,5° B\n"
            .",5°,5° B\n"                              // missing name
            ."Clase Fantasma,5°,9° Z\n"                // unknown class
            ."Maria González,5°,5° B\n";               // duplicate of the seed

        $response = $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'partial',
                'created' => 1,
                'failed' => 3,
            ])
            ->assertJsonStructure([
                'errors' => [['row', 'message']],
            ]);

        $this->assertDatabaseHas('students', ['name' => 'Buena Fila']);
        $this->assertDatabaseMissing('students', ['name' => 'Clase Fantasma']);
    }

    #[Test]
    public function a_header_without_the_required_columns_is_rejected(): void
    {
        $csv = "nombre,grado,clase\nUna,5°,5° B\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ])
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);

        $this->assertSame(4, Student::count());
    }

    #[Test]
    public function a_csv_with_only_a_header_is_rejected(): void
    {
        $csv = "name,grade,class\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ])
            ->assertStatus(422)
            ->assertJson(['status' => 'error', 'created' => 0]);

        $this->assertSame(4, Student::count());
    }

    #[Test]
    public function the_import_route_rejects_guests(): void
    {
        // Before any actingAs (session stickiness).
        $csv = "name,grade,class\nAlguien,5°,5° B\n";
        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv');

        $this->post('/api/v1/admin/students/import', ['file' => $file])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_import_route_rejects_teachers(): void
    {
        $csv = "name,grade,class\nAlguien,5°,5° B\n";
        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv');

        $this->actingAs($this->teacher())
            ->post('/api/v1/admin/students/import', ['file' => $file])
            ->assertForbidden();
    }

    #[Test]
    public function a_non_csv_upload_is_rejected_by_validation(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->create('roster.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }
}
