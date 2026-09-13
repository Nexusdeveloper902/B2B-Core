<?php

namespace Tests\Feature\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-039 — seeder-convention refresh. The seeders predate the account
 * conventions (hardcoded domain, hand-rolled slugs, printed emails that
 * could lie, an unprinted kitchen login). These pins hold the refreshed
 * behavior: settings presets flow into fixtures, allocation goes through
 * the real allocator, reruns mint nothing twice.
 */
class SeederConventionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function demo_fixtures_honor_the_configured_account_preset(): void
    {
        $domain = config('presence.student_email_domain');
        $password = config('presence.student_initial_password');

        config()->set('presence.student_email_domain', 'colegio.test');
        config()->set('presence.student_initial_password', 'secreta-01');

        try {
            $this->seedDemo();
        } finally {
            config()->set('presence.student_email_domain', $domain);
            config()->set('presence.student_initial_password', $password);
        }

        $maria = Student::where('name', 'Maria González')->firstOrFail();

        $this->assertSame('maria@colegio.test', $maria->account?->email);
        $this->assertTrue(Hash::check('secreta-01', $maria->account->password));

        // Staff fixtures follow the same preset (one-tap demo logins,
        // no forced rotation — the ADR-044 demo opt-out).
        $this->assertTrue(Hash::check('secreta-01', User::where('email', 'admin@presence.test')->firstOrFail()->password));
        $this->assertFalse(User::where('email', 'admin@presence.test')->firstOrFail()->must_change_password);
    }

    #[Test]
    public function rerunning_the_demo_seeder_mints_no_duplicate_accounts(): void
    {
        $this->seedDemo();
        $users = User::count();

        $this->seedDemo();

        $this->assertSame($users, User::count());
        foreach (Student::all() as $student) {
            $this->assertSame(1, User::where('student_id', $student->id)->count(), "{$student->name} must keep exactly one login");
        }
    }

    #[Test]
    public function pilot_fixtures_honor_the_configured_initial_password(): void
    {
        $password = config('presence.student_initial_password');
        config()->set('presence.student_initial_password', 'piloto-01');

        try {
            $this->artisan('db:seed', ['--class' => 'Database\Seeders\PilotSeeder', '--force' => true]);
        } finally {
            config()->set('presence.student_initial_password', $password);
        }

        $this->assertTrue(Hash::check('piloto-01', User::where('email', 'admin@presence.test')->firstOrFail()->password));
        $this->assertTrue(Hash::check('piloto-01', User::where('email', 'kitchen@presence.test')->firstOrFail()->password));
        $this->assertTrue(Hash::check('piloto-01', User::where('email', 'teacher@presence.test')->firstOrFail()->password));
    }
}
