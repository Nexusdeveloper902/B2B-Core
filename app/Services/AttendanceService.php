<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard derivations (Phase F): attendance / PAE / recycling views are
 * all computed from the same events table — never stored separately.
 *
 * Also backs the NL-query callable functions (Phase E): every number the
 * LLM can report comes through these same methods, so the LLM can never
 * fabricate data that disagrees with the dashboards.
 *
 * TASK-027 adds the analytical half (absences, repeat absentees, trends,
 * late counts, name lookup — all scope-aware for the teacher NL
 * interface) and the entry/exit session pairing (every entry registers;
 * time-in-school is derived on the fly, never stored).
 *
 * Scope convention used by the new methods: `$classIds === null` means
 * school-wide (admin); an empty array means a teacher with no classes
 * (everything legitimately empty).
 */
class AttendanceService
{
    private const NAME_LOOKUP_LIMIT = 5;

    /**
     * Today's CLASS_ATTENDANCE status for one class: present / late / absent.
     *
     * @return array<int, array{
     *   student: Student, status: 'present'|'late'|'absent', tapped_at: ?string
     * }>
     */
    public function classAttendanceToday(int $classId): array
    {
        $cutoff = (string) config('presence.late_cutoff');
        $today = now()->toDateString();

        $students = Student::where('class_id', $classId)
            ->with('cards')
            ->orderBy('name')
            ->get();

        // One grouped query for the whole class: each student's FIRST
        // CLASS_ATTENDANCE tap of today, across all their cards. The
        // per-student loop this replaces issued one events query per
        // student, per class, on every teacher-dashboard render.
        $firstTaps = PresenceEvent::query()
            ->where('type', 'CLASS_ATTENDANCE')
            ->whereDate('occurred_at', $today)
            ->whereIn('card_id', $students->flatMap->cards->pluck('id'))
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->selectRaw('cards.student_id, min(events.occurred_at) as first_tap')
            ->groupBy('cards.student_id')
            ->pluck('first_tap', 'cards.student_id');

        $rows = [];
        foreach ($students as $student) {
            $firstTap = $firstTaps[$student->id] ?? null;

            $status = 'absent';
            $tappedAt = null;

            if ($firstTap !== null) {
                $occurredAt = Carbon::parse($firstTap);
                $tappedAt = $occurredAt->format('H:i');
                $status = $tappedAt > $cutoff ? 'late' : 'present';
            }

            $rows[] = compact('student', 'status', 'tappedAt');
        }

        return $rows;
    }

    /**
     * Distinct students with a CLASS_ATTENDANCE event on the given date
     * (Y-m-d), optionally scoped to one class.
     */
    public function attendanceCount(string $date, ?int $classId = null): int
    {
        return $this->studentCountForEvent('CLASS_ATTENDANCE', $date, null, $classId);
    }

    /** Distinct students with a PAE meal event ('breakfast'|'lunch') on the given date. */
    public function paeCount(string $meal, string $date, ?array $classIds = null): int
    {
        $type = $meal === 'breakfast' ? 'PAE_BREAKFAST' : 'PAE_LUNCH';

        return $this->studentCountForEvent($type, $date, $classIds);
    }

    /**
     * TASK-027 — count of in-scope students (null = whole school; an
     * empty array = a teacher with no classes, honestly zero).
     *
     * @param  array<int, int>|null  $classIds
     */
    public function studentCount(?array $classIds = null): int
    {
        return $this->scopedStudents($classIds)->count();
    }

    private function studentCountForEvent(string $type, string $date, ?array $classIds = null, ?int $classId = null): int
    {
        $query = PresenceEvent::query()
            ->where('events.type', $type)
            ->whereDate('occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id');

        if ($classId !== null) {
            $query->where('students.class_id', $classId);
        } elseif ($classIds !== null) {
            $query->whereIn('students.class_id', $classIds);
        }

        return $query->distinct()->count('students.id');
    }

