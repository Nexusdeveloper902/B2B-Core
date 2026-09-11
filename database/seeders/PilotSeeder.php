<?php

namespace Database\Seeders;

use App\Enums\CardStatus;
use App\Enums\EventType;
use App\Enums\ReaderType;
use App\Enums\UserRole;
use App\Models\Card;
use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAccountService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * TASK-030 (Fix 3) — the pilot seeder: a school that looks ALIVE.
 *
 * DemoSeeder is the small, stable, test-pinned fixture (4 students, no
 * events — the suite counts on it). THIS seeder is the human-facing one:
 * three classes, 24 students with logins and cards, five readers, ten
 * school days of taps (entries/exits, attendance with lates, PAE meals
 * honoring enrollment, recycling deposits with ledger points, two
 * redemptions), all deterministic (mt_srand) so every reseed produces
 * the same volumes on the same floating ten-weekday window.
 *
 * Fresh databases only: refuses to run when students already exist
 * (events/deposits are append-only rows — re-running would double the
 * story). Invoke with:
 *
 *   php artisan migrate:fresh --seeder=PilotSeeder
 *   ./run reset --pilot
 *
 * DEV/DEMO ONLY (same standing-credential honesty as DemoSeeder):
 * every credential below is a published, shared secret — never run
 * either seeder against a production database.
 */
class PilotSeeder extends Seeder
{
    private const RNG_SEED = 20260911;

    private const SCHOOL_DAYS = 10;

    /** [name, grade, pae] x8 per class. */
    private const ROSTER_5A = [
        ['Sofía Herrera', '5°', true],
        ['Mateo Rojas', '5°', true],
        ['Valentina Castro', '5°', true],
        ['Santiago Morales', '5°', false],
        ['Isabella Ortiz', '5°', true],
        ['Sebastián Guzmán', '5°', true],
        ['Camila Vargas', '5°', true],
        ['Nicolás Peña', '5°', false],
    ];

    private const ROSTER_5B = [
        ['Maria González', '5°', true],
        ['Carlos Pérez', '5°', true],
        ['Ana Martínez', '5°', false],
        ['Diego López', '5°', true],
        ['Luciana Ríos', '5°', true],
        ['Emiliano Soto', '5°', true],
        ['Antonia Vega', '5°', false],
        ['Joaquín Cortés', '5°', true],
    ];

    private const ROSTER_6A = [
        ['Fernanda Silva', '6°', true],
        ['Martín Bravo', '6°', true],
        ['Paula Fuentes', '6°', false],
        ['Andrés Paredes', '6°', true],
        ['Daniela Soto', '6°', true],
        ['Gabriel Méndez', '6°', true],
        ['Renata Díaz', '6°', true],
        ['Tomás Aguirre', '6°', false],
    ];

    /** material => weight for the deterministic draw. */
    private const MATERIALS = [
        'plastic' => 45,
        'paper' => 25,
        'metal' => 12,
        'glass' => 10,
        'other' => 8,
    ];

    public function run(): void
    {
        if (Student::exists()) {
            $this->command->error('PilotSeeder needs a fresh database (students already exist) — run: php artisan migrate:fresh --seeder=PilotSeeder / ./run reset --pilot');
            $this->command->error('PilotSeeder necesita una base de datos fresca (ya hay estudiantes) — ejecuta: php artisan migrate:fresh --seeder=PilotSeeder / ./run reset --pilot');

            return;
        }

        mt_srand(self::RNG_SEED);

        $admin = User::firstOrCreate(
            ['email' => 'admin@presence.test'],
            ['name' => 'School Admin', 'password' => 'password', 'role' => UserRole::Admin->value, 'must_change_password' => false],
        );

        $classes = $this->seedClasses();
        $students = $this->seedStudents($classes);
        $readers = $this->seedReaders();
        $this->seedRewards();

        $days = $this->schoolDays();
        $eventCount = 0;
        $depositCount = 0;

        foreach ($days as $day) {
            foreach ($students as $student) {
                [$events, $deposits] = $this->seedStudentDay($student, $day, $readers);
                $eventCount += $events;
                $depositCount += $deposits;
            }
        }

        $redemptions = $this->seedRedemptions($students);

        $this->printSummary($admin, $classes, $students, $readers, $days, $eventCount, $depositCount, $redemptions);
    }

