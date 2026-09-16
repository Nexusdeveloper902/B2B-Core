<?php

namespace Database\Seeders;

use App\Enums\CardStatus;
use App\Enums\EventType;
use App\Enums\ReaderType;
use App\Enums\UserRole;
use App\Models\Reader;
use App\Models\Reward;
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
use Illuminate\Support\Facades\DB;

/**
 * The heavyweight realistic dataset: a whole school semester in a box.
 *
 * DemoSeeder is the small stable test fixture and PilotSeeder the 10-day
 * human demo. THIS seeder is the analytics playground: ~300 students with
 * a lower-grades-heavy distribution (grades 1-11, A/B per grade), per-meal
 * PAE enrollment (breakfast-only / lunch-only / both / neither, with uptake
 * falling off toward the upper grades), a teacher per class plus kitchen
 * and admin staff, and roughly six months of complete school days with
 * school-shaped trends — Monday/Friday dips, a May flu wave, a June/July
 * mid-year recess with public-holiday gaps, exam-week recycling slumps and
 * an August eco-campaign spike. It also exercises every corner of the
 * schema: card replacements (lost/revoked), mid-year transfers and
 * leavers, duplicate / not-enrolled / no-attendance / out-of-window /
 * weekend PAE flags, recycling deposits with ledger points, redemptions
 * (including an exhausted and an inactive reward), pending pairings and
 * captures, roster broadcasts and recycling feed frames.
 *
 * Fresh databases only: refuses to run when students already exist
 * (events are append-only — re-running would double the story). Invoke
 * with:
 *
 *   php artisan migrate:fresh --seeder=RealisticSeeder
 *   ./run seed-realistic
 *
 * Tuning knobs (env, both optional):
 *
 *   REALISTIC_STUDENTS=300   how many students to enroll
 *   REALISTIC_MONTHS=6       how many months of school days to generate
 *
 * Deterministic (mt_srand): the same knobs always produce the same
 * semester. Timings resolve through the effective settings (meal windows
 * + late cutoff), so an overridden timetable still yields coherent taps.
 *
 * DEV/DEMO ONLY (same standing-credential honesty as DemoSeeder): every
 * credential below is a published, shared secret — never run this seeder
 * against a production database.
 */
class RealisticSeeder extends Seeder
{
    /**
     * TASK-045 (ADR-064/ADR-065) — the realistic dataset is ONE school.
     *
     * Every row this seeder writes (staff, classes, students, cards,
     * readers, rewards, events, deposits, ledger, redemptions, pairings,
     * captures and both realtime channels) belongs to this organization,
     * so the demo reads as a coherent institution instead of a pile of
     * unrelated records.
     *
     * It creates exactly ONE school-scoped administrator
     * (admin.colegio@presence.test): the account that opens the
     * school-branded shell. The platform operator is a different,
     * single system-level account seeded OUTSIDE this organization by
     * SystemAdminSeeder (./run seed-realistic runs it right after this
     * one).
     */
    private const SCHOOL_NAME = 'IE Concejo de Sabaneta J.M.C.B';

    private const SCHOOL_SLUG = 'ie-concejo-de-sabaneta';

    /** The branding profile in config/branding.php this school renders with. */
    private const SCHOOL_BRAND = 'ie-concejo-de-sabaneta';

    private ?School $school = null;

    private const RNG_SEED = 20260315;

    private const DEFAULT_STUDENTS = 300;

    private const DEFAULT_MONTHS = 6;

    private const TZ = 'America/Bogota';

    /**
     * Target enrollment per grade (1-11), lower-grades-heavy like a real
     * Colombian school: big first grades, a thin media (10-11). Sums to
     * 300; scaled proportionally when REALISTIC_STUDENTS overrides.
     */
    private const GRADE_WEIGHTS = [40, 36, 34, 32, 30, 28, 26, 24, 22, 18, 10];

    /** Weekday holidays (2026, America/Bogota) inside a Sep-2025..Sep-2026 window. */
    private const HOLIDAYS = [
        '2026-03-23', // San José (observed Monday)
        '2026-04-02', // Jueves Santo
        '2026-04-03', // Viernes Santo
        '2026-05-01', // Día del Trabajo
        '2026-05-18', // Ascensión (observed Monday)
        '2026-06-08', // Corpus Christi (observed Monday)
        '2026-06-15', // Sagrado Corazón (observed Monday)
        '2026-07-20', // Independencia
        '2026-08-07', // Batalla de Boyacá
        '2026-08-17', // Asunción (observed Monday)
    ];

    /** Mid-year recess week (no classes at all). */
    private const RECESS_START = '2026-06-29';

    private const RECESS_END = '2026-07-03';

    /** Flu wave: absences spike these two school weeks. */
    private const FLU_START = '2026-05-11';

    private const FLU_END = '2026-05-22';

    /** Eco-campaign week: recycling roughly doubles. */
    private const ECO_WEEK_START = '2026-08-24';

    private const ECO_WEEK_END = '2026-08-28';

    /** material => weight for the deterministic draw (same mix as PilotSeeder). */
    private const MATERIALS = [
        'plastic' => 45,
        'paper' => 25,
        'metal' => 12,
        'glass' => 10,
        'other' => 8,
    ];

    private const FIRST_F = [
        'Sofía', 'Valentina', 'Isabella', 'Camila', 'Luciana', 'Antonia', 'Fernanda',
        'Paula', 'Daniela', 'Renata', 'Martina', 'Emilia', 'Florencia', 'Julieta',
        'Gabriela', 'Carolina', 'Natalia', 'Andrea', 'Laura', 'Mariana', 'Sara',
        'Alejandra', 'Salomé', 'Valeria', 'Ximena', 'Paola', 'Tatiana', 'Luisa',
        'Catalina', 'Manuela',
    ];

    private const FIRST_M = [
        'Santiago', 'Mateo', 'Sebastián', 'Nicolás', 'Emiliano', 'Joaquín', 'Martín',
        'Andrés', 'Gabriel', 'Tomás', 'Samuel', 'David', 'Daniel', 'Alejandro',
        'Diego', 'Carlos', 'Juan', 'Pablo', 'Felipe', 'Simón', 'Lucas', 'Thiago',
        'Emmanuel', 'Dilan', 'Kevin', 'Brayan', 'Johan', 'Cristian', 'Esteban', 'Jacobo',
    ];

    private const SURNAMES = [
        'González', 'Pérez', 'Martínez', 'López', 'Fernández', 'Herrera', 'Rojas',
        'Castro', 'Morales', 'Ortiz', 'Guzmán', 'Vargas', 'Peña', 'Ríos', 'Soto',
        'Vega', 'Cortés', 'Silva', 'Bravo', 'Fuentes', 'Paredes', 'Méndez', 'Díaz',
        'Aguirre', 'Ramírez', 'Mendoza', 'Torres', 'Cárdenas', 'Salazar', 'Moreno',
        'Álvarez', 'Romero', 'Navarro', 'Castillo', 'Jiménez', 'Rivera', 'Mora',
        'Delgado', 'Guerrero', 'Contreras', 'Campos', 'Núñez', 'Luna', 'Godoy',
        'Sandoval', 'Orozco', 'Robles', 'Vera', 'Figueroa', 'Quintero',
    ];

    /** One teacher per class (22 classes): [name, email]. */
    private const TEACHERS = [
        ['Prof. Elena Ramírez', 'elena.ramirez@presence.test'],
        ['Prof. Carlos Mendoza', 'carlos.mendoza@presence.test'],
        ['Prof. Ana Torres', 'ana.torres@presence.test'],
        ['Prof. Jorge Herrera', 'jorge.herrera@presence.test'],
        ['Prof. Lucía Paredes', 'lucia.paredes@presence.test'],
        ['Prof. Miguel Cárdenas', 'miguel.cardenas@presence.test'],
        ['Prof. Sara Quintero', 'sara.quintero@presence.test'],
        ['Prof. Diego Fuentes', 'diego.fuentes@presence.test'],
        ['Prof. Paula Restrepo', 'paula.restrepo@presence.test'],
        ['Prof. Andrés Molina', 'andres.molina@presence.test'],
        ['Prof. Carolina Ríos', 'carolina.rios@presence.test'],
        ['Prof. Felipe Duarte', 'felipe.duarte@presence.test'],
        ['Prof. Mariana Salas', 'mariana.salas@presence.test'],
        ['Prof. Óscar Benítez', 'oscar.benitez@presence.test'],
        ['Prof. Natalia Cifuentes', 'natalia.cifuentes@presence.test'],
        ['Prof. Ricardo León', 'ricardo.leon@presence.test'],
        ['Prof. Tatiana Marín', 'tatiana.marin@presence.test'],
        ['Prof. Hugo Agudelo', 'hugo.agudelo@presence.test'],
        ['Prof. Isabel Vega', 'isabel.vega@presence.test'],
        ['Prof. Mauricio Ossa', 'mauricio.ossa@presence.test'],
        ['Prof. Gloria Estrada', 'gloria.estrada@presence.test'],
        ['Prof. Hernán Giraldo', 'hernan.giraldo@presence.test'],
    ];

