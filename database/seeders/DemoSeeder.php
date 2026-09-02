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
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- Users ----------
        $admin = User::firstOrCreate(
            ['email' => 'admin@presence.test'],
            [
                'name' => 'School Admin',
                'password' => 'password',
                'role' => UserRole::Admin->value,
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

        foreach ($students as $data) {
            $student = Student::firstOrCreate(
                ['name' => $data['name']],
                [
                    'grade' => $data['grade'],
                    'pae_enrolled' => $data['pae_enrolled'],
                    'class_id' => $class->id,
                ],
            );

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
        $rewards = [
            ['name' => 'Canteen discount voucher', 'point_cost' => 50, 'description' => 'One-time canteen discount.'],
            ['name' => 'Raffle entry', 'point_cost' => 20, 'description' => 'One entry in the end-of-term raffle.'],
            ['name' => 'Leaderboard shout-out', 'point_cost' => 5, 'description' => 'Name highlighted on the school leaderboard.'],
            ['name' => 'Early lunch pass', 'point_cost' => 15, 'description' => 'Skip the lunch line for one day.'],
        ];

        foreach ($rewards as $reward) {
            Reward::firstOrCreate(['name' => $reward['name']], $reward);
        }

        // ---------- Console output (hard requirement, bilingual) ----------
        $this->printCredentials($cards, $classroomReader, $recyclingReader, $admin, $teacher);
    }

    private function printCredentials(array $cards, Reader $classroom, Reader $recycling, User $admin, User $teacher): void
    {
        $line = str_repeat('=', 74);

        $this->command->warn($line);
        $this->command->warn(' DEMO CREDENTIALS — copy/paste into Postman or curl');
        $this->command->warn(' CREDENCIALES DE DEMO — copiar/pegar en Postman o curl');
        $this->command->warn($line);

        $this->command->info(' [EN] Dashboard users / Usuarios del panel:');
        $this->command->table(
            ['User / Usuario', 'Email', 'Password', 'Role / Rol'],
            [
                [$admin->name, $admin->email, 'password', $admin->role],
                [$teacher->name, $teacher->email, 'password', $teacher->role],
            ],
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
