<?php

namespace Tests\Feature\Api;

use App\Models\RosterUpdate;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030-A (ADR-044) — enrollment mints the login. Single create, CSV
 * import and the backfill endpoint all provision the 1:1 student account
 * (convention email + shared initial password + forced rotation) inside
 * the same transaction as the student row; failures and duplicates mint
 * nothing. The temporary password is display-once: it appears in the
 * minting HTTP response and nowhere else (never in roster frames, never
 * re-issued for existing accounts).
 */
class StudentAccountProvisioningTest extends TestCase
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
    public function creating_a_student_provisions_its_login(): void
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
                'account' => [
                    'email' => 'nueva@presence.test',
                    'temporary_password' => 'password',
                    'must_change_password' => true,
                ],
            ])
            ->assertJsonPath('student.account_email', 'nueva@presence.test');

        $this->assertStringContainsString('nueva@presence.test', (string) $response->json('account_notice'));

        $student = Student::where('name', 'Nueva Estudiante')->firstOrFail();
        $this->assertDatabaseHas('users', [
            'email' => 'nueva@presence.test',
            'role' => 'student',
            'student_id' => $student->id,
            'must_change_password' => true,
        ]);
        $this->assertTrue(Hash::check('password', $student->account->password));
    }

    #[Test]
    public function the_minting_response_carries_no_secret_material_besides_the_display_once_pair(): void
    {
        $class = SchoolClass::firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'Limpia Registro',
                'grade' => '5°',
                'class_id' => $class->id,
            ]);

        $response->assertOk();

        $account = $response->json('account');
        $this->assertSame(['email', 'temporary_password', 'must_change_password'], array_keys($account));
    }

    #[Test]
    public function same_first_name_in_another_class_disambiguates(): void
    {
        $other = SchoolClass::create(['name' => '5° A']);

        // maria@presence.test already belongs to the seeded Maria González.
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'María López',
                'grade' => '5°',
                'class_id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('account.email', 'maria2@presence.test');

        $this->assertDatabaseHas('users', ['email' => 'maria2@presence.test']);
    }

    #[Test]
    public function a_duplicate_create_mints_no_account(): void
    {
        $usersBefore = User::count();
        $class = SchoolClass::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'Maria González',
                'grade' => '5°',
                'class_id' => $class->id,
            ])
            ->assertStatus(422);

        $this->assertSame($usersBefore, User::count());
    }

    #[Test]
    public function importing_a_roster_provisions_every_login(): void
    {
        $csv = "name,grade,class,pae_enrolled\n"
            ."Importada Uno,5°,5° B,yes\n"
            ."Importada Dos,5°,5° B,no\n";

        $response = $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'created' => 2,
                'accounts_created' => 2,
            ])
            // Same first name: the convention disambiguates for free.
            ->assertJsonPath('students.0.account_email', 'importada@presence.test')
            ->assertJsonPath('students.1.account_email', 'importada2@presence.test');

        $this->assertDatabaseHas('users', ['email' => 'importada@presence.test', 'must_change_password' => true]);
        $this->assertDatabaseHas('users', ['email' => 'importada2@presence.test', 'must_change_password' => true]);
    }

    #[Test]
    public function failed_import_rows_mint_no_accounts(): void
    {
        $usersBefore = User::count();

        $csv = "name,grade,class\n"
            ."Buena Fila,5°,5° B\n"
            .",5°,5° B\n"
            ."Clase Fantasma,5°,9° Z\n";

        $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ])
            ->assertOk()
            ->assertJson(['status' => 'partial', 'created' => 1, 'accounts_created' => 1]);

        // Exactly one new user: the good row's. Ghost rows leave nothing.
        $this->assertSame($usersBefore + 1, User::count());
        $this->assertDatabaseHas('users', ['email' => 'buena@presence.test']);
    }

    #[Test]
    public function roster_frames_carry_the_email_but_never_the_temporary_password(): void
    {
        $class = SchoolClass::firstOrFail();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/students', [
                'name' => 'Marco Visible',
                'grade' => '5°',
                'class_id' => $class->id,
            ])
            ->assertOk();

        $frame = RosterUpdate::where('type', 'student_created')->latest('id')->firstOrFail();
        $this->assertSame('marco@presence.test', $frame->payload['account_email']);
        $this->assertArrayNotHasKey('temporary_password', $frame->payload);
        $this->assertStringNotContainsString('temporary_password', json_encode($frame->payload));
    }

    #[Test]
    public function the_name_class_uniqueness_is_owned_by_the_database(): void
    {
        // Finding 3.2: the advisory pre-check can lose a race — the
        // invariant below it must hold regardless of application code.
        $class = SchoolClass::firstOrFail();

        $this->expectException(QueryException::class);

        Student::create([
            'name' => 'Maria González',
            'grade' => '5°',
            'class_id' => $class->id,
            'pae_enrolled' => false,
        ]);
    }

    #[Test]
    public function rows_past_the_import_cap_are_counted_never_silently_dropped(): void
    {
        // Finding 3.3: a 505-row file creates 500 and reports
        // failed=5 (not failed=1) — failed is the true shortfall.
        $rows = [];
        for ($i = 1; $i <= 505; $i++) {
            $rows[] = "Exceso {$i},5°,5° B,yes";
        }
        $csv = "name,grade,class,pae_enrolled\n".implode("\n", $rows)."\n";

        $response = $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', $csv, 'text/csv'),
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'partial',
                'created' => 500,
                'failed' => 5,
                'accounts_created' => 500,
            ]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertSame(500, User::where('role', 'student')->where('email', 'like', 'exceso%')->count());
    }

    #[Test]
    public function a_mass_same_name_import_allocates_every_suffix(): void
    {
        // Finding 1.2: sixty identical first names stay linear (one
        // pre-read) and every account lands unique and flagged.
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = "Maria Gemela {$i},5°,5° B,yes";
        }
        $csv = "name,grade,class,pae_enrolled\n".implode("\n", $rows)."\n";

        // Same name AND same class would be duplicates — spread the
        // twins across two classes to isolate the EMAIL allocator.
        $other = SchoolClass::create(['name' => '5° A']);
        $lines = explode("\n", trim($csv));
        $header = array_shift($lines);
        $mixed = [$header];
        foreach ($lines as $i => $line) {
            $class = $i % 2 === 0 ? '5° B' : '5° A';
            $mixed[] = preg_replace('/,5° B,/', ",{$class},", $line);
        }

        $response = $this->actingAs($this->admin())
            ->post('/api/v1/admin/students/import', [
                'file' => UploadedFile::fake()->createWithContent('roster.csv', implode("\n", $mixed)."\n", 'text/csv'),
            ]);

        $response->assertOk()
            ->assertJson(['status' => 'ok', 'created' => 60, 'accounts_created' => 60]);

        $emails = collect($response->json('students'))->pluck('account_email');
        $this->assertSame(60, $emails->unique()->count());
        $this->assertTrue($emails->every(fn ($e) => str_ends_with((string) $e, '@presence.test')));
        $this->assertSame(60, User::where('must_change_password', true)->count());
    }

    #[Test]
    public function the_backfill_endpoint_provisions_a_pre_feature_row(): void
    {
        // A row with no account — exactly what pre-TASK-030 rows look like.
        $legacy = Student::create([
            'name' => 'Legado Antiguo',
            'grade' => '5°',
            'class_id' => SchoolClass::firstOrFail()->id,
            'pae_enrolled' => false,
        ]);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/students/{$legacy->id}/account");

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'already' => false,
                'account' => [
                    'email' => 'legado@presence.test',
                    'temporary_password' => 'password',
                    'must_change_password' => true,
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'legado@presence.test',
            'student_id' => $legacy->id,
        ]);
    }

    #[Test]
    public function the_backfill_endpoint_is_idempotent_and_never_reissues_the_password(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/students/{$student->id}/account");

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'already' => true,
                'account' => ['email' => 'maria@presence.test'],
            ]);

        $this->assertArrayNotHasKey('temporary_password', $response->json('account'));
        $this->assertSame(1, User::where('student_id', $student->id)->count());
    }

    #[Test]
    public function the_backfill_endpoint_is_admin_only(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();

        $this->postJson("/api/v1/admin/students/{$student->id}/account")
            ->assertUnauthorized();

        $this->actingAs($this->teacher())
            ->postJson("/api/v1/admin/students/{$student->id}/account")
            ->assertForbidden();
    }

    #[Test]
    public function a_provisioned_student_can_log_in_with_the_initial_password(): void
    {
        // No actingAs before the login (session stickiness): mint the
        // account through the service, then drive the real login form.
        $student = Student::create([
            'name' => 'Acceso Fresco',
            'grade' => '5°',
            'class_id' => SchoolClass::firstOrFail()->id,
            'pae_enrolled' => false,
        ]);
        app(StudentAccountService::class)->provisionFor($student);

        $this->post('/login', [
            'email' => 'acceso@presence.test',
            'password' => 'password',
        ])->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs(User::where('email', 'acceso@presence.test')->firstOrFail());
    }

    #[Test]
    public function the_students_desk_shows_the_login_column(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/students');

        $response->assertOk()
            ->assertSee('<th scope="col">'.__('app.account').'</th>', false)
            ->assertSee('maria@presence.test');
    }
}