    public function run(): void
    {
        if (Student::exists()) {
            $this->command->error('RealisticSeeder needs a fresh database (students already exist) — run: php artisan migrate:fresh --seeder=RealisticSeeder / ./run seed-realistic --force');
            $this->command->error('RealisticSeeder necesita una base de datos fresca (ya hay estudiantes) — ejecuta: php artisan migrate:fresh --seeder=RealisticSeeder / ./run seed-realistic --force');

            return;
        }

        mt_srand(self::RNG_SEED);

        // Bulk-seeder pragmatism (DEV fixtures only): ~330 logins would
        // each pay a full-cost bcrypt hash (~0.25 s). Minimum-cost rounds
        // keep the seed in the minutes range; every stored hash stays
        // verifiable by Hash::check (rounds travel inside the hash).
        // Pragmatismo de siembra masiva (solo fixtures DEV): ~330 accesos
        // pagarían cada uno un hash bcrypt completo (~0,25 s). Rondas
        // mínimas mantienen la siembra en minutos; cada hash sigue siendo
        // verificable con Hash::check (las rondas viajan dentro del hash).
        config(['hashing.bcrypt.rounds' => 4]);

        $targetStudents = max(22, (int) (getenv('REALISTIC_STUDENTS') ?: self::DEFAULT_STUDENTS));
        $months = max(1, min(12, (int) (getenv('REALISTIC_MONTHS') ?: self::DEFAULT_MONTHS)));

        $initialPassword = (string) settings()->studentInitialPassword();
        $windows = settings()->mealWindows();
        $lateCutoff = (string) settings()->lateCutoff();
        [$bfStart, $bfEnd] = $this->parseWindow($windows['breakfast']);
        [$luStart, $luEnd] = $this->parseWindow($windows['lunch']);

        // TASK-045 — the organization comes first, and everything below
        // is created while ACTING AS it: BelongsToSchool's creation
        // inheritance stamps every Eloquent row, so nothing in this
        // seeder has to remember to pass a school id (and nothing can
        // forget to).
        $this->school = School::provision(self::SCHOOL_NAME, self::SCHOOL_SLUG, self::SCHOOL_BRAND);
        app(CurrentSchool::class)->actAs($this->school);

        $this->seedSettings();
        $this->seedStaff($initialPassword);
        $classes = $this->seedClasses($initialPassword);
        $days = $this->schoolDays($months);
        $daySet = array_flip(array_map(fn (Carbon $d) => $d->toDateString(), $days));

        $plan = $this->buildEnrollmentPlan($targetStudents, count($days));
        $students = $this->seedStudents($classes, $plan, $days);
        $readers = $this->seedReaders($classes);
        $rewards = $this->seedRewards();

        $stats = $this->seedSemester($students, $days, $readers, $bfStart, $bfEnd, $luStart, $luEnd, $lateCutoff);
        $redemptionCount = $this->seedRedemptions($students, $days, $daySet, $rewards, $stats['earned']);
        $this->seedOperationalHistory($students, $days, $readers, $stats);

        $this->printSummary($days, $students, $classes, $readers, $stats, $redemptionCount, $initialPassword);

        // Leave the process as it found it: a seeder that stays "acting
        // as" a school would silently scope anything that runs after it.
        app(CurrentSchool::class)->forgetOverride();
    }

    /** The organization id every raw (non-Eloquent) insert must carry. */
    private function schoolId(): int
    {
        return (int) $this->school->id;
    }

