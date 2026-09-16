<?php

namespace Tests;

use App\Models\Card;
use App\Models\Reader;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TASK-037 — the settings cache is per-PROCESS static; RefreshDatabase
        // drops/recreates the settings table between tests, so a stale cache
        // would serve the previous test's overrides (or defaults where rows
        // existed). Flush here, always.
        SettingsService::flushCache();
    }

    /**
     * Seed the standard demo data (same seeder used in production demos)
     * and return handles to the fixtures the tests need most.
     *
     * @return array{admin: User, teacher: User, students: Collection<int, Student>}
     */
    protected function seedDemo(): array
    {
        $this->artisan('db:seed', ['--class' => 'Database\Seeders\DemoSeeder', '--force' => true]);

        return [
            'admin' => User::where('email', 'admin@presence.test')->firstOrFail(),
            'teacher' => User::where('email', 'teacher@presence.test')->firstOrFail(),
            'students' => Student::orderBy('id')->get(),
        ];
    }

    /** The school the demo/pilot seeders provision (the only supported one). */
    protected function demoSchool(): School
    {
        return School::where('slug', 'ie-concejo-de-sabaneta')->firstOrFail();
    }

    /**
     * Create a student inside the demo school, visible to the demo
     * admin. A bare Student::create() in a test builds a NULL-school
     * row, which the organization wall correctly hides from a school
     * account — so fixtures that the demo admin must SEE go through
     * here.
     */
    protected function schoolStudent(array $attributes): Student
    {
        return Student::create($attributes + ['school_id' => $this->demoSchool()->id]);
    }

    /** Create a class inside the demo school (same wall, same reason). */
    protected function schoolClass(array $attributes): SchoolClass
    {
        return SchoolClass::create($attributes + ['school_id' => $this->demoSchool()->id]);
    }

    /**
     * Find-or-create a reader inside the demo school. A bare
     * Reader::firstOrCreate() in a test builds a NULL-school device,
     * whose taps then cannot see the school's cards.
     */
    protected function schoolReader(string $label, array $values): Reader
    {
        return Reader::firstOrCreate(
            ['label' => $label, 'school_id' => $this->demoSchool()->id],
            $values
        );
    }

    /** Bearer token for a demo reader by type ('classroom' | 'pae' | 'recycling'). */
    protected function readerToken(string $type): string
    {
        $reader = Reader::where('type', $type)->firstOrFail();

        return $reader->api_key;
    }

    /** A demo reader by type. */
    protected function reader(string $type): Reader
    {
        return Reader::where('type', $type)->firstOrFail();
    }

    /**
     * TASK-037 — widen the meal serving windows to cover the whole day
     * (00:00–23:59 for both meals, non-overlapping by a hair is
     * impossible for full-day — so this uses 00:00–12:00 + 12:00–23:59,
     * touching, never overlapping). Window-boundary tests set their own
     * windows explicitly; this helper makes generic tap-flow tests
     * deterministic at any wall-clock time.
     */
    protected function wideMealWindows(): void
    {
        app(SettingsService::class)->setMany([
            'pae.breakfast_start' => '00:00',
            'pae.breakfast_end' => '12:00',
            'pae.lunch_start' => '12:00',
            'pae.lunch_end' => '23:59',
        ]);
    }

    /** The first active card's credential_uid. */
    protected function cardUidFor(string $studentName): string
    {
        $student = Student::where('name', $studentName)->firstOrFail();

        return $student->cards()->firstOrFail()->credential_uid;
    }

    /** Card lookup helper. */
    protected function cardOf(string $studentName): Card
    {
        $student = Student::where('name', $studentName)->firstOrFail();

        return $student->cards()->firstOrFail();
    }
}
