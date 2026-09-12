<?php

namespace Database\Seeders;

use App\Enums\CardStatus;
use App\Enums\EventType;
use App\Enums\ReaderType;
use App\Enums\UserRole;
use App\Models\Card;
use App\Models\Reader;
use App\Models\Reward;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo seeder (Phase A). This is how the whole platform is exercised by
 * hand before any hardware exists: it prints every credential_uid and
 * reader api_key to the console, ready to paste into Postman/curl.
 *
 * Output is bilingual (EN + ES) per the platform requirement.
 *
 * DEV/DEMO ONLY (finding 7.2): every credential below is a published,
 * shared secret — never run this seeder against a production database.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- Users ----------
        // TASK-030-A (ADR-044) — demo accounts opt OUT of the forced
        // rotation (must_change_password=false): one-tap demo logins
        // keep working. Real enrollments (desk create/import) opt IN.
        $admin = User::firstOrCreate(
            ['email' => 'admin@presence.test'],
            [
                'name' => 'School Admin',
                'password' => 'password',
                'role' => UserRole::Admin->value,
                'must_change_password' => false,
            ],
        );

        // ---------- Class ----------
        $class = SchoolClass::firstOrCreate(
            ['name' => '5° B'],
            ['teacher_user_id' => null],
        );

        $teacher = User::firstOrCreate(
            ['email' => 'teacher@presence.test'],
            [
                'name' => 'Prof. Elena Ramírez',
                'password' => 'password',
                'role' => UserRole::Teacher->value,
                'must_change_password' => false,
            ],
        );

        $class->update(['teacher_user_id' => $teacher->id]);

        // ---------- Students + cards ----------
        $students = [
            ['name' => 'Maria González', 'grade' => '5°', 'pae_enrolled' => true],
            ['name' => 'Carlos Pérez', 'grade' => '5°', 'pae_enrolled' => true],
            ['name' => 'Ana Martínez', 'grade' => '5°', 'pae_enrolled' => false],
            ['name' => 'Diego López', 'grade' => '5°', 'pae_enrolled' => true],
        ];

        $cards = [];
        $studentRows = [];

        foreach ($students as $data) {
            $student = Student::firstOrCreate(
                ['name' => $data['name']],
                [
                    'grade' => $data['grade'],
                    'pae_enrolled' => $data['pae_enrolled'],
                    'class_id' => $class->id,
                ],
            );

            // TASK-025 item 5 (spec §11/§30) — the 1:1 student account
            // layer: a users row REFERENCING the students row (never a
            // second identity). Same login path as staff.
            $slug = str($data['name'])->before(' ')->lower()->ascii();
            User::firstOrCreate(
                ['email' => "{$slug}@presence.test"],
                [
                    'name' => $data['name'],
                    'password' => 'password',
                    'role' => UserRole::Student->value,
                    'student_id' => $student->id,
                    'must_change_password' => false,
                ],
            );

            $studentRows[] = $student;

            $card = Card::firstOrCreate(
                ['student_id' => $student->id],
                [
                    'credential_uid' => strtoupper(Str::random(12)),
                    'status' => CardStatus::Active->value,
                ],
            );

            $cards[] = $card;
        }

        // ---------- Readers ----------
        $classroomReader = Reader::firstOrCreate(
            ['label' => 'Demo Reader — Classroom/PAE'],
            [
                'type' => ReaderType::Classroom->value,
                'active_event_type' => EventType::ClassAttendance->value,
                'api_key' => Str::random(32),
            ],
        );

        $recyclingReader = Reader::firstOrCreate(
            ['label' => 'Demo Reader — Recycling'],
            [
                'type' => ReaderType::Recycling->value,
                'active_event_type' => EventType::RecyclingDeposit->value,
                'api_key' => Str::random(32),
            ],
        );

        // ---------- Rewards ----------
        // ASSUMED DEFAULT CATALOG — the owner must confirm or replace it
        // (see ADR-004 in .agent/DECISIONS/).
        // TASK-025 item 7 (spec §19/§20): the catalog now carries
        // type / value / active / stock (NULL stock = unlimited).
        $rewards = [
            ['name' => 'Canteen discount voucher', 'point_cost' => 50, 'description' => 'One-time canteen discount.', 'type' => 'voucher', 'value' => 2000, 'active' => true, 'stock' => 10],
            ['name' => 'Raffle entry', 'point_cost' => 20, 'description' => 'One entry in the end-of-term raffle.', 'type' => 'raffle', 'value' => null, 'active' => true, 'stock' => 50],
            ['name' => 'Leaderboard shout-out', 'point_cost' => 5, 'description' => 'Name highlighted on the school leaderboard.', 'type' => 'shoutout', 'value' => null, 'active' => true, 'stock' => null],
            ['name' => 'Early lunch pass', 'point_cost' => 15, 'description' => 'Skip the lunch line for one day.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => 3],
        ];

        foreach ($rewards as $reward) {
            Reward::firstOrCreate(['name' => $reward['name']], $reward);
        }

        // ---------- Console output (hard requirement, bilingual) ----------
        $this->printCredentials($cards, $classroomReader, $recyclingReader, $admin, $teacher, $studentRows);
    }

    private function printCredentials(array $cards, Reader $classroom, Reader $recycling, User $admin, User $teacher, array $studentRows = []): void
    {
        $line = str_repeat('=', 74);

        $this->command->warn($line);
        $this->command->warn(' DEMO CREDENTIALS — copy/paste into Postman or curl');
        $this->command->warn(' CREDENCIALES DE DEMO — copiar/pegar en Postman o curl');
        $this->command->warn($line);

        $this->command->info(' [EN] Dashboard users / Usuarios del panel:');
        $this->command->table(
            ['User / Usuario', 'Email', 'Password', 'Role / Rol'],
            array_merge(
                [
                    [$admin->name, $admin->email, 'password', $admin->role],
                    [$teacher->name, $teacher->email, 'password', $teacher->role],
                    // TASK-025 item 5 — demo student accounts (own data only).
                ],
                array_map(
                    fn (Student $student) => [
                        $student->name,
                        str($student->name)->before(' ')->lower()->ascii().'@presence.test',
                        'password',
                        UserRole::Student->value,
                    ],
                    $studentRows,
                ),
            ),
        );

        $this->command->info(' [EN] Cards — use credential_uid as {"credential_uid": "..."} in POST /api/v1/events/tap');
        $this->command->info(' [ES] Tarjetas — usa credential_uid como {"credential_uid": "..."} en POST /api/v1/events/tap');
        $this->command->table(
            ['Student / Estudiante', 'credential_uid'],
            array_map(fn (Card $card) => [$card->student->name, $card->credential_uid], $cards),
        );

        $this->command->info(' [EN] Readers — send as header: Authorization: Bearer <api_key>');
        $this->command->info(' [ES] Lectores — envía como cabecera: Authorization: Bearer <api_key>');
        $this->command->table(
            ['Reader / Lector', 'Type / Tipo', 'active_event_type', 'api_key (Bearer)'],
            [
                [$classroom->label, $classroom->type->value, $classroom->active_event_type, $classroom->api_key],
                [$recycling->label, $recycling->type->value, $recycling->active_event_type, $recycling->api_key],
            ],
        );

        $this->command->warn($line);
        $this->command->warn(' [EN] The recycling reader returns next_step="awaiting_classification" —');
        $this->command->warn('     then POST /api/v1/recycling/classify with event_id + an image file.');
        $this->command->warn(' [ES] El lector de reciclaje devuelve next_step="awaiting_classification" —');
        $this->command->warn('     luego POST /api/v1/recycling/classify con event_id y un archivo de imagen.');
        $this->command->warn($line);
    }
}
