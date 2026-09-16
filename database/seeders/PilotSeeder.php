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
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StudentAccountService;
use App\Support\Tenancy\CurrentSchool;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * TASK-030 (Fix 3) — the pilot seeder: a school that looks ALIVE.
 *
 * DemoSeeder is the small, stable, test-pinned fixture. THIS seeder is
 * the human-facing one: three classes, 24 students with logins and
 * cards, four readers, ten school days of taps (attendance with lates,
 * PAE meals honoring per-meal enrollment and the same-day attendance
 * prerequisite, recycling deposits with ledger points, two
 * redemptions), all deterministic (mt_srand) so every reseed produces
 * the same volumes on the same floating ten-weekday window.
 *
 * TASK-037 — ENTRY/EXIT is gone from the platform (supersedes ADR-038):
 * no gate reader, no gate taps; PAE enrollment is per meal (breakfast
 * / lunch independently) and meals only happen for present students.
 *
 * TASK-039 — seeder-convention refresh: the initial password resolves
 * through the accounts preset (never hardcoded), the settings cache is
 * flushed after seeding rows, and the summary prints every staff login
 * including kitchen.
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
    /** The only supported school right now: every pilot row belongs to it. */
    private const SCHOOL_NAME = 'IE Concejo de Sabaneta J.M.C.B';

    private const SCHOOL_SLUG = 'ie-concejo-de-sabaneta';

    /** The branding profile in config/branding.php this school renders with. */
    private const SCHOOL_BRAND = 'ie-concejo-de-sabaneta';

    private const RNG_SEED = 20260911;

    private const SCHOOL_DAYS = 10;

    /** [name, grade, breakfast, lunch] x8 per class — TASK-037: per-meal. */
    private const ROSTER_5A = [
        ['Sofía Herrera', '5°', true, true],
        ['Mateo Rojas', '5°', true, false],
        ['Valentina Castro', '5°', true, true],
        ['Santiago Morales', '5°', false, false],
        ['Isabella Ortiz', '5°', true, true],
        ['Sebastián Guzmán', '5°', false, true],
        ['Camila Vargas', '5°', true, true],
        ['Nicolás Peña', '5°', false, false],
    ];

    private const ROSTER_5B = [
        ['Maria González', '5°', true, true],
        ['Carlos Pérez', '5°', true, false],
        ['Ana Martínez', '5°', false, true],
        ['Diego López', '5°', false, false],
        ['Luciana Ríos', '5°', true, true],
        ['Emiliano Soto', '5°', true, true],
        ['Antonia Vega', '5°', false, true],
        ['Joaquín Cortés', '5°', true, true],
    ];

    private const ROSTER_6A = [
        ['Fernanda Silva', '6°', true, true],
        ['Martín Bravo', '6°', true, false],
        ['Paula Fuentes', '6°', false, false],
        ['Andrés Paredes', '6°', true, true],
        ['Daniela Soto', '6°', true, true],
        ['Gabriel Méndez', '6°', true, false],
        ['Renata Díaz', '6°', true, true],
        ['Tomás Aguirre', '6°', false, false],
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

        // The organization comes first, and everything below is created
        // while ACTING AS it: BelongsToSchool's creation inheritance
        // stamps every row, and the pilot admin therefore opens the
        // school-branded shell (same pattern as RealisticSeeder).
        $school = School::provision(self::SCHOOL_NAME, self::SCHOOL_SLUG, self::SCHOOL_BRAND);
        app(CurrentSchool::class)->actAs($school);

        mt_srand(self::RNG_SEED);

        // TASK-039 — every fixture password equals the effective
        // accounts preset (falls back to config before the settings
        // rows exist): an overridden STUDENT_INITIAL_PASSWORD still
        // yields one-tap fixture logins that match the printed table.
        $initialPassword = (string) settings()->studentInitialPassword();

        $admin = User::firstOrCreate(
            ['email' => 'admin@presence.test'],
            ['name' => 'School Admin', 'password' => $initialPassword, 'role' => UserRole::Admin->value, 'must_change_password' => false],
        );

        // TASK-037 — kitchen account (ADR-054) + runtime settings rows.
        $kitchen = User::firstOrCreate(
            ['email' => 'kitchen@presence.test'],
            ['name' => 'Sofía Vargas', 'password' => $initialPassword, 'role' => UserRole::Kitchen->value, 'must_change_password' => false],
        );

        foreach ([
            'pae.breakfast_start' => config('presence.pae.breakfast_start', '06:30'),
            'pae.breakfast_end' => config('presence.pae.breakfast_end', '08:30'),
            'pae.lunch_start' => config('presence.pae.lunch_start', '11:30'),
            'pae.lunch_end' => config('presence.pae.lunch_end', '13:30'),
            'attendance.late_cutoff' => config('presence.late_cutoff', '08:15'),
            'pairing.window_seconds' => config('presence.pairing_window_seconds', 45),
            'accounts.student_email_domain' => config('presence.student_email_domain', 'presence.test'),
            'accounts.student_initial_password' => config('presence.student_initial_password', 'password'),
        ] as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        SettingsService::flushCache();

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

        $this->printSummary($admin, $kitchen, $classes, $students, $readers, $days, $eventCount, $depositCount, $redemptions, $initialPassword);

        // Leave the process as it found it: a seeder that stays "acting
        // as" a school would silently scope anything that runs after it.
        app(CurrentSchool::class)->forgetOverride();
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
                ['name' => $teacherName, 'password' => (string) settings()->studentInitialPassword(), 'role' => UserRole::Teacher->value, 'must_change_password' => false],
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
            foreach ($roster as [$name, $grade, $breakfast, $lunch]) {
                $student = Student::create([
                    'name' => $name,
                    'grade' => $grade,
                    'pae_breakfast_enrolled' => $breakfast,
                    'pae_lunch_enrolled' => $lunch,
                    'class_id' => $classes[$className]->id,
                ]);

                // Convention email via the real allocator (first-name
                // slugs; true collisions take numeric suffixes), demo
                // logins stay one-tap (no forced rotation on fixtures).
                User::create([
                    'name' => $name,
                    'email' => $accounts->emailForName($name),
                    'password' => (string) settings()->studentInitialPassword(),
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
        // TASK-037 — the gate reader is gone with ENTRY/EXIT; the two PAE
        // readers stay as separate cafeteria stations (both auto-detect
        // the meal from the serving windows; their labels are the human
        // hint, not the meal authority).
        $defs = [
            'classroom' => ['Pilot — Classroom', ReaderType::Classroom->value, EventType::ClassAttendance->value],
            'pae_breakfast' => ['Pilot — Cafeteria North', ReaderType::Pae->value, EventType::PaeBreakfast->value],
            'pae_lunch' => ['Pilot — Cafeteria South', ReaderType::Pae->value, EventType::PaeLunch->value],
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

        // Classroom attendance: most on time, a real late tail. The
        // meal events below only happen when this tap exists — the
        // engine's same-day attendance prerequisite, honored by the
        // seed (TASK-037).
        $attended = false;
        $attendedAt = null;
        if (mt_rand(1, 100) <= 95) {
            $late = mt_rand(1, 100) <= 16;
            $attendedAt = $late ? $at(8, 16, 40) : $at(7, 0, 14);
            $tap($readers['classroom'], EventType::ClassAttendance->value, $attendedAt);
            $attended = true;
        }

        // PAE meals honor PER-MEAL enrollment and the attendance
        // prerequisite: a non-enrolled or absent student's meal never
        // appears (the engine's 422 is the point, not seed fiction).
        if ($attended) {
            if ($student->pae_breakfast_enrolled && mt_rand(1, 100) <= 85) {
                $breakfastAt = $at(7, 5, 55);
                if ($attendedAt === null || $breakfastAt->gt($attendedAt)) {
                    $tap($readers['pae_breakfast'], EventType::PaeBreakfast->value, $breakfastAt);
                }
            }
            if ($student->pae_lunch_enrolled && mt_rand(1, 100) <= 80) {
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
    private function printSummary(User $admin, User $kitchen, array $classes, array $students, array $readers, array $days, int $events, int $deposits, int $redemptions, string $initialPassword): void
    {
        $line = str_repeat('=', 74);
        $this->command->warn($line);
        $this->command->warn(' PILOT DATA — a school that looks alive (deterministic reseed)');
        $this->command->warn(' DATOS PILOTO — un colegio que se ve vivo (resiembra determinista)');
        $this->command->warn($line);
        $this->command->info(' [EN] Organization: '.self::SCHOOL_NAME.' (slug '.self::SCHOOL_SLUG.', branding '.self::SCHOOL_BRAND.') — every row below belongs to it.');
        $this->command->info(' [ES] Organización: '.self::SCHOOL_NAME.' (slug '.self::SCHOOL_SLUG.', identidad '.self::SCHOOL_BRAND.') — todo lo de abajo le pertenece.');

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

        $this->command->info(" [EN] Staff logins (password: {$initialPassword}) / Accesos del personal (clave: {$initialPassword}):");
        $this->command->table(
            ['User / Usuario', 'Email', 'Role / Rol'],
            array_merge(
                [
                    [$admin->name, $admin->email, $admin->role],
                    // TASK-039 — the kitchen login was seeded but never
                    // printed: operators couldn't discover it.
                    [$kitchen->name, $kitchen->email, $kitchen->role],
                ],
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
