<?php

namespace Tests;

use App\Models\Card;
use App\Models\Reader;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;

abstract class TestCase extends BaseTestCase
{
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

    /** Bearer token for a demo reader by type ('classroom' | 'recycling'). */
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
