<?php

namespace App\Services\NlQuery;

use App\Models\Student;
use App\Services\AttendanceService;

/**
 * The fixed set of functions the LLM may call. Each declaration maps 1:1 to
 * a REAL Eloquent-backed implementation below — the LLM only ever selects
 * a function and arguments; the backend always computes the answer itself
 * and the LLM merely phrases the result. The LLM can never fabricate data.
 *
 * Every function is independently callable (and independently tested)
 * without any LLM involvement.
 *
 * Wire format: LOWERCASE OpenAPI-style types ("object"/"string"/
 * "integer"). Gemini 3.x models reject the legacy uppercase proto enum
 * ("OBJECT"/"STRING"/...) — FunctionRegistryTest locks this contract.
 */
class FunctionRegistry
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * Gemini-format function declarations (tools.schema).
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
                'description' => 'Count of distinct students who attended the school feeding program (PAE) for a given meal on a given date.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'meal' => ['type' => 'string', 'description' => "The meal: 'breakfast' or 'lunch'."],
                        'date' => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                    ],
                    'required' => ['meal', 'date'],
                ],
            ],
            [
                'name' => 'get_recycling_totals',
                'description' => 'Recycling totals (items deposited and points awarded) for an inclusive date range.',
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
                'description' => 'Chronological list of all presence events (attendance, feeding program, recycling deposits) for one student.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'student_id' => ['type' => 'integer', 'description' => 'The student ID.'],
                    ],
                    'required' => ['student_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a function call locally against the real database.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(string $name, array $args): array
    {
        return match ($name) {
            'get_attendance_count' => [
                'date' => $args['date'],
                'class_id' => $args['class_id'] ?? null,
                'attendance_count' => $this->attendance->attendanceCount(
                    (string) $args['date'],
                    isset($args['class_id']) ? (int) $args['class_id'] : null
                ),
            ],
            'get_pae_count' => [
                'meal' => $args['meal'],
                'date' => $args['date'],
                'pae_count' => $this->attendance->paeCount((string) $args['meal'], (string) $args['date']),
            ],
            'get_recycling_totals' => $this->attendance->recyclingTotals(
                (string) $args['date_from'],
                (string) $args['date_to']
            ),
            'get_student_timeline' => $this->studentTimelineResult((int) $args['student_id']),
            default => ['error' => "Unknown function [{$name}]"],
        };
    }

    private function studentTimelineResult(int $studentId): array
    {
        $student = Student::find($studentId);

        if ($student === null) {
            return ['error' => "Student [{$studentId}] not found"];
        }

        return [
            'student_id' => $student->id,
            'student_name' => $student->name,
            'event_count' => count($this->attendance->studentTimeline($student)),
            'timeline' => $this->attendance->studentTimeline($student),
        ];
    }
}