    /**
     * Recycling totals for an inclusive date range (Y-m-d to Y-m-d).
     *
     * @return array{items: int, points: int, by_material: array<string, int>}
     */
    public function recyclingTotals(string $from, string $to): array
    {
        $rows = RecyclingDeposit::whereHas('event', function ($q) use ($from, $to) {
            $q->whereBetween('occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]);
        })->get();

        $byMaterial = [];
        foreach (MaterialClass::cases() as $material) {
            $byMaterial[$material->value] = $rows->where('material_class', $material->value)->count();
        }

        return [
            'items' => $rows->count(),
            'points' => (int) $rows->sum('points_awarded'),
            'by_material' => $byMaterial,
        ];
    }

    /**
     * Chronological timeline of all events for one student (parent view +
     * NL query function). PresenceEvent rows joined with readable context.
     *
     * @return array<int, array{
     *   event_id: int, type: string, occurred_at: string, reader: string, material: ?string, points: ?int
     * }>
     */
    public function studentTimeline(Student $student): array
    {
        return PresenceEvent::whereIn('card_id', $student->cards()->pluck('id'))
            ->with(['reader', 'deposit'])
            ->orderBy('occurred_at')
            ->get()
            ->map(function (PresenceEvent $event) {
                return [
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'reader' => $event->reader?->label,
                    'material' => $event->deposit?->material_class?->value,
                    'points' => $event->deposit?->points_awarded,
                ];
            })
            ->all();
    }

    // -----------------------------------------------------------------------
    // TASK-027 — the analytical half (teacher NL queries + absence views).
    // -----------------------------------------------------------------------

    /**
     * Students with NO CLASS_ATTENDANCE event on the given date (Y-m-d).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{id: int, name: string, class_name: ?string}>
     */
    public function absentStudents(string $date, ?array $classIds = null): array
    {
        $attended = $this->studentsWithAttendanceOn($date, $classIds);

        return $this->scopedStudents($classIds)
            ->whereNotIn('students.id', $attended->isEmpty() ? [0] : $attended->all())
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->orderBy('students.name')
            ->get(['students.id', 'students.name', 'classes.name as class_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
            ])
            ->all();
    }

    /**
     * Distinct students whose FIRST CLASS_ATTENDANCE tap of the date came
     * after the late cutoff (school-local wall time, TASK-015).
     *
     * @param  array<int, int>|null  $classIds
     */
    public function lateCount(string $date, ?array $classIds = null): int
    {
        $cutoff = (string) config('presence.late_cutoff');

        return collect($this->firstTapByStudent($date, $classIds))
            ->filter(fn (string $time) => $time > $cutoff)
            ->count();
    }

    /**
     * Per-day distinct-student attendance counts for the last $days days
     * (inclusive of today; zero days stay zero — honest trend, weekends
     * and holidays included).
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{date: string, students: int}>
     */
    public function attendanceTrend(int $days, ?array $classIds = null): array
    {
        $days = max(1, min(90, $days));
        $from = Carbon::today()->subDays($days - 1)->toDateString();
        $to = Carbon::today()->toDateString();

        $counts = PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->whereBetween('occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->selectRaw('date(events.occurred_at) as day, count(distinct students.id) as attended')
            ->groupBy('day')
            ->get();

        $countsByDay = Collection::make($counts)->pluck('attended', 'day')->all();

        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i)->toDateString();
            $trend[] = ['date' => $day, 'students' => (int) ($countsByDay[$day] ?? 0)];
        }

        return $trend;
    }

    /**
     * Students absent on at least $minAbsences of the last $days' SCHOOL
     * days — a school day is a date inside the window on which at least
     * one in-scope student attended (weekends/holidays drop out by
     * construction). This is the "repeatedly absent" signal.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{id: int, name: string, class_name: ?string, absences: int, school_days: int}>
     */
    public function repeatedlyAbsentStudents(int $days, int $minAbsences, ?array $classIds = null): array
    {
        $days = max(1, min(90, $days));
        $minAbsences = max(1, $minAbsences);

        $trend = $this->attendanceTrend($days, $classIds);
        $schoolDays = collect($trend)
            ->filter(fn ($row) => $row['students'] > 0)
            ->pluck('date')
            ->all();

        if ($schoolDays === []) {
            return [];
        }

        // Which student attended which school day (only in-scope students).
        $attendedByStudent = PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->whereBetween('occurred_at', [
                Carbon::parse($schoolDays[0])->startOfDay(),
                Carbon::parse($schoolDays[count($schoolDays) - 1])->endOfDay(),
            ])
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->selectRaw('students.id as student_id, date(events.occurred_at) as day')
            ->distinct()
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('day')->all())
            ->all();

        // Keep only attendance rows that actually fall on school days —
        // the between range may include non-school days inside its span.
        $schoolDaySet = array_flip($schoolDays);
        $attendedByStudent = array_map(
            fn ($days) => array_values(array_filter($days, fn ($day) => isset($schoolDaySet[$day]))),
            $attendedByStudent,
        );

        $result = [];
        foreach ($this->scopedStudents($classIds)
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->orderBy('students.name')
            ->get(['students.id', 'students.name', 'classes.name as class_name']) as $student) {
            $attended = $attendedByStudent[(int) $student->id] ?? [];
            $absences = count(array_diff($schoolDays, $attended));

            if ($absences >= $minAbsences) {
                $result[] = [
                    'id' => (int) $student->id,
                    'name' => (string) $student->name,
                    'class_name' => $student->class_name !== null ? (string) $student->class_name : null,
                    'absences' => $absences,
                    'school_days' => count($schoolDays),
                ];
            }
        }