    /** @return array<string, SchoolClass> */
    private function seedClasses(): array
    {
        $defs = [
            '5° A' => ['Prof. Elena Ramírez', 'teacher@presence.test'],
            '5° B' => ['Prof. Carlos Mendoza', 'carlos.m@presence.test'],
            '6° A' => ['Prof. Ana Torres', 'ana.t@presence.test'],
        ];

        $classes = [];

        foreach ($defs as $name => [$teacherName, $email]) {
            $teacher = User::firstOrCreate(
                ['email' => $email],
                ['name' => $teacherName, 'password' => 'password', 'role' => UserRole::Teacher->value, 'must_change_password' => false],
            );

            $class = SchoolClass::firstOrCreate(['name' => $name]);
            $class->update(['teacher_user_id' => $teacher->id]);
            $classes[$name] = $class;
        }

        return $classes;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @return array<int, Student>
     */
    private function seedStudents(array $classes): array
    {
        $accounts = new StudentAccountService;
        $students = [];
        $cardIndex = 0;

        $rosters = [
            '5° A' => self::ROSTER_5A,
            '5° B' => self::ROSTER_5B,
            '6° A' => self::ROSTER_6A,
        ];

        foreach ($rosters as $className => $roster) {
            foreach ($roster as [$name, $grade, $pae]) {
                $student = Student::create([
                    'name' => $name,
                    'grade' => $grade,
                    'pae_enrolled' => $pae,
                    'class_id' => $classes[$className]->id,
                ]);

                // Convention email via the real allocator (first-name
                // slugs; true collisions take numeric suffixes), demo
                // logins stay one-tap (no forced rotation on fixtures).
                User::create([
                    'name' => $name,
                    'email' => $accounts->emailForName($name),
                    'password' => 'password',
                    'role' => UserRole::Student->value,
                    'student_id' => $student->id,
                    'must_change_password' => false,
                ]);

                $cardIndex++;
                Card::create([
                    'student_id' => $student->id,
                    'credential_uid' => 'PILOT-'.str_pad((string) $cardIndex, 4, '0', STR_PAD_LEFT),
                    'status' => CardStatus::Active->value,
                ]);

                $students[] = $student;
            }
        }

        return $students;
    }

    /** @return array<string, Reader> */
    private function seedReaders(): array
    {
        $defs = [
            'classroom' => ['Pilot — Classroom', ReaderType::Classroom->value, EventType::ClassAttendance->value],
            'pae_breakfast' => ['Pilot — PAE Breakfast', ReaderType::Pae->value, EventType::PaeBreakfast->value],
            'pae_lunch' => ['Pilot — PAE Lunch', ReaderType::Pae->value, EventType::PaeLunch->value],
            'entry' => ['Pilot — Gate', ReaderType::Entry->value, EventType::Entry->value],
            'recycling' => ['Pilot — EcoStation', ReaderType::Recycling->value, EventType::RecyclingDeposit->value],
        ];

        $readers = [];

        foreach ($defs as $key => [$label, $type, $mode]) {
            $readers[$key] = Reader::firstOrCreate(
                ['label' => $label],
                [
                    'type' => $type,
                    'active_event_type' => $mode,
                    'api_key' => substr(hash('sha256', 'pilot/'.$label), 0, 32),
                ],
            );
        }

        return $readers;
    }

    private function seedRewards(): void
    {
        $rewards = [
            ['name' => 'Canteen discount voucher', 'point_cost' => 50, 'description' => 'One-time canteen discount.', 'type' => 'voucher', 'value' => 2000, 'active' => true, 'stock' => 10],
            ['name' => 'Raffle entry', 'point_cost' => 20, 'description' => 'One entry in the end-of-term raffle.', 'type' => 'raffle', 'value' => null, 'active' => true, 'stock' => 50],
            ['name' => 'Leaderboard shout-out', 'point_cost' => 5, 'description' => 'Name highlighted on the school leaderboard.', 'type' => 'shoutout', 'value' => null, 'active' => true, 'stock' => null],
            ['name' => 'Early lunch pass', 'point_cost' => 15, 'description' => 'Skip the lunch line for one day.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => 3],
        ];

        foreach ($rewards as $reward) {
            Reward::firstOrCreate(['name' => $reward['name']], $reward);
        }
    }

    /** @return array<int, Carbon> last N weekdays (Bogota), oldest first. */
    private function schoolDays(): array
    {
        $days = [];
        $cursor = Carbon::today('America/Bogota');

        while (count($days) < self::SCHOOL_DAYS) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->copy();
            }
            $cursor->subDay();
        }

        return array_reverse($days);
    }

