<?php

namespace App\Services\NlQuery;

use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\StudentScope;

/**
 * The fixed set of functions the LLM may call. Each declaration maps 1:1 to
 * a REAL Eloquent-backed implementation below — the LLM only ever selects
 * a function and arguments; the backend always computes the answer itself
 * and the LLM merely phrases the result. The LLM can never fabricate data.
 *
 * Every function is independently callable (and independently tested)
 * without any LLM involvement.
 *
 * TASK-027 — the analytical half (absences, repeat absentees, trends,
 * late counts, name lookup, time-in-school) and the teacher data wall:
 * every execution is fenced by the caller's StudentScope (admin =
 * school-wide; teacher = own classes — a requested class or student
 * outside the wall answers with an explicit scope error, never with
 * data). The school-wide recycling totals stay school-wide on purpose
 * (leaderboard-level exposure, spec §22).
 *
 * Wire format: provider-neutral declarations (name/description/parameters)
 * with LOWERCASE JSON-Schema types ("object"/"string"/"integer") — exactly
 * what the DeepSeek tools format expects; the client wraps them into
 * {type: "function", function: {…}} envelopes (ADR-030).
 * FunctionRegistryTest locks this contract.
 */
class FunctionRegistry
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Provider-neutral function declarations (name/description/parameters).
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarations(): array
    {
        return [
            [
                'name' => 'get_attendance_count',
                'description' => 'Count of distinct students who tapped in for class attendance on a given date. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the count.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'get_pae_count',
                'description' => 'Count of distinct students who attended the school feeding program (PAE) for a given meal on a given date. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'meal' => ['type' => 'string', 'description' => "The meal: 'breakfast' or 'lunch'."],
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the count.'],
                    ],
                    'required' => ['meal', 'date'],
                ],
            ],
            [
                'name' => 'get_recycling_totals',
                'description' => 'Recycling totals (items deposited and points awarded) for an inclusive date range. School-wide by design (public competition board).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date_from' => ['type' => 'string', 'description' => 'Start date in YYYY-MM-DD format (inclusive).'],
                        'date_to' => ['type' => 'string', 'description' => 'End date in YYYY-MM-DD format (inclusive).'],
                    ],
                    'required' => ['date_from', 'date_to'],
                ],
            ],
            [
                'name' => 'get_student_timeline',
                'description' => 'Chronological list of all presence events (attendance, feeding program, recycling deposits, entry/exit) for one student.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'student_id' => ['type' => 'integer', 'description' => 'The student ID (resolve it with find_student first).'],
                    ],
                    'required' => ['student_id'],
                ],
            ],
            [
                'name' => 'get_absence_count',
                'description' => 'How many students were absent (no class attendance tap) on a given date. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the count.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'get_absent_students',
                'description' => 'List of students who were absent (no class attendance tap) on a given date, with their class. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the list.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'get_late_count',
                'description' => 'How many students first tapped in AFTER the late cutoff on a given date. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the count.'],
                    ],
                    'required' => ['date'],
                ],
            ],
            [
                'name' => 'get_attendance_trend',
                'description' => 'Daily distinct-student attendance counts for the last N days — the recent attendance trend (zero days included, weekends included).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back (1-90, default 7).'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_repeatedly_absent_students',
                'description' => 'Students who missed class attendance on at least a minimum number of the recent school days — the repeated-absence signal. Optionally scoped to one class.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back to consider (1-90, default 10).'],
                        'min_absences' => ['type' => 'integer', 'description' => 'Minimum absences to report (default 3).'],
                        'class_id' => ['type' => 'integer', 'description' => 'Optional class ID to scope the list.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_student_time_in_school',
                'description' => 'Per-day entry/exit sessions and total time spent in school for one student (derived from ENTRY/EXIT taps; an unmatched entry counts as an open session).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'student_id' => ['type' => 'integer', 'description' => 'The student ID (resolve it with find_student first).'],
                        'days' => ['type' => 'integer', 'description' => 'How many days back (1-365, default 30).'],
                    ],
                    'required' => ['student_id'],
                ],
            ],
            [
                'name' => 'find_student',
                'description' => 'Look up students by (partial) name to resolve a student_id before calling timeline or time-in-school functions. Returns up to five matches.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Full or partial student name.'],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    /**
     * Execute a function call locally against the real database.
     *
     * @param  array<string, mixed>  $args
     * @param  StudentScope|null  $scope  TASK-027 — caller's data wall (null = admin/school-wide)
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args, ?StudentScope $scope = null): array
    {
        return match ($name) {
            'get_attendance_count' => $this->attendanceCountResult($args, $scope),
            'get_pae_count' => $this->paeCountResult($args, $scope),
            'get_recycling_totals' => $this->attendance->recyclingTotals(
                (string) $args['date_from'],
                (string) $args['date_to']
            ),
            'get_student_timeline' => $this->studentTimelineResult((int) $args['student_id'], $scope),
            'get_absence_count' => $this->absenceCountResult($args, $scope),
            'get_absent_students' => $this->absentStudentsResult($args, $scope),
            'get_late_count' => $this->lateCountResult($args, $scope),
            'get_attendance_trend' => [
                'days' => $this->boundedDays((int) ($args['days'] ?? 7), 90),
                'trend' => $this->attendance->attendanceTrend(
                    $this->boundedDays((int) ($args['days'] ?? 7), 90),
                    $this->scopeClassIds($scope),
                ),
            ],
            'get_repeatedly_absent_students' => $this->repeatedlyAbsentResult($args, $scope),
            'get_student_time_in_school' => $this->timeInSchoolResult($args, $scope),
            'find_student' => [
                'name' => (string) $args['name'],
                'matches' => $this->attendance->findStudentsByName(
                    (string) $args['name'],
                    $this->scopeClassIds($scope),
                ),
            ],
            default => ['error' => "Unknown function [{$name}]"],
        };
    }

    // -- TASK-027 helpers: scope fences + bounded arguments ----------------

    /** Teacher's class-id filter (null = school-wide/admin). */
    private function scopeClassIds(?StudentScope $scope): ?array
    {
        return $scope?->classIdFilter();
    }

    /**
     * Resolve the class a scoped call may actually use: a requested class
     * outside the caller's wall is a hard error (never silent data).
     *
     * @param  array<string, mixed>  $args
     * @return array{0: array<int, int>|null, 1: ?int}
     */
    private function resolveRequestedClass(array $args, ?StudentScope $scope): array
    {
        $requested = isset($args['class_id']) ? (int) $args['class_id'] : null;

        if ($requested === null) {
            return [$this->scopeClassIds($scope), null];
        }

        $scoped = $this->scopeClassIds($scope);

        if ($scoped !== null && ! in_array($requested, $scoped, true)) {
            return [$scoped, $requested];
        }

        return [[$requested], null];
    }

    private function boundedDays(int $days, int $max): int
    {
        return max(1, min($max, $days));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function attendanceCountResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        if (isset($args['class_id'])) {
            return $this->attendanceResultForClass((string) $args['date'], (int) $args['class_id']);
        }

        if ($classIds === null) {
            return $this->attendanceResultForClass((string) $args['date'], null);
        }

        // Scoped count without an explicit class: in-scope students minus
        // the absent ones (distinct per student, same spine as the roster).
        return [
            'date' => (string) $args['date'],
            'attendance_count' => $this->attendance->studentCount($classIds)
                - count($this->attendance->absentStudents((string) $args['date'], $classIds)),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function paeCountResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        return [
            'meal' => $args['meal'],
            'date' => $args['date'],
            'pae_count' => $this->attendance->paeCount((string) $args['meal'], (string) $args['date'], $classIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function absenceCountResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        return [
            'date' => (string) $args['date'],
            'absence_count' => count($this->attendance->absentStudents((string) $args['date'], $classIds)),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function absentStudentsResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        return [
            'date' => (string) $args['date'],
            'absent_students' => $this->attendance->absentStudents((string) $args['date'], $classIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function lateCountResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        return [
            'date' => (string) $args['date'],
            'late_count' => $this->attendance->lateCount((string) $args['date'], $classIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function repeatedlyAbsentResult(array $args, ?StudentScope $scope): array
    {
        [$classIds, $forbidden] = $this->resolveRequestedClass($args, $scope);

        if ($forbidden !== null) {
            return ['error' => "Class [{$forbidden}] is outside the caller's scope"];
        }

        $days = $this->boundedDays((int) ($args['days'] ?? 10), 90);
        $minAbsences = max(1, (int) ($args['min_absences'] ?? 3));

        return [
            'days' => $days,
            'min_absences' => $minAbsences,
            'students' => $this->attendance->repeatedlyAbsentStudents($days, $minAbsences, $classIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function timeInSchoolResult(array $args, ?StudentScope $scope): array
    {
        $student = Student::find((int) $args['student_id']);

        if ($student === null) {
            return ['error' => 'Student ['.(int) $args['student_id'].'] not found'];
        }

        if ($scope !== null && ! $scope->allowsStudent($student)) {
            return ['error' => 'Student is outside the caller\'s scope'];
        }

        return [
            'student_id' => $student->id,
            'student_name' => $student->name,
            'days' => $this->boundedDays((int) ($args['days'] ?? 30), 365),
            'sessions_by_day' => $this->attendance->studentSessions(
                $student,
                $this->boundedDays((int) ($args['days'] ?? 30), 365),
            ),
        ];
    }

    private function attendanceResultForClass(string $date, ?int $classId): array
    {
        return [
            'date' => $date,
            'class_id' => $classId,
            'attendance_count' => $this->attendance->attendanceCount($date, $classId),
        ];
    }

    private function studentTimelineResult(int $studentId, ?StudentScope $scope): array
    {
        $student = Student::find($studentId);

        if ($student === null) {
            return ['error' => "Student [{$studentId}] not found"];
        }

        if ($scope !== null && ! $scope->allowsStudent($student)) {
            return ['error' => 'Student is outside the caller\'s scope'];
        }

        return [
            'student_id' => $student->id,
            'student_name' => $student->name,
            'event_count' => count($this->attendance->studentTimeline($student)),
            'timeline' => $this->attendance->studentTimeline($student),
        ];
    }
}
