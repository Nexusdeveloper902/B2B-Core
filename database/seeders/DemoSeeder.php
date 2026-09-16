<?php

namespace Database\Seeders;

use App\Enums\CardStatus;
use App\Enums\EventType;
use App\Enums\ReaderType;
use App\Enums\UserRole;
use App\Models\Card;
use App\Models\PresenceEvent;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo seeder (Phase A). This is how the whole platform is exercised by
 * hand before any hardware exists: it prints every credential_uid and
 * reader api_key to the console, ready to paste into Postman/curl.
 *
 * Output is bilingual (EN + ES) per the platform requirement.
 *
 * TASK-037 — the PAE full program demo dataset: per-meal enrollment
 * (breakfast/lunch independently), the kitchen user, the default
 * meal-serving settings, a dedicated cafeteria reader (auto meal
 * detection), and one past school day's worth of scenario events —
 * accepted meals, an out-of-window attempt, a duplicate, not-enrolled
 * rejections, a no-attendance rejection and a missed meal. TODAY is
 * deliberately left clean so demo taps and the e2e journey always start
 * from an empty school day.
 *
 * DEV/DEMO ONLY (finding 7.2): every credential below is a published,
 * shared secret — never run this seeder against a production database.
 *
 * TASK-039 — seeder-convention refresh: student emails resolve through
 * the accounts preset (not a hardcoded domain), logins allocate through
 * the real StudentAccountService allocator, and the printed table shows
 * the ACTUAL account emails (never a recomputed slug that could lie).
 */
class DemoSeeder extends Seeder
{
    /** The only supported school right now: every demo row belongs to it. */
    private const SCHOOL_NAME = 'IE Concejo de Sabaneta J.M.C.B';

    private const SCHOOL_SLUG = 'ie-concejo-de-sabaneta';

    /** The branding profile in config/branding.php this school renders with. */
    private const SCHOOL_BRAND = 'ie-concejo-de-sabaneta';