    /**
     * One plausible school day for one student.
     *
     * @param  array<string, Reader>  $readers
     * @return array{int, int} [events, deposits]
     */
    private function seedStudentDay(Student $student, Carbon $day, array $readers): array
    {
        $card = $student->cards()->firstOrFail();
        $events = 0;
        $deposits = 0;

        // ~6% full absences: no rows at all (the NL absence analytics
        // need real gaps, not just lates).
        if (mt_rand(1, 100) <= 6) {
            return [0, 0];
        }

        $at = fn (int $hour, int $minStart, int $minEnd): Carbon => $day->copy()->setTime(
            $hour, mt_rand($minStart, $minEnd), mt_rand(0, 59), 0
        );
        $atRange = fn (int $hourStart, int $hourEnd): Carbon => $day->copy()->setTime(
            mt_rand($hourStart, $hourEnd), mt_rand(5, 55), mt_rand(0, 59), 0
        );

        $tap = function (Reader $reader, string $type, Carbon $when) use ($card, &$events): PresenceEvent {
            $events++;

            return PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $reader->id,
                'type' => $type,
                'occurred_at' => $when,
            ]);
        };

        // Gate in (ENTRY) — the 08:15 late line is read off the class tap.
        if (mt_rand(1, 100) <= 97) {
            $tap($readers['entry'], EventType::Entry->value, $at(6, 40, 55));
        }

        // Classroom attendance: most on time, a real late tail.
        if (mt_rand(1, 100) <= 95) {
            $late = mt_rand(1, 100) <= 16;
            $tap(
                $readers['classroom'],
                EventType::ClassAttendance->value,
                $late ? $at(8, 16, 40) : $at(7, 0, 14)
            );
        }

        // PAE meals honor enrollment — a non-enrolled tap never appears
        // (the gate's 422 is the point, not seed fiction).
        if ($student->pae_enrolled) {
            if (mt_rand(1, 100) <= 85) {
                $tap($readers['pae_breakfast'], EventType::PaeBreakfast->value, $at(7, 5, 55));
            }
            if (mt_rand(1, 100) <= 80) {
                $tap($readers['pae_lunch'], EventType::PaeLunch->value, $at(12, 5, 50));
            }
        }

        // Recycling: a steady minority recycles most days.
        $recycles = mt_rand(1, 100) <= 25 ? 1 : (mt_rand(1, 100) <= 8 ? 2 : 0);
        for ($i = 0; $i < $recycles; $i++) {
            $event = $tap($readers['recycling'], EventType::RecyclingDeposit->value, $atRange(9, 13));
            $material = $this->drawMaterial();
            $points = (int) config("recycling.points.{$material}", 0);

            RecyclingDeposit::create([
                'event_id' => $event->id,
                'image_path' => null, // synthetic fixtures carry no image
                'material_class' => $material,
                'confidence' => mt_rand(62, 99) / 100,
                'points_awarded' => $points,
                'is_bottle' => true,
                'is_recyclable' => $material !== 'other',
            ]);

            PointsLedger::create([
                'student_id' => $student->id,
                'delta' => $points,
                'reason' => 'recycling_deposit',
                'event_id' => $event->id,
            ]);

            $deposits++;
        }

        // Gate out (EXIT) — usually, not always (open sessions exist).
        if (mt_rand(1, 100) <= 88) {
            $tap($readers['entry'], EventType::Departure->value, $atRange(14, 16));
        }

        return [$events, $deposits];
    }

    private function drawMaterial(): string
    {
        $roll = mt_rand(1, array_sum(self::MATERIALS));

        foreach (self::MATERIALS as $material => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $material;
            }
        }

        return 'plastic';
    }

    /**
     * Two honest spends for the richest recyclers (shout-out + raffle).
     *
     * @param  array<int, Student>  $students
     */
    private function seedRedemptions(array $students): int
    {
        $ranked = collect($students)
            ->mapWithKeys(fn (Student $s) => [$s->id => $s->pointBalance()])
            ->sortDesc();

        $targets = [
            ['Leaderboard shout-out', 'pilot-shoutout-1'],
            ['Raffle entry', 'pilot-raffle-1'],
        ];

        $done = 0;
        $used = [];

        foreach ($targets as [$rewardName, $requestId]) {
            $reward = Reward::where('name', $rewardName)->firstOrFail();

            foreach ($ranked as $studentId => $balance) {
                if (isset($used[$studentId]) || $balance < $reward->point_cost) {
                    continue;
                }

                $ledger = PointsLedger::create([
                    'student_id' => $studentId,
                    'delta' => -$reward->point_cost,
                    'reason' => 'redemption',
                    'reward_id' => $reward->id,
                ]);

                RewardRedemption::create([
                    'reward_id' => $reward->id,
                    'student_id' => $studentId,
                    'ledger_id' => $ledger->id,
                    'points_spent' => $reward->point_cost,
                    'request_id' => $requestId,
                ]);

                if ($reward->stock !== null) {
                    $reward->decrement('stock');
                }

                $used[$studentId] = true;
                $done++;
                break;
            }
        }

        return $done;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @param  array<int, Student>  $students
     * @param  array<string, Reader>  $readers
     * @param  array<int, Carbon>  $days
     */
    private function printSummary(User $admin, array $classes, array $students, array $readers, array $days, int $events, int $deposits, int $redemptions): void
    {
        $line = str_repeat('=', 74);
        $this->command->warn($line);
        $this->command->warn(' PILOT DATA — a school that looks alive (deterministic reseed)');
        $this->command->warn(' DATOS PILOTO — un colegio que se ve vivo (resiembra determinista)');
        $this->command->warn($line);

        $this->command->info(sprintf(
            ' [EN] %d classes, %d students, %d readers, %d school days (%s → %s), %d taps, %d deposits, %d redemptions',
            count($classes), count($students), count($readers), count($days),
            $days[0]->toDateString(), $days[count($days) - 1]->toDateString(),
            $events, $deposits, $redemptions
        ));
        $this->command->info(sprintf(
            ' [ES] %d cursos, %d estudiantes, %d lectores, %d días de clase (%s → %s), %d toques, %d depósitos, %d canjes',
            count($classes), count($students), count($readers), count($days),
            $days[0]->toDateString(), $days[count($days) - 1]->toDateString(),
            $events, $deposits, $redemptions
        ));

        $this->command->info(' [EN] Staff logins (password: password) / Accesos del personal (clave: password):');
        $this->command->table(
            ['User / Usuario', 'Email', 'Role / Rol'],
            array_merge(
                [[$admin->name, $admin->email, $admin->role]],
                array_map(
                    fn (SchoolClass $c) => [$c->teacher->name ?? '?', $c->teacher->email ?? '?', 'teacher'],
                    array_values($classes),
                ),
            ),
        );

        $this->command->info(' [EN] Readers — send as header: Authorization: Bearer <api_key>');
        $this->command->info(' [ES] Lectores — envía como cabecera: Authorization: Bearer <api_key>');
        $this->command->table(
            ['Reader / Lector', 'Type / Tipo', 'active_event_type', 'api_key (Bearer)'],
            array_map(
                fn (Reader $r) => [$r->label, $r->type->value, $r->active_event_type, $r->api_key],
                array_values($readers),
            ),
        );

        $this->command->warn($line);
    }
}