    /**
     * Stamp the organization onto rows headed for a bulk insert.
     *
     * Bulk inserts bypass Eloquent entirely (that is the point — 100k
     * events), so creation inheritance cannot reach them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function owned(array $rows): array
    {
        return array_map(fn (array $row) => $row + ['school_id' => $this->schoolId()], $rows);
    }

    // ------------------------------------------------------------------
    // Static structure: settings, staff, classes, readers, rewards.
    // ------------------------------------------------------------------

    private function seedSettings(): void
    {
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
    }

    private function seedStaff(string $initialPassword): void
    {
        // One school-scoped administrator lives INSIDE this organization
        // (first row): it opens the school-branded shell — colors, crest
        // and branded report exports — one login away. The platform
        // operator is a different account seeded OUTSIDE every
        // organization by SystemAdminSeeder (stock Pulse, sees all
        // schools), so the two can never collide: different emails.
        $staff = [
            ['Administración IE Concejo', 'admin.colegio@presence.test', UserRole::Admin],
            ['Sofía Vargas', 'kitchen@presence.test', UserRole::Kitchen],
            ['Pedro Castaño', 'cocina.manana@presence.test', UserRole::Kitchen],
            ['Luz Mery Díaz', 'cocina.tarde@presence.test', UserRole::Kitchen],
        ];

        foreach ($staff as [$name, $email, $role]) {
            User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $initialPassword, 'role' => $role->value, 'must_change_password' => false],
            );
        }
    }

    /** @return array<string, SchoolClass> name => row */
    private function seedClasses(string $initialPassword): array
    {
        $classes = [];
        $teacherIndex = 0;

        foreach (range(1, 11) as $grade) {
            foreach (['A', 'B'] as $variant) {
                $name = "{$grade}° {$variant}";
                [$teacherName, $email] = self::TEACHERS[$teacherIndex % count(self::TEACHERS)];
                $teacherIndex++;

                $teacher = User::firstOrCreate(
                    ['email' => $email],
                    ['name' => $teacherName, 'password' => $initialPassword, 'role' => UserRole::Teacher->value, 'must_change_password' => false],
                );

                $class = SchoolClass::firstOrCreate(['name' => $name]);
                $class->update(['teacher_user_id' => $teacher->id]);
                $classes[$name] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param  array<string, SchoolClass>  $classes
     * @return array<string, Reader>
     */
    private function seedReaders(array $classes): array
    {
        $readers = [];

        foreach ($classes as $name => $class) {
            $readers['class:'.$name] = Reader::firstOrCreate(
                ['label' => "Aula {$name}"],
                [
                    'type' => ReaderType::Classroom->value,
                    'active_event_type' => EventType::ClassAttendance->value,
                    'api_key' => substr(hash('sha256', 'realistic/aula-'.$name), 0, 32),
                ],
            );
        }

        $readers['pae_north'] = Reader::firstOrCreate(
            ['label' => 'Cafetería Norte'],
            ['type' => ReaderType::Pae->value, 'active_event_type' => EventType::PaeBreakfast->value, 'api_key' => substr(hash('sha256', 'realistic/cafeteria-norte'), 0, 32)],
        );
        $readers['pae_south'] = Reader::firstOrCreate(
            ['label' => 'Cafetería Sur'],
            ['type' => ReaderType::Pae->value, 'active_event_type' => EventType::PaeLunch->value, 'api_key' => substr(hash('sha256', 'realistic/cafeteria-sur'), 0, 32)],
        );
        $readers['eco_patio'] = Reader::firstOrCreate(
            ['label' => 'Ecoestación Patio'],
            ['type' => ReaderType::Recycling->value, 'active_event_type' => EventType::RecyclingDeposit->value, 'api_key' => substr(hash('sha256', 'realistic/eco-patio'), 0, 32)],
        );
        $readers['eco_biblio'] = Reader::firstOrCreate(
            ['label' => 'Ecoestación Biblioteca'],
            ['type' => ReaderType::Recycling->value, 'active_event_type' => EventType::RecyclingDeposit->value, 'api_key' => substr(hash('sha256', 'realistic/eco-biblioteca'), 0, 32)],
        );

        return $readers;
    }

    /** @return array<string, Reward> name => row (stock = INITIAL units) */
    private function seedRewards(): array
    {
        $catalog = [
            ['name' => 'Canteen discount voucher', 'point_cost' => 50, 'description' => 'One-time canteen discount.', 'type' => 'voucher', 'value' => 2000, 'active' => true, 'stock' => 40],
            ['name' => 'Raffle entry', 'point_cost' => 20, 'description' => 'One entry in the end-of-term raffle.', 'type' => 'raffle', 'value' => null, 'active' => true, 'stock' => 200],
            ['name' => 'Leaderboard shout-out', 'point_cost' => 5, 'description' => 'Name highlighted on the school leaderboard.', 'type' => 'shoutout', 'value' => null, 'active' => true, 'stock' => null],
            ['name' => 'Early lunch pass', 'point_cost' => 15, 'description' => 'Skip the lunch line for one day.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => 30],
            ['name' => 'Sports hour with friends', 'point_cost' => 30, 'description' => 'An extra sports hour with friends.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => 25],
            ['name' => 'Library priority loan', 'point_cost' => 10, 'description' => 'Priority loan at the school library.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => null],
            ['name' => 'Movie afternoon', 'point_cost' => 40, 'description' => 'A movie afternoon at the school.', 'type' => 'privilege', 'value' => null, 'active' => true, 'stock' => 20],
            ['name' => 'Snack combo', 'point_cost' => 25, 'description' => 'A snack combo at the canteen.', 'type' => 'voucher', 'value' => 1500, 'active' => true, 'stock' => 60],
            // The exhausted story: cheap, tiny stock, gone by mid-semester.
            ['name' => 'Gorra del colegio', 'point_cost' => 12, 'description' => 'School cap (limited units).', 'type' => 'voucher', 'value' => 8000, 'active' => true, 'stock' => 10],
            // The archived story: visible in the catalog, never redeemable.
            ['name' => 'Mochila eco', 'point_cost' => 120, 'description' => 'Eco backpack (last semester prize).', 'type' => 'voucher', 'value' => 45000, 'active' => false, 'stock' => 5],
        ];

        $rows = [];
        foreach ($catalog as $reward) {
            $rows[$reward['name']] = Reward::firstOrCreate(['name' => $reward['name']], $reward);
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // Enrollment: grade-weighted roster with PAE mix, transfers, leavers.
    // ------------------------------------------------------------------

    /**
     * One plan row per student: class, grade, per-meal PAE flags and the
     * enrollment window (null join = day one; null leave = still here).
     *
     * @return array<int, array{grade: int, class: string, breakfast: bool, lunch: bool, joinIndex: int|null, leaveIndex: int|null}>
     */
    private function buildEnrollmentPlan(int $target, int $dayCount): array
    {
        $totalWeight = array_sum(self::GRADE_WEIGHTS);
        $counts = [];
        foreach (self::GRADE_WEIGHTS as $i => $weight) {
            $counts[$i] = (int) round($weight / $totalWeight * $target);
        }
        // Fix rounding drift on the largest grade (1°).
        $counts[0] += $target - array_sum($counts);

        $plan = [];
        foreach ($counts as $gradeIndex => $count) {
            $grade = $gradeIndex + 1;
            $countA = (int) ceil($count / 2);
            $countB = $count - $countA;

            foreach (['A' => $countA, 'B' => $countB] as $variant => $n) {
                for ($i = 0; $i < $n; $i++) {
                    [$breakfast, $lunch] = $this->drawPaeMix($grade);

                    $plan[] = [
                        'grade' => $grade,
                        'class' => "{$grade}° {$variant}",
                        'breakfast' => $breakfast,
                        'lunch' => $lunch,
                        'joinIndex' => null,
                        'leaveIndex' => null,
                    ];
                }
            }
        }

        // Mid-year transfers join between 20% and 80% of the window…
        $transferKeys = $this->pickKeys($plan, 8);
        foreach ($transferKeys as $key) {
            $plan[$key]['joinIndex'] = mt_rand((int) ($dayCount * 0.2), (int) ($dayCount * 0.8));
        }

        // …and a few students leave during the last third (their taps stop).
        $leaverKeys = $this->pickKeys($plan, 3, $transferKeys);
        foreach ($leaverKeys as $key) {
            $plan[$key]['leaveIndex'] = mt_rand((int) ($dayCount * 0.66), $dayCount - 6);
        }

        return $plan;
    }

    /**
     * Per-meal PAE mix with a vulnerability gradient: lower grades are far
     * more likely to take both meals; upper grades skew lunch-only or out.
     *
     * @return array{bool, bool} [breakfast, lunch]
     */
    private function drawPaeMix(int $grade): array
    {
        $pBoth = 0.60 - 0.032 * $grade;      // 1°≈57% … 11°≈25%
        $pBreakfastOnly = 0.16 + 0.002 * $grade;
        $pLunchOnly = 0.14 + 0.008 * $grade; // 1°≈15% … 11°≈23%

        $roll = mt_rand(1, 1000) / 1000;

        if ($roll <= $pBoth) {
            return [true, true];
        }
        if ($roll <= $pBoth + $pBreakfastOnly) {
            return [true, false];
        }
        if ($roll <= $pBoth + $pBreakfastOnly + $pLunchOnly) {
            return [false, true];
        }

        return [false, false];
    }

    /** @param  array<int, mixed>  $pool @return array<int, int> random keys, excluding $exclude */
    private function pickKeys(array $pool, int $n, array $exclude = []): array
    {
        $keys = array_diff(array_keys($pool), $exclude);
        shuffle($keys);

        return array_slice(array_values($keys), 0, $n);
    }

    /**
     * Create students + 1:1 logins + cards.
     *
     * ~5% of students get a replaced card (lost/revoked): taps before the
     * switch date reference the old card, taps after the new one.
     *
     * @param  array<string, SchoolClass>  $classes
     * @param  array<int, array{grade: int, class: string, breakfast: bool, lunch: bool, joinIndex: int|null, leaveIndex: int|null}>  $plan
     * @param  array<int, Carbon>  $days
     * @return array<int, array{student: Student, grade: int, class: string, breakfast: bool, lunch: bool, join: string|null, leave: string|null, cards: array{old: array{id: int}|null, active: array{id: int}, switch: string|null}}>
     */
    private function seedStudents(array $classes, array $plan, array $days): array
    {
        $firstDay = $days[0]->toDateString();
        $usedNames = [];
        $students = [];

        foreach ($plan as $row) {
            $name = $this->uniqueName($usedNames, $classes[$row['class']]->id);

            $student = Student::create([
                'name' => $name,
                'grade' => $row['grade'].'°',
                'pae_breakfast_enrolled' => $row['breakfast'],
                'pae_lunch_enrolled' => $row['lunch'],
                'class_id' => $classes[$row['class']]->id,
            ]);

            $join = $row['joinIndex'] !== null ? $days[$row['joinIndex']]->toDateString() : null;
            $leave = $row['leaveIndex'] !== null ? $days[$row['leaveIndex']]->toDateString() : null;
            $createdAt = ($join ?? $firstDay).' 06:00:00';
            DB::table('students')->where('id', $student->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            $students[] = [
                'student' => $student,
                'grade' => $row['grade'],
                'class' => $row['class'],
                'breakfast' => $row['breakfast'],
                'lunch' => $row['lunch'],
                'join' => $join,
                'leave' => $leave,
                'cards' => ['old' => null, 'active' => ['id' => 0], 'switch' => null],
            ];
        }

        // 1:1 logins through the REAL allocator (numeric suffixes on true
        // collisions). Real enrollments rotate on first login (ADR-044).
        (new StudentAccountService)->provisionMany(collect(array_column($students, 'student')));
        $joinStamps = [];
        foreach ($students as $entry) {
            $joinStamps[] = ['id' => $entry['student']->id, 'at' => ($entry['join'] ?? $firstDay).' 06:05:00'];
        }
        foreach ($joinStamps as $stamp) {
            DB::table('users')->where('student_id', $stamp['id'])->update(['created_at' => $stamp['at'], 'updated_at' => $stamp['at']]);
        }

        // Cards: one active each, plus replacements for ~5% (12 lost, 3 revoked).
        $uids = [];
        $cardRows = [];
        $churnKeys = $this->pickKeys($students, max(1, (int) (count($students) * 0.05)));
        $revokedKeys = array_slice($churnKeys, 0, min(3, count($churnKeys)));

        foreach ($students as $key => $entry) {
            $activeAt = ($entry['join'] ?? $firstDay).' 06:10:00';

            if (in_array($key, $churnKeys, true)) {
                $switchDay = $days[mt_rand((int) (count($days) * 0.3), (int) (count($days) * 0.7))]->toDateString();
                $oldUid = $this->uniqueUid($uids, $entry['student']->id.'-old');
                $cardRows[] = [
                    'credential_uid' => $oldUid,
                    'student_id' => $entry['student']->id,
                    'status' => in_array($key, $revokedKeys, true) ? CardStatus::Revoked->value : CardStatus::Lost->value,
                    'created_at' => $firstDay.' 06:10:00',
                    'updated_at' => $switchDay.' 07:00:00',
                    '_key' => $key,
                    '_slot' => 'old',
                ];
                $students[$key]['cards']['switch'] = $switchDay;
                $activeAt = $switchDay.' 07:00:00';
            }

            $cardRows[] = [
                'credential_uid' => $this->uniqueUid($uids, (string) $entry['student']->id),
                'student_id' => $entry['student']->id,
                'status' => CardStatus::Active->value,
                'created_at' => $activeAt,
                'updated_at' => $activeAt,
                '_key' => $key,
                '_slot' => 'active',
            ];
        }

        foreach (array_chunk($cardRows, 500) as $chunk) {
            $insert = array_map(fn (array $r) => array_diff_key($r, ['_key' => true, '_slot' => true]), $chunk);
            DB::table('cards')->insert($insert);
        }

        // Map (student, slot) => card id for the semester loop. Matched by
        // student + status (never by uid string: purely numeric uids would
        // lose a strict comparison once PHP casts array keys to int).
        $byStudent = DB::table('cards')->get(['id', 'student_id', 'status'])->groupBy('student_id');
        foreach ($students as $key => $entry) {
            foreach ($byStudent[$entry['student']->id] ?? [] as $card) {
                $slot = $card->status === CardStatus::Active->value ? 'active' : 'old';
                $students[$key]['cards'][$slot] = ['id' => $card->id];
            }
        }

        return $students;
    }

    private function uniqueName(array &$used, int $classId): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $female = mt_rand(0, 1) === 0;
            $pool = $female ? self::FIRST_F : self::FIRST_M;
            $first = $pool[array_rand($pool)];
            $last1 = self::SURNAMES[array_rand(self::SURNAMES)];
            do {
                $last2 = self::SURNAMES[array_rand(self::SURNAMES)];
            } while ($last2 === $last1);

            $name = "{$first} {$last1} {$last2}";
            if (! isset($used[$name.'|'.$classId])) {
                $used[$name.'|'.$classId] = true;

                return $name;
            }
        }

        // Pathological collision fallback (never hit with these pool sizes).
        $name = 'Estudiante '.(count($used) + 1).' '.self::SURNAMES[array_rand(self::SURNAMES)];
        $used[$name.'|'.$classId] = true;

        return $name;
    }

    private function uniqueUid(array &$used, string $seed): string
    {
        $uid = strtoupper(substr(md5('realistic/'.$seed), 0, 8));
        $suffix = 0;
        while (isset($used[$uid])) {
            $suffix++;
            $uid = strtoupper(substr(md5('realistic/'.$seed.'/'.$suffix), 0, 8));
        }
        $used[$uid] = true;

        return $uid;
    }

    // ------------------------------------------------------------------
    // The semester: one plausible school day per student per day.
    // ------------------------------------------------------------------

    /** @return array<int, Carbon> complete school days (Mon-Fri, no holidays), oldest first */
    private function schoolDays(int $months): array
    {
        $end = Carbon::yesterday(self::TZ)->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($months)->startOfDay();

        $skip = array_flip(self::HOLIDAYS);
        $days = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            if ($cursor->isWeekend()) {
                continue;
            }
            $date = $cursor->toDateString();
            if (isset($skip[$date])) {
                continue;
            }
            if ($date >= self::RECESS_START && $date <= self::RECESS_END) {
                continue;
            }
            $days[] = $cursor->copy();
        }

        return $days;
    }

    /**
     * @param  array<int, array{student: Student, grade: int, class: string, breakfast: bool, lunch: bool, join: string|null, leave: string|null, cards: array{old: array{id: int}|null, active: array{id: int}, switch: string|null}}>  $students
     * @param  array<int, Carbon>  $days
     * @param  array<string, Reader>  $readers
     * @return array{events: int, deposits: int, points: int, earned: array<int, int>}
     */
    private function seedSemester(array $students, array $days, array $readers, int $bfStart, int $bfEnd, int $luStart, int $luEnd, string $lateCutoff): array
    {
        $lateMinutes = $this->toMinutes($lateCutoff);
        $eventRows = [];
        $recyclingTaps = [];
        $earned = [];
        foreach ($students as $entry) {
            $earned[$entry['student']->id] = 0;
        }

        $totalDays = count($days);
        $paeReaders = [$readers['pae_north'], $readers['pae_south']];
        $ecoReaders = [$readers['eco_patio'], $readers['eco_biblio']];

        foreach ($days as $dayIndex => $day) {
            $date = $day->toDateString();
            $dow = $day->dayOfWeekIso; // 1=Mon … 5=Fri
            $isMonday = $dow === 1;
            $isFriday = $dow === 5;
            $isFlu = $date >= self::FLU_START && $date <= self::FLU_END;
            $isEco = $date >= self::ECO_WEEK_START && $date <= self::ECO_WEEK_END;
            $isExam = $this->isExamDay($day, $days, $dayIndex);
            $ramp = $totalDays > 1 ? $dayIndex / ($totalDays - 1) : 1.0; // 0 → 1 across the semester

            foreach ($students as $entry) {
                if ($entry['join'] !== null && $date < $entry['join']) {
                    continue;
                }
                if ($entry['leave'] !== null && $date > $entry['leave']) {
                    continue;
                }

                $sid = $entry['student']->id;
                $cardId = ($entry['cards']['switch'] !== null && $date < $entry['cards']['switch'])
                    ? $entry['cards']['old']['id']
                    : $entry['cards']['active']['id'];

                // --- absence (Monday/Friday dips + flu wave) ---
                $pAbsent = 0.06 + ($isMonday ? 0.02 : 0.0) + ($isFriday ? 0.03 : 0.0) + ($isFlu ? 0.05 : 0.0);
                if ($dayIndex < 5) {
                    $pAbsent = max(0.01, $pAbsent - 0.03); // first-week enthusiasm
                }
                if (mt_rand(1, 10000) <= (int) ($pAbsent * 10000)) {
                    // A few absent-but-enrolled kids still show up for food.
                    if (($entry['breakfast'] || $entry['lunch']) && mt_rand(1, 100) <= 3) {
                        $meal = $entry['breakfast'] && (! $entry['lunch'] || mt_rand(0, 1) === 0) ? 'breakfast' : 'lunch';
                        [$winStart, $winEnd] = $meal === 'breakfast' ? [$bfStart, $bfEnd] : [$luStart, $luEnd];
                        $eventRows[] = $this->eventRow(
                            $cardId, $paeReaders[mt_rand(0, 1)]->id,
                            $meal === 'breakfast' ? EventType::PaeBreakfast->value : EventType::PaeLunch->value,
                            $date.' '.$this->clock(mt_rand($winStart + 5, $winEnd - 10)), false, 'no_attendance'
                        );
                    }

                    continue;
                }

                // --- classroom attendance (on time vs late tail) ---
                $pLate = 0.12 + ($isMonday ? 0.06 : 0.0) + ($entry['grade'] >= 10 ? 0.04 : 0.0);
                if (mt_rand(1, 10000) <= (int) ($pLate * 10000)) {
                    $attMin = mt_rand(1, 100) <= 85 ? mt_rand($lateMinutes + 1, $lateMinutes + 40) : mt_rand($lateMinutes + 41, $lateMinutes + 75);
                } else {
                    $roll = mt_rand(1, 100);
                    $attMin = $roll <= 5 ? mt_rand(415, 419) : ($roll <= 75 ? mt_rand(420, 479) : mt_rand(480, $lateMinutes - 1));
                }
                $eventRows[] = $this->eventRow(
                    $cardId, $readers['class:'.$entry['class']]->id,
                    EventType::ClassAttendance->value, $date.' '.$this->clock($attMin), true, null
                );

                // --- PAE meals: enrolled + present + take-up ---
                foreach (['breakfast', 'lunch'] as $meal) {
                    $enrolled = $meal === 'breakfast' ? $entry['breakfast'] : $entry['lunch'];
                    [$winStart, $winEnd] = $meal === 'breakfast' ? [$bfStart, $bfEnd] : [$luStart, $luEnd];
                    $type = $meal === 'breakfast' ? EventType::PaeBreakfast->value : EventType::PaeLunch->value;

                    if (! $enrolled) {
                        // Unenrolled kids probing their luck (~2.5%/meal/day).
                        if (mt_rand(1, 1000) <= 25) {
                            $eventRows[] = $this->eventRow(
                                $cardId, $paeReaders[mt_rand(0, 1)]->id, $type,
                                $date.' '.$this->clock(mt_rand($winStart + 5, $winEnd - 10)), false, 'not_enrolled'
                            );
                        }

                        continue;
                    }

                    $take = ($meal === 'breakfast' ? 0.86 : 0.82)
                        - ($isFriday ? ($meal === 'breakfast' ? 0.05 : 0.04) : 0.0)
                        - ($isMonday && $meal === 'breakfast' ? 0.02 : 0.0)
                        + ($entry['grade'] <= 3 ? 0.05 : 0.0)
                        - ($entry['grade'] >= 10 ? ($meal === 'breakfast' ? 0.08 : 0.06) : 0.0);
                    $take = max(0.30, min(0.98, $take));

                    if (mt_rand(1, 10000) > (int) ($take * 10000)) {
                        continue; // enrolled + present, but skipped the meal
                    }

                    $mealMin = $meal === 'breakfast'
                        ? $attMin + mt_rand(5, 45)
                        : mt_rand($winStart + 5, $winEnd - 10);

                    if ($mealMin < $winStart || $mealMin >= $winEnd) {
                        continue; // arrived too late for the window: missed meal
                    }

                    $mealAt = $date.' '.$this->clock($mealMin);
                    $eventRows[] = $this->eventRow($cardId, $paeReaders[mt_rand(0, 1)]->id, $type, $mealAt, true, null);

                    // Duplicate taps: the kid taps again (flagged, counted once).
                    $dupRate = $meal === 'breakfast' ? 15 : 12; // per 1000
                    if (mt_rand(1, 1000) <= $dupRate && $mealMin + 10 < $winEnd) {
                        $eventRows[] = $this->eventRow(
                            $cardId, $paeReaders[mt_rand(0, 1)]->id, $type,
                            $date.' '.$this->clock(mt_rand($mealMin + 10, min($mealMin + 30, $winEnd - 1))), false, 'duplicate'
                        );
                    }
                }

                // --- out-of-window attempts (middle of the morning, mid-afternoon) ---
                if (mt_rand(1, 1000) <= 4) {
                    $gap = mt_rand(0, 1) === 0 ? mt_rand($bfEnd + 60, $luStart - 30) : mt_rand($luEnd + 30, $luEnd + 120);
                    $eventRows[] = $this->eventRow(
                        $cardId, $paeReaders[mt_rand(0, 1)]->id, EventType::PaeAttempt->value,
                        $date.' '.$this->clock($gap), false, 'out_of_window'
                    );
                }

                // --- recycling: adoption ramps 10% → 26% across the semester ---
                $pRecycle = (0.10 + 0.16 * $ramp)
                    * ($isFriday ? 0.8 : 1.0)
                    * ($isExam ? 0.6 : 1.0)
                    * ($isEco ? 1.9 : 1.0)
                    * ($entry['grade'] >= 6 ? 1.25 : 1.0)
                    * ($entry['grade'] <= 3 ? 0.8 : 1.0);
                $pRecycle = min(0.60, $pRecycle);

                if (mt_rand(1, 10000) <= (int) ($pRecycle * 10000)) {
                    $times = mt_rand(1, 100) <= 8 ? 2 : 1;
                    for ($i = 0; $i < $times; $i++) {
                        $at = $date.' '.$this->clock(mt_rand(545, 810), true);
                        $material = $this->drawMaterial();
                        $points = (int) config("recycling.points.{$material}", 0);
                        $eventRows[] = $this->eventRow(
                            $cardId, $ecoReaders[mt_rand(0, 1)]->id,
                            EventType::RecyclingDeposit->value, $at, true, null
                        );
                        $recyclingTaps[] = [
                            'card_id' => $cardId,
                            'student_id' => $sid,
                            'occurred_at' => $at,
                            'material' => $material,
                            'confidence' => mt_rand(62, 99) / 100,
                            'points' => $points,
                            'is_bottle' => $material !== 'paper' && mt_rand(1, 100) <= 92,
                            'is_recyclable' => $material !== 'other',
                        ];
                        $earned[$sid] += $points;
                    }
                }
            }

            if (count($eventRows) >= 2000) {
                DB::table('events')->insert($eventRows);
                $eventRows = [];
            }
        }

        // A handful of weekend taps across the semester (always flagged).
        // Only students enrolled on that weekend can tap — transfers never
        // tap before joining, leavers never tap after leaving.
        $weekends = $this->weekendDates($days[0], end($days));
        for ($i = 0; $i < min(20, count($weekends) * 2); $i++) {
            $date = $weekends[array_rand($weekends)];
            $eligible = array_values(array_filter(
                $students,
                fn (array $entry) => ($entry['join'] === null || $date >= $entry['join'])
                    && ($entry['leave'] === null || $date <= $entry['leave'])
            ));
            if ($eligible === []) {
                continue;
            }
            $entry = $eligible[array_rand($eligible)];
            $cardId = ($entry['cards']['switch'] !== null && $date < $entry['cards']['switch'])
                ? $entry['cards']['old']['id']
                : $entry['cards']['active']['id'];
            $eventRows[] = $this->eventRow(
                $cardId, $paeReaders[mt_rand(0, 1)]->id, EventType::PaeAttempt->value,
                $date.' '.$this->clock(mt_rand(480, 780), true), false, 'weekend'
            );
        }
        if ($eventRows !== []) {
            DB::table('events')->insert($eventRows);
        }

        // Link deposits + earn-ledger rows to the inserted recycling events.
        $linked = $this->linkRecyclingTaps($recyclingTaps, $days[0]->toDateString(), end($days)->toDateString());

        return [
            'events' => (int) DB::table('events')->count(),
            'deposits' => $linked['deposits'],
            'points' => $linked['points'],
            'earned' => $earned,
        ];
    }

    private function eventRow(int $cardId, int $readerId, string $type, string $at, bool $served, ?string $reason): array
    {
        return [
            'card_id' => $cardId,
            'reader_id' => $readerId,
            'type' => $type,
            'occurred_at' => $at,
            'metadata' => null,
            'served' => $served,
            'reason' => $reason,
            // TASK-045 — the reader's organization, stamped here because
            // bulk inserts never reach the model's creating hook.
            'school_id' => $this->schoolId(),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * Attach recycling_deposits (+ earn ledger, points>0 only — the same
     * rule PointsService::awardRecyclingPoints applies) to their events.
     *
     * @param  array<int, array{card_id: int, student_id: int, occurred_at: string, material: string, confidence: float, points: int, is_bottle: bool, is_recyclable: bool}>  $taps
     * @return array{deposits: int, points: int}
     */
    private function linkRecyclingTaps(array $taps, string $from, string $to): array
    {
        $queues = [];
        $rows = DB::table('events')
            ->where('type', EventType::RecyclingDeposit->value)
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('id')
            ->get(['id', 'card_id', 'occurred_at']);

        foreach ($rows as $row) {
            $at = substr((string) $row->occurred_at, 0, 19);
            $queues[$row->card_id.'|'.$at][] = $row->id;
        }

        $deposits = [];
        $ledger = [];
        $points = 0;
        foreach ($taps as $tap) {
            $key = $tap['card_id'].'|'.$tap['occurred_at'];
            if (empty($queues[$key])) {
                continue;
            }
            $eventId = array_shift($queues[$key]);
            $deposits[] = [
                'event_id' => $eventId,
                'image_path' => null, // synthetic fixtures carry no image
                'material_class' => $tap['material'],
                'confidence' => $tap['confidence'],
                'points_awarded' => $tap['points'],
                'is_bottle' => $tap['is_bottle'],
                'is_recyclable' => $tap['is_recyclable'],
                'created_at' => $tap['occurred_at'],
                'updated_at' => $tap['occurred_at'],
            ];
            if ($tap['points'] > 0) {
                $ledger[] = [
                    'student_id' => $tap['student_id'],
                    'delta' => $tap['points'],
                    'reason' => 'recycling_deposit',
                    'event_id' => $eventId,
                    'reward_id' => null,
                    'created_at' => $tap['occurred_at'],
                    'updated_at' => $tap['occurred_at'],
                ];
                $points += $tap['points'];
            }
        }

        foreach (array_chunk($deposits, 1000) as $chunk) {
            DB::table('recycling_deposits')->insert($chunk);
        }
        foreach (array_chunk($ledger, 1000) as $chunk) {
            DB::table('points_ledger')->insert($chunk);
        }

        return ['deposits' => count($deposits), 'points' => $points];
    }

    /**
     * Spend half of the loop: an exhausted cheap reward early, then ~60
     * weighted redemptions spread over the back half of the semester with
     * honest running balances (never overspending).
     *
     * @param  array<int, array{student: Student, grade: int, class: string, breakfast: bool, lunch: bool, join: string|null, leave: string|null, cards: array{old: array{id: int}|null, active: array{id: int}, switch: string|null}}>  $students
     * @param  array<int, Carbon>  $days
     * @param  array<string, bool>  $daySet
     * @param  array<string, Reward>  $rewards
     * @param  array<int, int>  $earned  student_id => lifetime earned
     */
    private function seedRedemptions(array $students, array $days, array $daySet, array $rewards, array $earned): int
    {
        $spent = array_fill_keys(array_keys($earned), 0);
        $sold = [];
        $request = 0;
        $done = 0;

        $spend = function (int $studentId, Reward $reward, string $date) use (&$spent, &$sold, &$request, &$done): void {
            $request++;
            $ledgerId = DB::table('points_ledger')->insertGetId([
                'student_id' => $studentId,
                'delta' => -$reward->point_cost,
                'reason' => 'redemption',
                'event_id' => null,
                'reward_id' => $reward->id,
                'created_at' => $date.' 12:30:00',
                'updated_at' => $date.' 12:30:00',
            ]);
            DB::table('reward_redemptions')->insert([
                'reward_id' => $reward->id,
                'student_id' => $studentId,
                'ledger_id' => $ledgerId,
                'points_spent' => $reward->point_cost,
                'request_id' => 'realistic-'.str_pad((string) $request, 4, '0', STR_PAD_LEFT),
                'created_at' => $date.' 12:30:00',
                'updated_at' => $date.' 12:30:00',
            ]);
            $spent[$studentId] += $reward->point_cost;
            $sold[$reward->id] = ($sold[$reward->id] ?? 0) + 1;
            $done++;
        };

        // Phase 1 — the Gorra del colegio sells out in June (10 units).
        $cap = $rewards['Gorra del colegio'];
        $juneDays = array_values(array_filter(
            array_map(fn (Carbon $d) => $d->toDateString(), $days),
            fn (string $d) => str_starts_with($d, '2026-06-')
        ));
        $ranked = $students;
        usort($ranked, fn ($a, $b) => $earned[$b['student']->id] <=> $earned[$a['student']->id]);
        $capSold = 0;
        foreach ($ranked as $entry) {
            if ($capSold >= 10 || $juneDays === []) {
                break;
            }
            $sid = $entry['student']->id;
            if ($cap->point_cost <= $earned[$sid] - $spent[$sid]) {
                $spend($sid, $cap, $juneDays[$capSold % count($juneDays)]);
                $capSold++;
            }
        }

        // Phase 2 — weighted redemptions over the back half of the window.
        $dates = array_map(fn (Carbon $d) => $d->toDateString(), $days);
        $lateDates = array_values(array_filter($dates, fn (string $d) => $d >= $dates[max(0, (int) (count($dates) / 2))]));
        sort($lateDates);

        $weights = [
            'Leaderboard shout-out' => 45,
            'Raffle entry' => 30,
            'Snack combo' => 8,
            'Library priority loan' => 5,
            'Early lunch pass' => 5,
            'Sports hour with friends' => 3,
            'Movie afternoon' => 2,
            'Canteen discount voucher' => 2,
        ];

        $attempts = 0;
        while ($done - $capSold < 60 && $attempts < 3000 && $lateDates !== []) {
            $attempts++;
            $entry = $students[array_rand($students)];
            $sid = $entry['student']->id;
            $balance = $earned[$sid] - $spent[$sid];

            $affordable = [];
            foreach ($weights as $name => $weight) {
                $reward = $rewards[$name];
                $remaining = $reward->stock !== null ? $reward->stock - ($sold[$reward->id] ?? 0) : null;
                if ($balance >= $reward->point_cost && $remaining !== 0) {
                    $affordable[$name] = $weight;
                }
            }
            if ($affordable === []) {
                continue;
            }

            $reward = $rewards[$this->drawWeighted($affordable)];
            $date = $lateDates[array_rand($lateDates)];
            if ($entry['join'] !== null && $date < $entry['join']) {
                continue;
            }
            if ($entry['leave'] !== null && $date > $entry['leave']) {
                continue;
            }
            $spend($sid, $reward, $date);
        }

        // Final stock = initial units minus units sold (the cap lands on 0).
        foreach ($rewards as $reward) {
            if ($reward->stock !== null) {
                $reward->update(['stock' => max(0, $reward->stock - ($sold[$reward->id] ?? 0))]);
            }
        }

        return $done;
    }

    /**
     * Transient + broadcast history: consumed/expired/active pairings, the
     * bottle-first capture lifecycle, roster broadcasts and a bounded tail
     * of recycling feed frames (last 5 school days, so the feed tables stay
     * proportional instead of duplicating the whole semester).
     *
     * @param  array<int, array{student: Student, grade: int, class: string, breakfast: bool, lunch: bool, join: string|null, leave: string|null, cards: array{old: array{id: int}|null, active: array{id: int}, switch: string|null}}>  $students
     * @param  array<int, Carbon>  $days
     * @param  array<string, Reader>  $readers
     * @param  array{events: int, deposits: int, points: int, earned: array<int, int>}  $stats
     */
    private function seedOperationalHistory(array $students, array $days, array $readers, array $stats): void
    {
        $classReaders = array_values(array_filter($readers, fn ($r, $k) => str_starts_with($k, 'class:'), ARRAY_FILTER_USE_BOTH));
        $firstDay = $days[0]->toDateString();
        $lastDay = end($days)->toDateString();

        // --- pending pairings: 7 consumed, 1 expired, 1 armed, 1 armed+rejected ---
        $pairRows = [];
        for ($i = 0; $i < 7; $i++) {
            $entry = $students[array_rand($students)];
            // A consumed pairing is stamped with the card it created: date
            // it on/after the card's issue (churned students) so history
            // never shows a card consumed before it existed.
            $atDay = $days[array_rand($days)];
            // …on/after the card's issue (churned students) and on/after
            // enrollment (transfers), so history never shows a card
            // consumed before it existed.
            $earliest = $firstDay;
            if ($entry['cards']['switch'] !== null && $entry['cards']['switch'] > $earliest) {
                $earliest = $entry['cards']['switch'];
            }
            if ($entry['join'] !== null && $entry['join'] > $earliest) {
                $earliest = $entry['join'];
            }
            if ($atDay->toDateString() < $earliest) {
                $atDay = $days[array_rand($days)];
                if ($atDay->toDateString() < $earliest) {
                    $atDay = end($days);
                }
            }
            $at = $atDay->toDateString().' 10:'.str_pad((string) mt_rand(5, 50), 2, '0', STR_PAD_LEFT).':00';
            $pairRows[] = [
                'student_id' => $entry['student']->id,
                'reader_id' => $classReaders[array_rand($classReaders)]->id,
                'card_id' => $entry['cards']['active']['id'],
                'expires_at' => Carbon::parse($at)->addSeconds(45)->toDateTimeString(),
                'consumed_at' => Carbon::parse($at)->addSeconds(mt_rand(15, 40))->toDateTimeString(),
                'last_rejected_uid' => null,
                'last_rejected_reason' => null,
                'last_rejected_at' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }
        $expiredAt = Carbon::parse($lastDay.' 09:00:00');
        $expiredEntry = $students[array_rand($students)];
        $pairRows[] = [
            'student_id' => $expiredEntry['student']->id,
            'reader_id' => $classReaders[array_rand($classReaders)]->id,
            'card_id' => null,
            'expires_at' => $expiredAt->copy()->addSeconds(45)->toDateTimeString(),
            'consumed_at' => null,
            'last_rejected_uid' => null,
            'last_rejected_reason' => null,
            'last_rejected_at' => null,
            'created_at' => $expiredAt->toDateTimeString(),
            'updated_at' => $expiredAt->toDateTimeString(),
        ];
        $now = Carbon::now();
        $armedEntry = $students[array_rand($students)];
        $pairRows[] = [
            'student_id' => $armedEntry['student']->id,
            'reader_id' => $classReaders[array_rand($classReaders)]->id,
            'card_id' => null,
            'expires_at' => $now->copy()->addSeconds(35)->toDateTimeString(),
            'consumed_at' => null,
            'last_rejected_uid' => null,
            'last_rejected_reason' => null,
            'last_rejected_at' => null,
            'created_at' => $now->copy()->subSeconds(10)->toDateTimeString(),
            'updated_at' => $now->copy()->subSeconds(10)->toDateTimeString(),
        ];
        $rejectedEntry = $students[array_rand($students)];
        $pairRows[] = [
            'student_id' => $rejectedEntry['student']->id,
            'reader_id' => $classReaders[array_rand($classReaders)]->id,
            'card_id' => null,
            'expires_at' => $now->copy()->addSeconds(25)->toDateTimeString(),
            'consumed_at' => null,
            'last_rejected_uid' => 'A1B2C3D4',
            'last_rejected_reason' => 'already_paired',
            'last_rejected_at' => $now->copy()->subSeconds(5)->toDateTimeString(),
            'created_at' => $now->copy()->subSeconds(20)->toDateTimeString(),
            'updated_at' => $now->copy()->subSeconds(5)->toDateTimeString(),
        ];
        DB::table('pending_pairings')->insert($pairRows);

        // --- pending captures: 5 accepted (linked to real recent taps), 2 expired, 1 open ---
        $recentTaps = DB::table('events')
            ->where('type', EventType::RecyclingDeposit->value)
            ->where('occurred_at', '>=', $days[max(0, count($days) - 15)]->toDateString().' 00:00:00')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'card_id', 'occurred_at', 'reader_id']);

        $captureRows = [];
        foreach ($recentTaps as $tap) {
            $at = Carbon::parse($tap->occurred_at);
            $captureRows[] = [
                'reader_id' => $tap->reader_id,
                'image_path' => 'recycling-captures/realistic-synthetic.jpg', // placeholder: fixtures carry no image
                'event_id' => $tap->id,
                'card_id' => $tap->card_id,
                'state' => 'accepted',
                'expires_at' => $at->copy()->addMinutes(5)->toDateTimeString(),
                'created_at' => $at->copy()->subMinutes(5)->toDateTimeString(),
                'updated_at' => $at->toDateTimeString(),
            ];
        }
        for ($i = 0; $i < 2; $i++) {
            $at = Carbon::parse($days[mt_rand(10, max(10, count($days) - 30))]->toDateString().' 11:00:00');
            $captureRows[] = [
                'reader_id' => $readers['eco_patio']->id,
                'image_path' => 'recycling-captures/realistic-synthetic.jpg',
                'event_id' => null,
                'card_id' => null,
                'state' => 'expired',
                'expires_at' => $at->copy()->addMinutes(5)->toDateTimeString(),
                'created_at' => $at->toDateTimeString(),
                'updated_at' => $at->copy()->addMinutes(5)->toDateTimeString(),
            ];
        }
        $captureRows[] = [
            'reader_id' => $readers['eco_biblio']->id,
            'image_path' => 'recycling-captures/realistic-synthetic.jpg',
            'event_id' => null,
            'card_id' => null,
            'state' => 'awaiting_card',
            'expires_at' => $now->copy()->addMinutes(4)->toDateTimeString(),
            'created_at' => $now->copy()->subMinute()->toDateTimeString(),
            'updated_at' => $now->copy()->subMinute()->toDateTimeString(),
        ];
        DB::table('pending_captures')->insert($captureRows);

        // --- roster broadcasts: classes, the initial import, transfers, readers ---
        $rosterRows = [];
        foreach (array_keys(array_filter($readers, fn ($r, $k) => str_starts_with($k, 'class:'), ARRAY_FILTER_USE_BOTH)) as $key) {
            $className = substr($key, strlen('class:'));
            $rosterRows[] = [
                'type' => 'class_created',
                'payload' => json_encode(['class' => $className]),
                'created_at' => $firstDay.' 06:00:00',
                'updated_at' => $firstDay.' 06:00:00',
            ];
        }
        $rosterRows[] = [
            'type' => 'students_imported',
            'payload' => json_encode(['count' => count($students), 'source' => 'csv', 'note' => 'initial enrollment']),
            'created_at' => $firstDay.' 06:05:00',
            'updated_at' => $firstDay.' 06:05:00',
        ];
        foreach ($students as $entry) {
            if ($entry['join'] === null) {
                continue;
            }
            $rosterRows[] = [
                'type' => 'student_created',
                'payload' => json_encode(['student_id' => $entry['student']->id, 'student_name' => $entry['student']->name, 'class' => $entry['class'], 'note' => 'mid-year transfer']),
                'created_at' => $entry['join'].' 07:00:00',
                'updated_at' => $entry['join'].' 07:00:00',
            ];
        }
        $rosterRows[] = [
            'type' => 'students_imported',
            'payload' => json_encode(['count' => 8, 'source' => 'csv', 'note' => 'mid-year intake']),
            'created_at' => $days[(int) (count($days) / 2)]->toDateString().' 07:00:00',
            'updated_at' => $days[(int) (count($days) / 2)]->toDateString().' 07:00:00',
        ];
        $rosterRows[] = [
            'type' => 'reader_created',
            'payload' => json_encode(['reader' => 'Ecoestación Biblioteca']),
            'created_at' => $days[min(20, count($days) - 1)]->toDateString().' 08:00:00',
            'updated_at' => $days[min(20, count($days) - 1)]->toDateString().' 08:00:00',
        ];
        $rosterRows[] = [
            'type' => 'reader_updated',
            'payload' => json_encode(['reader' => 'Cafetería Sur', 'active_event_type' => 'PAE_LUNCH']),
            'created_at' => $days[min(40, count($days) - 1)]->toDateString().' 08:00:00',
            'updated_at' => $days[min(40, count($days) - 1)]->toDateString().' 08:00:00',
        ];
        DB::table('roster_updates')->insert($this->owned($rosterRows));

        // --- recycling feed frames for the last 5 school days ---
        $tailDays = array_slice(array_map(fn (Carbon $d) => $d->toDateString(), $days), -5);
        $tailFrom = $tailDays[0].' 00:00:00';
        $tailTaps = DB::table('events')
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->leftJoin('recycling_deposits', 'recycling_deposits.event_id', '=', 'events.id')
            ->where('events.type', EventType::RecyclingDeposit->value)
            ->where('events.occurred_at', '>=', $tailFrom)
            ->orderBy('events.id')
            ->get(['events.id', 'events.occurred_at', 'students.id as student_id', 'students.name as student_name',
                'recycling_deposits.material_class', 'recycling_deposits.confidence', 'recycling_deposits.points_awarded']);

        $running = [];
        $feedRows = [];
        foreach ($tailTaps as $tap) {
            $running[$tap->student_id] = ($running[$tap->student_id] ?? 0) + (int) $tap->points_awarded;
            $at = substr((string) $tap->occurred_at, 0, 19);
            $feedRows[] = [
                'type' => 'validated',
                'payload' => json_encode(['event_id' => $tap->id, 'student' => $tap->student_name, 'material' => $tap->material_class, 'confidence' => (float) $tap->confidence]),
                'created_at' => $at,
                'updated_at' => $at,
            ];
            if ((int) $tap->points_awarded > 0) {
                $feedRows[] = [
                    'type' => 'points_awarded',
                    'payload' => json_encode(['student_id' => $tap->student_id, 'student' => $tap->student_name, 'points' => (int) $tap->points_awarded, 'earned_to_date' => $running[$tap->student_id]]),
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }

        $recentRedemptions = DB::table('reward_redemptions')
            ->join('rewards', 'rewards.id', '=', 'reward_redemptions.reward_id')
            ->join('students', 'students.id', '=', 'reward_redemptions.student_id')
            ->where('reward_redemptions.created_at', '>=', $tailFrom)
            ->orderBy('reward_redemptions.id')
            ->get(['reward_redemptions.id', 'reward_redemptions.created_at', 'reward_redemptions.points_spent',
                'rewards.name as reward_name', 'students.id as student_id', 'students.name as student_name']);

        foreach ($recentRedemptions as $redemption) {
            $at = substr((string) $redemption->created_at, 0, 19);
            $feedRows[] = [
                'type' => 'reward_redeemed',
                'payload' => json_encode(['redemption_id' => $redemption->id, 'student' => $redemption->student_name, 'reward' => $redemption->reward_name, 'points_spent' => (int) $redemption->points_spent]),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        $balances = [];
        foreach ($stats['earned'] as $sid => $points) {
            $balances[$sid] = $points - (int) DB::table('points_ledger')->where('student_id', $sid)->where('delta', '<', 0)->sum('delta');
        }
        arsort($balances);
        $top = [];
        foreach (array_slice($balances, 0, 10, true) as $sid => $balance) {
            $top[] = ['student_id' => $sid, 'balance' => $balance];
        }
        $feedRows[] = [
            'type' => 'leaderboard_updated',
            'payload' => json_encode(['top' => $top]),
            'created_at' => $lastDay.' 18:00:00',
            'updated_at' => $lastDay.' 18:00:00',
        ];

        foreach (array_chunk($feedRows, 500) as $chunk) {
            DB::table('recycling_updates')->insert($this->owned($chunk));
        }
    }

    // ------------------------------------------------------------------
    // Small helpers.
    // ------------------------------------------------------------------

    /** @return array{int, int} [startMinutes, endMinutes] for an HH:MM window */
    private function parseWindow(array $window): array
    {
        return [$this->toMinutes($window['start']), $this->toMinutes($window['end'])];
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private function clock(int $minutes, bool $withSeconds = false): string
    {
        $seconds = $withSeconds ? str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT) : '00';

        return str_pad((string) intdiv($minutes, 60), 2, '0', STR_PAD_LEFT).':'
            .str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).':'.$seconds;
    }

    /** True on the last 5 school days of each calendar month (exam weeks recycle less). */
    private function isExamDay(Carbon $day, array $days, int $index): bool
    {
        $month = $day->format('Y-m');
        $laterSameMonth = 0;
        for ($i = $index + 1; $i < count($days); $i++) {
            if ($days[$i]->format('Y-m') !== $month) {
                break;
            }
            $laterSameMonth++;
        }

        return $laterSameMonth < 5;
    }

    /** @return array<int, string> weekend Y-m-d dates across the window */
    private function weekendDates(Carbon $first, Carbon $last): array
    {
        $dates = [];
        for ($cursor = $first->copy(); $cursor->lte($last); $cursor->addDay()) {
            if ($cursor->isWeekend()) {
                $dates[] = $cursor->toDateString();
            }
        }

        return $dates;
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

    /** @param  array<string, int>  $weights */
    private function drawWeighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $name => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $name;
            }
        }

        return array_key_first($weights);
    }

    /**
     * @param  array<int, Carbon>  $days
     * @param  array<int, array{student: Student, grade: int, class: string, breakfast: bool, lunch: bool, join: string|null, leave: string|null, cards: array{old: array{id: int}|null, active: array{id: int}, switch: string|null}}>  $students
     * @param  array<string, SchoolClass>  $classes
     * @param  array<string, Reader>  $readers
     * @param  array{events: int, deposits: int, points: int, earned: array<int, int>}  $stats
     */
    private function printSummary(array $days, array $students, array $classes, array $readers, array $stats, int $redemptions, string $initialPassword): void
    {
        $line = str_repeat('=', 74);
        $this->command->warn($line);
        $this->command->warn(' REALISTIC DATA — a full semester, school-shaped trends (deterministic reseed)');
        $this->command->warn(' DATOS REALISTAS — un semestre completo, tendencias escolares (resiembra determinista)');
        $this->command->warn($line);
        $this->command->info(sprintf(
            ' [EN] Organization: %s (slug %s, branding %s) — every row below belongs to it.',
            self::SCHOOL_NAME, self::SCHOOL_SLUG, self::SCHOOL_BRAND,
        ));
        $this->command->info(sprintf(
            ' [ES] Organización: %s (slug %s, identidad %s) — todo lo de abajo le pertenece.',
            self::SCHOOL_NAME, self::SCHOOL_SLUG, self::SCHOOL_BRAND,
        ));

        $first = $days[0]->toDateString();
        $last = end($days)->toDateString();
        $this->command->info(sprintf(
            ' [EN] %d students (%d classes, %d teachers), %d readers, %d school days (%s → %s)',
            count($students), count($classes), count(self::TEACHERS), count($readers), count($days), $first, $last
        ));
        $this->command->info(sprintf(
            ' [ES] %d estudiantes (%d cursos, %d profesores), %d lectores, %d días de clase (%s → %s)',
            count($students), count($classes), count(self::TEACHERS), count($readers), count($days), $first, $last
        ));
        $this->command->info(sprintf(
            ' [EN] %d taps (%d served), %d recycling deposits, %d points awarded, %d redemptions',
            $stats['events'], (int) DB::table('events')->where('served', true)->count(),
            $stats['deposits'], $stats['points'], $redemptions
        ));
        $this->command->info(sprintf(
            ' [ES] %d toques (%d servidos), %d depósitos de reciclaje, %d puntos otorgados, %d canjes',
            $stats['events'], (int) DB::table('events')->where('served', true)->count(),
            $stats['deposits'], $stats['points'], $redemptions
        ));

        $byGrade = [];
        $mix = ['both' => 0, 'breakfast' => 0, 'lunch' => 0, 'neither' => 0];
        foreach ($students as $entry) {
            $byGrade[$entry['grade']] = ($byGrade[$entry['grade']] ?? 0) + 1;
            $key = $entry['breakfast'] ? ($entry['lunch'] ? 'both' : 'breakfast') : ($entry['lunch'] ? 'lunch' : 'neither');
            $mix[$key]++;
        }
        ksort($byGrade);
        $this->command->info(' [EN] Enrollment per grade (lower-grades-heavy) / Matrícula por grado:');
        $this->command->table(
            ['Grade / Grado', 'Students / Estudiantes'],
            array_map(fn ($grade, $count) => ["{$grade}°", $count], array_keys($byGrade), array_values($byGrade)),
        );
        $this->command->info(' [EN] PAE mix (morning=breakfast, afternoon=lunch) / Mezcla PAE (mañana=desayuno, tarde=almuerzo):');
        $this->command->table(
            ['Mix / Mezcla', 'Students / Estudiantes'],
            [
                ['Both / Ambos', $mix['both']],
                ['Breakfast only / Solo desayuno', $mix['breakfast']],
                ['Lunch only / Solo almuerzo', $mix['lunch']],
                ['Neither / Ninguno', $mix['neither']],
            ],
        );

        $this->command->info(" [EN] Staff logins (password: {$initialPassword}) / Accesos del personal (clave: {$initialPassword}):");
        $staffRows = [
            ['Administración IE Concejo', 'admin.colegio@presence.test', 'admin'],
            ['Sofía Vargas', 'kitchen@presence.test', 'kitchen'],
            ['Pedro Castaño', 'cocina.manana@presence.test', 'kitchen'],
            ['Luz Mery Díaz', 'cocina.tarde@presence.test', 'kitchen'],
        ];
        foreach (self::TEACHERS as [$name, $email]) {
            $staffRows[] = [$name, $email, 'teacher'];
        }
        $this->command->table(['User / Usuario', 'Email', 'Role / Rol'], $staffRows);

        $this->command->info(' [EN] Readers — send as header: Authorization: Bearer <api_key>');
        $this->command->info(' [ES] Lectores — envía como cabecera: Authorization: Bearer <api_key>');
        $this->command->table(
            ['Reader / Lector', 'Type / Tipo', 'api_key (Bearer)'],
            array_map(fn ($r) => [$r->label, $r->type->value, $r->api_key], array_values($readers)),
        );

        $this->command->warn($line);
        $this->command->warn(" [EN] Student logins follow {first-name}@{$this->accountDomain()} (rotation on first login) — see the users table.");
        $this->command->warn(" [ES] Los accesos de estudiantes siguen {nombre}@{$this->accountDomain()} (rotación al primer login) — ver la tabla users.");
        $this->command->warn(' [EN] The school administrator above opens the branded shell — the single system admin (stock Pulse, all schools) is seeded separately (SystemAdminSeeder).');
        $this->command->warn(' [ES] El administrador del colegio abre la interfaz con identidad — el único admin del sistema (Pulse estándar, todos los colegios) se siembra aparte (SystemAdminSeeder).');
        $this->command->warn($line);
    }

    private function accountDomain(): string
    {
        return (string) settings()->studentEmailDomain();
    }
}
