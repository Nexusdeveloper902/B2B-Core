<?php

namespace App\Services;

use App\Models\PresenceEvent;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * TASK-037 — the PAE reporting data layer (ADR-056).
 *
 * ONE truth, two consumers: the /admin/reports/pae pages (web) and the
 * natural-language function registry (FunctionRegistry) both resolve
 * through this service, so an LLM answer can never disagree with a
 * report page. Everything is derived from the events spine with the
 * same served-truth constraint AttendanceService uses: only served meal
 * rows count toward meals; flagged rows (served=false + reason) surface
 * exclusively in the flagged-attempt surfaces.
 *
 * Missed meal (the report's core signal): a student who
 *   - has a CLASS_ATTENDANCE event on the school day, AND
 *   - is enrolled for the specific meal, AND
 *   - has NO served meal event of that meal that day.
 * Only SCHOOL DAYS are considered (Mon–Fri with any attendance recorded
 * — weekends and holidays drop out by construction, same rule as the
 * repeat-absence signal).
 */
class PaeReportService
{
    private const MAX_TREND_DAYS = 90;

    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Meals served on one date, per meal + totals, optionally by class.
     *
     * @param  array<int, int>|null  $classIds
     * @return array{date: string, breakfast: int, lunch: int, total: int, by_class: array<int, array{class_id: int, class_name: string, breakfast: int, lunch: int}>}
     */
    public function dailyMeals(string $date, ?array $classIds = null): array
    {
        $byClass = $this->mealsByClass($date, $date, $classIds);

        $breakfast = 0;
        $lunch = 0;
        foreach ($byClass as $row) {
            $breakfast += $row['breakfast'];
            $lunch += $row['lunch'];
        }

        return [
            'date' => $date,
            'breakfast' => $breakfast,
            'lunch' => $lunch,
            'total' => $breakfast + $lunch,
            'by_class' => $byClass,
        ];
    }

    /**
     * Per-day served-meal counts over an inclusive date range (zero-filled).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{date: string, breakfast: int, lunch: int}>
     */
    public function mealsTrend(string $from, string $to, ?array $classIds = null): array
    {
        $rows = $this->servedMealRows($from, $to, $classIds)
            ->selectRaw('date(events.occurred_at) as day, events.type, count(distinct students.id) as students')
            // Portable GROUP BY: group the raw expression, not the `day`
            // alias — MariaDB strict (ONLY_FULL_GROUP_BY) rejects alias
            // grouping the same way it rejected class_id/class_name
            // (mealsByClass already groups real columns for this reason).
            ->groupByRaw('date(events.occurred_at), events.type')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->day][$row->type] = (int) $row->students;
        }

        $trend = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cursor <= $end) {
            $day = $cursor->toDateString();
            $trend[] = [
                'date' => $day,
                'breakfast' => $byDay[$day]['PAE_BREAKFAST'] ?? 0,
                'lunch' => $byDay[$day]['PAE_LUNCH'] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $trend;
    }

    /**
     * Monthly summary: per-day trend + totals.
     *
     * @return array{month: string, days: array<int, array{date: string, breakfast: int, lunch: int}>, totals: array{breakfast: int, lunch: int, total: int}}
     */
    public function monthlyMeals(string $month): array
    {
        // Accept "YYYY-MM" (or a full date — only the month is used).
        $stamp = Carbon::parse($month.'-01');
        $from = $stamp->copy()->startOfMonth()->toDateString();
        $to = $stamp->copy()->endOfMonth()->toDateString();

        $days = $this->mealsTrend($from, $to);

        $breakfast = array_sum(array_column($days, 'breakfast'));
        $lunch = array_sum(array_column($days, 'lunch'));

        return [
            'month' => $stamp->format('Y-m'),
            'days' => $days,
            'totals' => [
                'breakfast' => $breakfast,
                'lunch' => $lunch,
                'total' => $breakfast + $lunch,
            ],
        ];
    }

    /**
     * Students who MISSED a meal on a school day: present (CLASS_ATTENDANCE),
     * enrolled, but no served meal event. Rows carry class + the day's
     * attendance time for context.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{id: int, name: string, class_name: ?string, attended_at: ?string, meal: string}>
     */
    public function missedMeals(string $meal, string $date, ?array $classIds = null): array
    {
        if (! $this->isSchoolDay($date, $classIds)) {
            return [];
        }

        $type = $meal === 'breakfast' ? 'PAE_BREAKFAST' : 'PAE_LUNCH';
        $enrolledColumn = $meal === 'breakfast' ? 'students.pae_breakfast_enrolled' : 'students.pae_lunch_enrolled';

        $present = PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->where('events.served', true)
            ->whereDate('events.occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->selectRaw('students.id as student_id, min(time(events.occurred_at)) as attended_at')
            ->groupBy('students.id')
            ->pluck('attended_at', 'student_id');

        if ($present->isEmpty()) {
            return [];
        }

        $served = PresenceEvent::query()
            ->where('events.type', $type)
            ->where('events.served', true)
            ->whereDate('events.occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->distinct()
            ->pluck('students.id');

        return Student::query()
            ->whereIn('students.id', $present->keys()->all())
            ->whereNotIn('students.id', $served->isEmpty() ? [0] : $served->all())
            ->where($enrolledColumn, true)
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->orderBy('students.name')
            ->get(['students.id', 'students.name', 'classes.name as class_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
                'attended_at' => $present[(int) $row->id] !== null ? substr((string) $present[(int) $row->id], 0, 5) : null,
                'meal' => $meal,
            ])
            ->all();
    }

    /**
     * Missed-meal trend: per-school-day count of missed meals for one
     * meal over the last $days days (today inclusive).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{date: string, missed: int}>
     */
    public function missedMealTrend(string $meal, int $days, ?array $classIds = null): array
    {
        $days = max(1, min(self::MAX_TREND_DAYS, $days));
        $from = Carbon::today()->subDays($days - 1)->toDateString();

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $trend[] = [
                'date' => $date,
                'missed' => count($this->missedMeals($meal, $date, $classIds)),
            ];
        }

        return $trend;
    }

    /**
     * Flagged (excluded) meal attempts over an inclusive range: every
     * served=false row with a meal-related type, with the breakdown by
     * machine reason.
     *
     * @return array{rows: array<int, array{id: int, student_id: ?int, student_name: ?string, class_name: ?string, type: string, meal: ?string, reason: string, occurred_at: string, reader: ?string}>, by_reason: array<string, int>, total: int}
     */
    public function flaggedAttempts(string $from, string $to): array
    {
        $rows = PresenceEvent::query()
            ->whereIn('events.type', ['PAE_BREAKFAST', 'PAE_LUNCH', 'PAE_ATTEMPT'])
            ->where('events.served', false)
            ->whereBetween('events.occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->leftJoin('students', 'students.id', '=', 'cards.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->leftJoin('readers', 'readers.id', '=', 'events.reader_id')
            ->orderByDesc('events.occurred_at')
            ->limit(500)
            ->get([
                'events.id', 'events.type', 'events.reason', 'events.occurred_at',
                'students.id as student_id', 'students.name as student_name',
                'classes.name as class_name', 'readers.label as reader_label',
            ]);

        $byReason = [];

        $mapped = $rows->map(function ($row) use (&$byReason) {
            $reason = (string) $row->reason;
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;

            return [
                'id' => (int) $row->id,
                'student_id' => $row->student_id !== null ? (int) $row->student_id : null,
                'student_name' => $row->student_name,
                'class_name' => $row->class_name,
                'type' => (string) $row->type,
                'meal' => match ((string) $row->type) {
                    'PAE_BREAKFAST' => 'breakfast',
                    'PAE_LUNCH' => 'lunch',
                    default => null,
                },
                'reason' => $reason,
                'occurred_at' => (string) $row->occurred_at,
                'reader' => $row->reader_label,
            ];
        })->all();

        arsort($byReason);

        return [
            'rows' => $mapped,
            'by_reason' => $byReason,
            'total' => count($mapped),
        ];
    }

    /**
     * PAE enrollment summary: per-meal enrollment counts + the four
     * combination buckets (both / breakfast only / lunch only / neither).
     *
     * @param  array<int, int>|null  $classIds
     * @return array{total_students: int, breakfast: int, lunch: int, both: int, breakfast_only: int, lunch_only: int, neither: int}
     */
    public function enrollmentSummary(?array $classIds = null): array
    {
        $students = Student::query()
            ->when($classIds !== null, fn ($q) => $q->whereIn('class_id', $classIds))
            ->get(['pae_breakfast_enrolled', 'pae_lunch_enrolled']);

        $both = $students->filter(fn ($s) => $s->pae_breakfast_enrolled && $s->pae_lunch_enrolled)->count();
        $breakfastOnly = $students->filter(fn ($s) => $s->pae_breakfast_enrolled && ! $s->pae_lunch_enrolled)->count();
        $lunchOnly = $students->filter(fn ($s) => ! $s->pae_breakfast_enrolled && $s->pae_lunch_enrolled)->count();

        return [
            'total_students' => $students->count(),
            'breakfast' => $both + $breakfastOnly,
            'lunch' => $both + $lunchOnly,
            'both' => $both,
            'breakfast_only' => $breakfastOnly,
            'lunch_only' => $lunchOnly,
            'neither' => $students->count() - $both - $breakfastOnly - $lunchOnly,
        ];
    }

    /**
     * One student's PAE history: per school-day meal rows (served +
     * flagged attempts), newest first, plus aggregates. The per-student
     * report page and the NL function share this exact shape.
     *
     * @return array{student: array{id: int, name: string, class_name: ?string, breakfast_enrolled: bool, lunch_enrolled: bool}, days: array<int, array{date: string, breakfast: ?array{time: string, status: string, reason: ?string}, lunch: ?array{time: string, status: string, reason: ?string}}>, totals: array{breakfasts: int, lunches: int, flagged: int}}
     */
    public function studentHistory(Student $student, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = Carbon::today()->subDays($days - 1)->startOfDay();

        $events = PresenceEvent::query()
            ->whereIn('events.type', ['PAE_BREAKFAST', 'PAE_LUNCH', 'PAE_ATTEMPT'])
            ->whereIn('card_id', $student->cards()->pluck('id'))
            ->where('occurred_at', '>=', $from)
            ->orderBy('occurred_at')
            ->get(['type', 'served', 'reason', 'occurred_at']);

        $byDay = [];
        $flaggedTotal = 0;
        foreach ($events as $event) {
            // Every excluded attempt counts toward the flagged total
            // (duplicates that follow a served meal included — the slot
            // display keeps the SERVED meal, but the attempt is still
            // auditable in the count).
            if (! $event->served) {
                $flaggedTotal++;
            }

            $day = $event->occurred_at->toDateString();
            $slot = match ($event->type) {
                'PAE_BREAKFAST' => 'breakfast',
                'PAE_LUNCH' => 'lunch',
                default => null, // PAE_ATTEMPT: no meal window was active
            };

            if ($slot !== null) {
                // The SERVED meal is the day's display truth; attempts
                // around it stay contextual (a later row for the same
                // slot only replaces an earlier FLAGGED one).
                $existing = $byDay[$day][$slot] ?? null;
                if ($existing === null || ! $existing['served']) {
                    $byDay[$day][$slot] = [
                        'time' => $event->occurred_at->format('H:i'),
                        'status' => $event->served ? 'served' : 'flagged',
                        'reason' => $event->reason,
                        'served' => $event->served,
                    ];
                }
            } else {
                $byDay[$day]['attempts'][] = [
                    'time' => $event->occurred_at->format('H:i'),
                    'reason' => $event->reason,
                ];
            }
        }

        krsort($byDay);

        $daysRows = [];
        $breakfasts = 0;
        $lunches = 0;
        foreach ($byDay as $day => $slots) {
            $breakfast = $slots['breakfast'] ?? null;
            $lunch = $slots['lunch'] ?? null;
            if ($breakfast !== null && $breakfast['served']) {
                $breakfasts++;
            }
            if ($lunch !== null && $lunch['served']) {
                $lunches++;
            }

            $daysRows[] = [
                'date' => (string) $day,
                'breakfast' => $breakfast !== null ? [
                    'time' => $breakfast['time'],
                    'status' => $breakfast['status'],
                    'reason' => $breakfast['reason'],
                ] : null,
                'lunch' => $lunch !== null ? [
                    'time' => $lunch['time'],
                    'status' => $lunch['status'],
                    'reason' => $lunch['reason'],
                ] : null,
            ];
        }

        return [
            'student' => [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'class_name' => $student->schoolClass?->name,
                'breakfast_enrolled' => (bool) $student->pae_breakfast_enrolled,
                'lunch_enrolled' => (bool) $student->pae_lunch_enrolled,
            ],
            'days' => $daysRows,
            'totals' => [
                'breakfasts' => $breakfasts,
                'lunches' => $lunches,
                'flagged' => $flaggedTotal,
            ],
        ];
    }

    /**
     * Breakfast + lunch served counts for a single student on a date
     * (present-tense NL questions: "did Maria eat today?").
     *
     * @return array{breakfast: bool, lunch: bool}
     */
    public function studentMealsToday(Student $student, string $date): array
    {
        $served = PresenceEvent::query()
            ->whereIn('events.type', ['PAE_BREAKFAST', 'PAE_LUNCH'])
            ->where('events.served', true)
            ->whereIn('card_id', $student->cards()->pluck('id'))
            ->whereDate('occurred_at', $date)
            ->pluck('type');

        return [
            'breakfast' => $served->contains('PAE_BREAKFAST'),
            'lunch' => $served->contains('PAE_LUNCH'),
        ];
    }

    /**
     * Is this date a school day? Mon–Fri AND at least one in-scope
     * student attended that day (weekends/holidays drop out by
     * construction — the same rule as the repeat-absence signal).
     *
     * @param  array<int, int>|null  $classIds
     */
    public function isSchoolDay(string $date, ?array $classIds = null): bool
    {
        if (! Carbon::parse($date)->isWeekday()) {
            return false;
        }

        $date = Carbon::parse($date)->toDateString();

        return PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->where('events.served', true)
            ->whereDate('events.occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->exists();
    }

    /**
     * @param  array<int, int>|null  $classIds
     */
    private function servedMealRows(string $from, string $to, ?array $classIds): Builder
    {
        return PresenceEvent::query()
            ->whereIn('events.type', ['PAE_BREAKFAST', 'PAE_LUNCH'])
            ->where('events.served', true)
            ->whereBetween('events.occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds));
    }

    /**
     * Per-class served meal counts for a date range (classes with zero
     * meals still appear — honest comparison, not survivorship).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{class_id: int, class_name: string, breakfast: int, lunch: int}>
     */
    private function mealsByClass(string $from, string $to, ?array $classIds): array
    {
        $counts = $this->servedMealRows($from, $to, $classIds)
            ->join('classes', 'classes.id', '=', 'students.class_id')
            // GROUP BY the REAL columns (not the select aliases) —
            // MariaDB's ONLY_FULL_GROUP_BY resolves aliases back to
            // their source columns and then complains the source isn't
            // grouped; SQLite accepts both, so this is the portable
            // form (the RUN-038 engine-truth rule).
            ->selectRaw('classes.id as class_id, classes.name as class_name, events.type, count(distinct students.id) as students')
            ->groupBy('classes.id', 'classes.name', 'events.type')
            ->get();

        $byClass = [];
        foreach ($counts as $row) {
            $byClass[(int) $row->class_id] ??= [
                'class_id' => (int) $row->class_id,
                'class_name' => (string) $row->class_name,
                'breakfast' => 0,
                'lunch' => 0,
            ];
            $byClass[(int) $row->class_id][$row->type === 'PAE_BREAKFAST' ? 'breakfast' : 'lunch'] = (int) $row->students;
        }

        return array_values($byClass);
    }
}