        return $result;
    }

    /**
     * Fuzzy name lookup — the bridge that lets the NL interface resolve
     * "Ana" to a student id before calling the timeline function.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, array{id: int, name: string, class_name: ?string, pae_enrolled: bool}>
     */
    public function findStudentsByName(string $name, ?array $classIds = null): array
    {
        // LIKE wildcards are stripped, not escaped: the lookup is a plain
        // contains-match and must never become a pattern-injection game.
        $needle = str_replace(['%', '_'], '', trim($name));

        if ($needle === '') {
            return [];
        }

        return $this->scopedStudents($classIds)
            ->where('students.name', 'like', '%'.$needle.'%')
            ->join('classes', 'classes.id', '=', 'students.class_id')
            ->orderBy('students.name')
            ->limit(self::NAME_LOOKUP_LIMIT)
            ->get(['students.id', 'students.name', 'classes.name as class_name', 'students.pae_enrolled'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
                'pae_enrolled' => (bool) $row->pae_enrolled,
            ])
            ->all();
    }

    // -----------------------------------------------------------------------
    // TASK-027 — the entry/exit half (multiple entries all register;
    // time-in-school derived on the fly from ENTRY/EXIT pairs).
    // -----------------------------------------------------------------------

    /**
     * Per-day presence sessions for one student over the last $days days.
     * Every ENTRY is its own row (multiple entries per day register —
     * the edge case the spec calls out); each EXIT closes the most recent
     * open ENTRY of the same day; an unmatched ENTRY stays open (the
     * student is still inside / forgot to tap out — honest, not invented).
     *
     * @return array<int, array{
     *   date: string, entries: int, exits: int, minutes_in_school: int,
     *   open_session: bool, sessions: array<int, array{entry: string, exit: ?string, minutes: ?int}>
     * }>
     */
    public function studentSessions(Student $student, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = Carbon::today()->subDays($days - 1)->startOfDay();

        $events = PresenceEvent::whereIn('card_id', $student->cards()->pluck('id'))
            ->whereIn('type', ['ENTRY', 'EXIT'])
            ->where('occurred_at', '>=', $from)
            ->orderBy('occurred_at')
            ->get(['type', 'occurred_at']);

        $byDay = [];
        foreach ($events as $event) {
            $day = $event->occurred_at->toDateString();
            $byDay[$day] ??= ['sessions' => [], 'open' => null, 'exits' => 0, 'entries' => 0];

            if ($event->type === 'ENTRY') {
                $byDay[$day]['entries']++;
                $byDay[$day]['sessions'][] = ['entry' => $event->occurred_at->format('H:i'), 'exit' => null, 'minutes' => null];
                $byDay[$day]['open'] = count($byDay[$day]['sessions']) - 1;
            } else {
                $byDay[$day]['exits']++;
                $open = $byDay[$day]['open'];
                if ($open !== null) {
                    $entry = $byDay[$day]['sessions'][$open];
                    $minutes = (int) round(abs($event->occurred_at->diffInMinutes(
                        Carbon::parse($day.' '.$entry['entry'])
                    )));
                    $byDay[$day]['sessions'][$open]['exit'] = $event->occurred_at->format('H:i');
                    $byDay[$day]['sessions'][$open]['minutes'] = $minutes;
                    $byDay[$day]['open'] = null;
                }
                // An EXIT with no open entry of the same day is kept in the
                // exits count but pairs with nothing (no invented entry).
            }
        }

        krsort($byDay);

        $rows = [];
        foreach ($byDay as $day => $data) {
            $rows[] = [
                'date' => (string) $day,
                'entries' => $data['entries'],
                'exits' => $data['exits'],
                'minutes_in_school' => (int) collect($data['sessions'])->sum('minutes'),
                'open_session' => $data['open'] !== null,
                'sessions' => $data['sessions'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int>|null  $classIds
     * @return Collection<int, int>
     */
    private function studentsWithAttendanceOn(string $date, ?array $classIds): Collection
    {
        return PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->whereDate('occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->distinct()
            ->pluck('students.id');
    }

    /**
     * First CLASS_ATTENDANCE tap time (H:i) per student for a date.
     *
     * @param  array<int, int>|null  $classIds
     * @return array<int, string> student_id => H:i
     */
    private function firstTapByStudent(string $date, ?array $classIds): array
    {
        $rows = PresenceEvent::query()
            ->where('events.type', 'CLASS_ATTENDANCE')
            ->whereDate('occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds))
            ->selectRaw('students.id as student_id, min(time(events.occurred_at)) as first_tap')
            ->groupBy('students.id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->student_id] = (string) $row->first_tap;
        }

        return $map;
    }

    /**
     * In-scope students (query builder, joined to classes for names).
     *
     * @param  array<int, int>|null  $classIds
     */
    private function scopedStudents(?array $classIds): Builder
    {
        return Student::query()
            ->when($classIds !== null, fn ($q) => $q->whereIn('students.class_id', $classIds));
    }
}