    public function run(): void
    {
        // The organization comes first, and everything below is created
        // while ACTING AS it: BelongsToSchool's creation inheritance
        // stamps every row, and the demo admin therefore opens the
        // school-branded shell (same pattern as RealisticSeeder).
        $school = School::provision(self::SCHOOL_NAME, self::SCHOOL_SLUG, self::SCHOOL_BRAND);
        app(CurrentSchool::class)->actAs($school);

        // ---------- Users ----------
        // TASK-030-A (ADR-044) — demo accounts opt OUT of the forced
        // rotation (must_change_password=false): one-tap demo logins
        // keep working. Real enrollments (desk create/import) opt IN.
        // TASK-039 — the fixture password equals the effective accounts
        // preset (config fallback before the settings rows exist), so an
        // overridden STUDENT_INITIAL_PASSWORD still yields matching
        // one-tap logins and an honest printed table.
        $initialPassword = (string) settings()->studentInitialPassword();

        $admin = User::firstOrCreate(
            ['email' => 'admin@presence.test'],
            [
                'name' => 'School Admin',
                'password' => $initialPassword,
                'role' => UserRole::Admin->value,
                'must_change_password' => false,
            ],
        );

        // TASK-037 — the kitchen account: normal login flow, restricted
        // to the /kitchen meal-service workflow (ADR-054).
        $kitchen = User::firstOrCreate(
            ['email' => 'kitchen@presence.test'],
            [
                'name' => 'Sofía Vargas',
                'password' => $initialPassword,
                'role' => UserRole::Kitchen->value,
                'must_change_password' => false,
            ],
        );

        // ---------- Classes ----------
        // TASK-033 — one A/B pair per grade (1–11, no grade 0): the
        // class dropdown actually changes now. 5° B stays the demo
        // home (teacher + the four seeded students live there).
        $class = null;
        foreach (range(1, 11) as $gradeNumber) {
            foreach (['A', 'B'] as $variant) {
                $row = SchoolClass::firstOrCreate(
                    ['name' => "{$gradeNumber}° {$variant}"],
                    ['teacher_user_id' => null],
                );
                if ($row->name === '5° B') {
                    $class = $row;
                }
            }
        }

        $teacher = User::firstOrCreate(
            ['email' => 'teacher@presence.test'],
            [
                'name' => 'Prof. Elena Ramírez',
                'password' => $initialPassword,
                'role' => UserRole::Teacher->value,
                'must_change_password' => false,
            ],
        );

        $class->update(['teacher_user_id' => $teacher->id]);

        // ---------- TASK-037: runtime settings (ADR-055) ----------
        // Seed the canonical defaults as rows (the settings desk then
        // shows configured values instead of implicit defaults). The
        // values resolve through config — env overrides apply here too
        // (the e2e suite exports full-day windows before seeding).
        $settings = [
            'pae.breakfast_start' => config('presence.pae.breakfast_start', '06:30'),
            'pae.breakfast_end' => config('presence.pae.breakfast_end', '08:30'),
            'pae.lunch_start' => config('presence.pae.lunch_start', '11:30'),
            'pae.lunch_end' => config('presence.pae.lunch_end', '13:30'),
            'attendance.late_cutoff' => config('presence.late_cutoff', '08:15'),
            'pairing.window_seconds' => config('presence.pairing_window_seconds', 45),
            'accounts.student_email_domain' => config('presence.student_email_domain', 'presence.test'),
            'accounts.student_initial_password' => config('presence.student_initial_password', 'password'),
        ];

        foreach ($settings as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        SettingsService::flushCache();

        // ---------- Students + cards (per-meal PAE enrollment) ----------
        // TASK-037 — breakfast and lunch enroll independently: the demo
        // roster covers all four combinations.
        // TASK-039 — the account preset resolves through settings (the
        // same effective values the desks use), never hardcoded: a
        // school that overrides the domain/password gets seeded logins
        // that match its own convention (the allocator resolves the
        // domain internally; $initialPassword was read above).
        $accounts = new StudentAccountService;
        $students = [
            ['name' => 'Maria González', 'grade' => '5°', 'breakfast' => true, 'lunch' => true],
            ['name' => 'Carlos Pérez', 'grade' => '5°', 'breakfast' => true, 'lunch' => false],
            ['name' => 'Ana Martínez', 'grade' => '5°', 'breakfast' => false, 'lunch' => true],
            ['name' => 'Diego López', 'grade' => '5°', 'breakfast' => false, 'lunch' => false],
            ['name' => 'Lucía Fernández', 'grade' => '5°', 'breakfast' => true, 'lunch' => true],
        ];

        $cards = [];
        $studentRows = [];

        foreach ($students as $data) {
            // Match the students_name_class_unique invariant (name IN a
            // class), not the bare name: re-runs stay idempotent even if
            // the roster ever spreads across classes.
            $student = Student::firstOrCreate(
                ['name' => $data['name'], 'class_id' => $class->id],
                [
                    'grade' => $data['grade'],
                    'pae_breakfast_enrolled' => $data['breakfast'],
                    'pae_lunch_enrolled' => $data['lunch'],
                ],
            );

            // TASK-025 item 5 (spec §11/§30) — the 1:1 student account
            // layer: a users row REFERENCING the students row (never a
            // second identity). Same login path as staff.
            // TASK-039 — allocate through the REAL allocator (numeric
            // suffixes on true collisions) and skip when the account
            // already exists (the ADR-044 idempotency: keep, never
            // re-issue) — firstOrCreate on the email alone could hand
            // a student somebody else's login.
            if ($student->account()->first() === null) {
                User::create([
                    'name' => $data['name'],
                    'email' => $accounts->emailForName($data['name']),
                    'password' => $initialPassword,
                    'role' => UserRole::Student->value,
                    'student_id' => $student->id,
                    'must_change_password' => false,
                ]);
            }

            $studentRows[$data['name']] = $student;

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
            ['label' => 'Demo Reader — Classroom'],
            [
                'type' => ReaderType::Classroom->value,
                'active_event_type' => EventType::ClassAttendance->value,
                'api_key' => Str::random(32),
            ],
        );

        // TASK-037 — the cafeteria reader: type `pae`, so its taps go
        // through the serving engine with AUTO meal detection from the
        // configured windows (no manual meal mode). The mode column
        // stays informational for this reader type.
        $cafeteriaReader = Reader::firstOrCreate(
            ['label' => 'Demo Reader — Cafeteria'],
            [
                'type' => ReaderType::Pae->value,
                'active_event_type' => EventType::PaeLunch->value,
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

        // ---------- TASK-037: one past school day of PAE scenarios ----------
        $scenarioDate = $this->seedScenarioDay($studentRows, $cafeteriaReader, $classroomReader);

        // ---------- Console output (hard requirement, bilingual) ----------
        $this->printCredentials($cards, $classroomReader, $cafeteriaReader, $recyclingReader, $admin, $teacher, $kitchen, array_values($studentRows), $scenarioDate, $initialPassword);

        // Leave the process as it found it: a seeder that stays "acting
        // as" a school would silently scope anything that runs after it.
        app(CurrentSchool::class)->forgetOverride();
    }

    /**
     * Seed ONE past school day demonstrating every PAE serving outcome.
     * Today stays empty (demo taps + the e2e journey start clean).
     *
     * @param  array<string, Student>  $students
     * @return string the scenario date (Y-m-d)
     */
    private function seedScenarioDay(array $students, Reader $cafeteria, Reader $classroom): string
    {
        $day = Carbon::yesterday();
        while (! $day->isWeekday()) {
            $day = $day->subDay();
        }

        $tap = function (Student $student, Reader $reader, string $time, string $type, bool $served, ?string $reason = null) use ($day): void {
            PresenceEvent::firstOrCreate(
                [
                    'card_id' => $student->cards()->first()->id,
                    'type' => $type,
                    'occurred_at' => $day->format('Y-m-d').' '.$time.':00',
                ],
                [
                    'reader_id' => $reader->id,
                    'served' => $served,
                    'reason' => $reason,
                ],
            );
        };

        // Maria (both meals): attendance → breakfast + lunch served, then
        // a duplicate lunch attempt (flagged, counted once).
        $maria = $students['Maria González'];
        $tap($maria, $classroom, '07:05', EventType::ClassAttendance->value, true);
        $tap($maria, $cafeteria, '07:20', EventType::PaeBreakfast->value, true);
        $tap($maria, $cafeteria, '12:10', EventType::PaeLunch->value, true);
        $tap($maria, $cafeteria, '12:40', EventType::PaeLunch->value, false, 'duplicate');

        // Carlos (breakfast only): attendance → breakfast served; a lunch
        // attempt is rejected (not enrolled for lunch) and a 15:30 tap
        // falls outside every window (flagged out-of-window attempt).
        $carlos = $students['Carlos Pérez'];
        $tap($carlos, $classroom, '07:10', EventType::ClassAttendance->value, true);
        $tap($carlos, $cafeteria, '07:30', EventType::PaeBreakfast->value, true);
        $tap($carlos, $cafeteria, '12:15', EventType::PaeLunch->value, false, 'not_enrolled');
        $tap($carlos, $cafeteria, '15:30', EventType::PaeAttempt->value, false, 'out_of_window');

        // Ana (lunch only): attendance + breakfast attempt rejected (not
        // enrolled for breakfast); lunch NOT served → the missed-meal
        // report's canonical case (present + enrolled + not served).
        $ana = $students['Ana Martínez'];
        $tap($ana, $classroom, '07:15', EventType::ClassAttendance->value, true);
        $tap($ana, $cafeteria, '07:40', EventType::PaeBreakfast->value, false, 'not_enrolled');

        // Diego (neither meal): absent — no attendance, no meal.
        $diego = $students['Diego López'];
        unset($diego);

        // Lucía (both meals): no attendance tap — her lunch attempt is
        // rejected (no prior same-day class attendance).
        $lucia = $students['Lucía Fernández'];
        $tap($lucia, $cafeteria, '12:25', EventType::PaeLunch->value, false, 'no_attendance');

        return $day->toDateString();
    }

    private function printCredentials(array $cards, Reader $classroom, Reader $cafeteria, Reader $recycling, User $admin, User $teacher, User $kitchen, array $studentRows = [], ?string $scenarioDate = null, string $initialPassword = 'password'): void
    {
        $line = str_repeat('=', 74);

        $this->command->warn($line);
        $this->command->warn(' DEMO CREDENTIALS — copy/paste into Postman or curl');
        $this->command->warn(' CREDENCIALES DE DEMO — copiar/pegar en Postman o curl');
        $this->command->warn($line);
        $this->command->info(' [EN] Organization: '.self::SCHOOL_NAME.' (slug '.self::SCHOOL_SLUG.', branding '.self::SCHOOL_BRAND.') — every row below belongs to it.');
        $this->command->info(' [ES] Organización: '.self::SCHOOL_NAME.' (slug '.self::SCHOOL_SLUG.', identidad '.self::SCHOOL_BRAND.') — todo lo de abajo le pertenece.');

        $this->command->info(' [EN] Dashboard users / Usuarios del panel:');
        $this->command->table(
            ['User / Usuario', 'Email', 'Password', 'Role / Rol'],
            array_merge(
                [
                    [$admin->name, $admin->email, $initialPassword, $admin->role],
                    [$teacher->name, $teacher->email, $initialPassword, $teacher->role],
                    // TASK-037 — the kitchen account (meal-service staff).
                    [$kitchen->name, $kitchen->email, $initialPassword, $kitchen->role],
                    // TASK-025 item 5 — demo student accounts (own data only).
                ],
                // TASK-039 — print the ACTUAL account emails (the audit
                // truth, OBS-014): never a recomputed slug that could
                // disagree with the allocator on collisions.
                array_map(
                    fn (Student $student) => [
                        $student->name,
                        $student->account?->email ?? '?',
                        $initialPassword,
                        UserRole::Student->value,
                    ],
                    $studentRows,
                ),
            ),
        );

        $this->command->info(' [EN] Cards — use credential_uid as {"credential_uid": "..."} in POST /api/v1/events/tap');
        $this->command->info(' [ES] Tarjetas — usa credential_uid como {"credential_uid": "..."} en POST /api/v1/events/tap');
        $this->command->table(
            ['Student / Estudiante', 'Breakfast / Desayuno', 'Lunch / Almuerzo', 'credential_uid'],
            array_map(fn (Card $card) => [
                $card->student->name,
                $card->student->pae_breakfast_enrolled ? '✓' : '—',
                $card->student->pae_lunch_enrolled ? '✓' : '—',
                $card->credential_uid,
            ], $cards),
        );

        $this->command->info(' [EN] Readers — send as header: Authorization: Bearer <api_key>');
        $this->command->info(' [ES] Lectores — envía como cabecera: Authorization: Bearer <api_key>');
        $this->command->info(' [EN] The Cafeteria reader AUTO-DETECTS the meal from the serving windows — no mode to set.');
        $this->command->info(' [ES] El lector de Cafetería DETECTA la comida automáticamente según las ventanas — sin modo que cambiar.');
        $this->command->table(
            ['Reader / Lector', 'Type / Tipo', 'active_event_type', 'api_key (Bearer)'],
            [
                [$classroom->label, $classroom->type->value, $classroom->active_event_type, $classroom->api_key],
                [$cafeteria->label, $cafeteria->type->value, $cafeteria->active_event_type.' (auto)', $cafeteria->api_key],
                [$recycling->label, $recycling->type->value, $recycling->active_event_type, $recycling->api_key],
            ],
        );

        $this->command->warn($line);
        if ($scenarioDate !== null) {
            $this->command->warn(' [EN] PAE demo scenarios (accepted / duplicate / not-enrolled / no-attendance /');
            $this->command->warn("     out-of-window / missed meal) are seeded on {$scenarioDate} — open");
            $this->command->warn('     /admin/reports/pae with that date, or the parent timeline of any student.');
            $this->command->warn(' [ES] Los escenarios PAE de demo (aceptado / duplicado / no inscrito / sin');
            $this->command->warn("     asistencia / fuera de ventana / comida perdida) están sembrados el {$scenarioDate} —");
            $this->command->warn('     abre /admin/reports/pae con esa fecha, o la línea de tiempo de cualquier estudiante.');
            $this->command->warn($line);
        }

        $this->command->warn(' [EN] The recycling reader returns next_step="awaiting_classification" —');
        $this->command->warn('     then POST /api/v1/recycling/classify with event_id + an image file.');
        $this->command->warn(' [ES] El lector de reciclaje devuelve next_step="awaiting_classification" —');
        $this->command->warn('     luego POST /api/v1/recycling/classify con event_id y un archivo de imagen.');
        $this->command->warn($line);
    }
}
