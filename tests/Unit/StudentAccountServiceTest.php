<?php

namespace Tests\Unit;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-030-A (ADR-044) — the provisioning primitive itself: convention
 * emails, collision disambiguation, idempotency, and the rotation flag.
 * HTTP wiring (create/import/backfill/desk) lives in
 * StudentAccountProvisioningTest; the rotation wall in PasswordChangeTest.
 */
class StudentAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private StudentAccountService $accounts;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = new StudentAccountService;
        $this->class = SchoolClass::create(['name' => '5° B']);
    }

    private function makeStudent(string $name): Student
    {
        return Student::create([
            'name' => $name,
            'grade' => '5°',
            'class_id' => $this->class->id,
            'pae_enrolled' => false,
        ]);
    }

    #[Test]
    public function the_email_follows_the_established_convention(): void
    {
        $this->assertSame('maria@presence.test', $this->accounts->emailForName('Maria González'));
        $this->assertSame('ana@presence.test', $this->accounts->emailForName('Ana Martínez'));
    }

    #[Test]
    public function collisions_disambiguate_with_a_numeric_suffix(): void
    {
        User::create([
            'name' => 'Maria González',
            'email' => 'maria@presence.test',
            'password' => 'password',
            'role' => 'student',
        ]);

        $this->assertSame('maria2@presence.test', $this->accounts->emailForName('María López'));

        User::create([
            'name' => 'María López',
            'email' => 'maria2@presence.test',
            'password' => 'password',
            'role' => 'student',
        ]);

        $this->assertSame('maria3@presence.test', $this->accounts->emailForName('Maria Torres'));
    }

    #[Test]
    public function names_with_no_ascii_letters_fall_back_to_student(): void
    {
        $email = $this->accounts->emailForName('李小龙');

        $this->assertStringStartsWith('student@', $email);
        $this->assertStringEndsWith('@presence.test', $email);
    }

    #[Test]
    public function provisioning_mints_a_flagged_student_account(): void
    {
        $student = $this->makeStudent('Nueva Estudiante');

        ['user' => $user, 'already' => $already] = $this->accounts->provisionFor($student);

        $this->assertFalse($already);
        $this->assertSame('nueva@presence.test', $user->email);
        $this->assertSame('student', $user->role);
        $this->assertSame($student->id, $user->student_id);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    #[Test]
    public function provisioning_is_idempotent(): void
    {
        $student = $this->makeStudent('Repetida Alumna');

        $first = $this->accounts->provisionFor($student);
        $second = $this->accounts->provisionFor($student);

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame(1, User::where('student_id', $student->id)->count());
    }

    #[Test]
    public function bulk_provisioning_allocates_unique_flagged_emails(): void
    {
        $students = collect();
        for ($i = 1; $i <= 25; $i++) {
            $students->push($this->makeStudent("Melliza {$i}"));
        }

        $emails = $this->accounts->provisionMany($students);

        $this->assertCount(25, $emails);
        $this->assertSame(25, collect($emails)->unique()->count());
        $this->assertSame(25, User::where('must_change_password', true)->count());

        foreach ($students as $student) {
            $this->assertSame($emails[$student->id], $student->account->email);
        }
    }

    #[Test]
    public function bulk_provisioning_keeps_pre_existing_accounts(): void
    {
        $legacy = $this->makeStudent('Antigua Alumna');
        $first = $this->accounts->provisionFor($legacy)['user'];
        $fresh = $this->makeStudent('Nueva Vecina');

        $emails = $this->accounts->provisionMany(collect([$legacy->fresh(), $fresh]));

        $this->assertSame($first->email, $emails[$legacy->id]);
        $this->assertArrayHasKey($fresh->id, $emails);
        $this->assertSame(2, User::whereIn('student_id', [$legacy->id, $fresh->id])->count());
    }

    #[Test]
    public function factory_users_are_well_formed_unflagged_staff(): void
    {
        $user = User::factory()->create();

        $this->assertSame('admin', $user->role);
        $this->assertFalse($user->must_change_password);
    }

    #[Test]
    public function a_rotated_account_is_never_reflagged(): void
    {
        $student = $this->makeStudent('Ya Rotada');

        ['user' => $user] = $this->accounts->provisionFor($student);
        $user->update(['password' => 'a-brand-new-secret', 'must_change_password' => false]);

        ['user' => $same, 'already' => $already] = $this->accounts->provisionFor($student);

        $this->assertTrue($already);
        $this->assertFalse($same->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-secret', $same->password));
    }
}
