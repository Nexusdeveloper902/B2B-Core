<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentStoreRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TASK-027 — student management through the GUI: create students and
 * bulk-import a CSV, no more hand-written SQL.
 *
 * POST /api/v1/admin/students        { name, grade, class_id, pae_enrolled }
 * POST /api/v1/admin/students/import  multipart: file (CSV) — admin-only.
 *
 * CSV contract (header row REQUIRED, columns case-insensitive, order
 * free, extra columns ignored):
 *
 *   name,grade,class,pae_enrolled
 *   María Pérez,5° B,5° B,yes
 *
 * `class` resolves by class NAME (the human workflow); `pae_enrolled`
 * accepts yes/no/true/false/1/0/sí/no. Row-level failures are reported
 * per row (row number + bilingual message) — a bad row never blocks
 * the good ones; duplicates (same name in the same class) are row
 * errors, never silent skips.
 */
class StudentController extends Controller
{
    private const IMPORT_MAX_ROWS = 500;

    public function store(StudentStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $duplicate = Student::query()
            ->where('name', $validated['name'])
            ->where('class_id', $validated['class_id'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status' => 'error',
                'reason' => 'duplicate',
                'message' => __('api.student_duplicate', [
                    'name' => $validated['name'],
                    'class' => SchoolClass::find($validated['class_id'])?->name ?? '',
                ]),
            ], 422);
        }

        $student = Student::create([
            'name' => $validated['name'],
            'grade' => $validated['grade'],
            'class_id' => (int) $validated['class_id'],
            'pae_enrolled' => (bool) ($validated['pae_enrolled'] ?? false),
        ]);

        return response()->json([
            'status' => 'ok',
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'grade' => $student->grade,
                'class_name' => $student->schoolClass?->name,
                'pae_enrolled' => $student->pae_enrolled,
            ],
            'message' => __('api.student_created', ['name' => $student->name]),
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ]);

        $path = $request->file('file')->getRealPath();
        $handle = $path !== false ? @fopen($path, 'r') : false;

        if ($handle === false) {
            return response()->json([
                'status' => 'error',
                'message' => __('api.students_import_unreadable'),
            ], 422);
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '\\');
            $columns = $header === false ? null : $this->columnMap($header);

            if ($columns === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => __('api.students_import_bad_header'),
                ], 422);
            }

            // Class resolution by name (exact, then case-insensitive).
            $classes = SchoolClass::pluck('id', 'name')->all();
            $classesLower = [];
            foreach ($classes as $name => $id) {
                $classesLower[mb_strtolower($name)] = $id;
            }

            $created = 0;
            $errors = [];
            $dataRows = 0;
            $rowNumber = 1; // header is row 1
            $students = [];

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;

                if (count($row) === 1 && trim((string) $row[0]) === '') {
                    continue; // blank line — not a data row
                }

                $dataRows++;

                if ($dataRows > self::IMPORT_MAX_ROWS) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'message' => __('api.students_import_too_many_rows', ['max' => self::IMPORT_MAX_ROWS]),
                    ];
                    break;
                }

                $result = $this->importRow($row, $columns, $classes, $classesLower);

                if ($result['error'] !== null) {
                    $errors[] = ['row' => $rowNumber, 'message' => $result['error']];

                    continue;
                }

                $students[] = Student::create($result['attributes']);
                $created++;
            }
        } finally {
            @fclose($handle);
        }

        if ($dataRows === 0) {
            return response()->json([
                'status' => 'error',
                'created' => 0,
                'failed' => 0,
                'errors' => [],
                'students' => [],
                'message' => __('api.students_import_no_rows'),
            ], 422);
        }

        $status = $created > 0 && $errors === [] ? 'ok'
            : ($created > 0 ? 'partial' : 'error');

        return response()->json([
            'status' => $status,
            'created' => $created,
            'failed' => count($errors),
            'errors' => $errors,
            'students' => array_map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'class_name' => $s->schoolClass?->name,
            ], $students),
            'message' => __('api.students_import_summary', [
                'created' => $created,
                'failed' => count($errors),
            ]),
        ], $status === 'error' ? 422 : 200);
    }

    /**
     * Map a header row to column positions (case-insensitive, order free).
     *
     * @param  array<int, string|false|null>  $header
     * @return array<string, int>|null
     */
    private function columnMap(array $header): ?array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $key = strtolower(trim((string) $name));
            if (in_array($key, ['name', 'grade', 'class', 'pae_enrolled', 'pae'], true)) {
                $map[$key === 'pae' ? 'pae_enrolled' : $key] = (int) $index;
            }
        }

        foreach (['name', 'grade', 'class'] as $required) {
            if (! isset($map[$required])) {
                return null;
            }
        }

        return $map;
    }

    /**
     * Validate + resolve ONE data row.
     *
     * @param  array<int, string|false|null>  $row
     * @param  array<string, int>  $columns
     * @param  array<string, int>  $classes
     * @param  array<string, int>  $classesLower
     * @return array{attributes: array<string, mixed>, error: ?string}
     */
    private function importRow(array $row, array $columns, array $classes, array $classesLower): array
    {
        $name = trim((string) ($row[$columns['name']] ?? ''));
        $grade = trim((string) ($row[$columns['grade']] ?? ''));
        $className = trim((string) ($row[$columns['class']] ?? ''));
        $paeRaw = isset($columns['pae_enrolled'])
            ? strtolower(trim((string) ($row[$columns['pae_enrolled']] ?? '')))
            : '';

        if ($name === '' || $grade === '') {
            return ['attributes' => [], 'error' => __('api.students_import_row_incomplete')];
        }

        $classId = $classes[$className]
            ?? $classesLower[mb_strtolower($className)]
            ?? null;

        if ($classId === null) {
            return ['attributes' => [], 'error' => __('api.students_import_unknown_class', ['class' => $className])];
        }

        $paeEnrolled = in_array($paeRaw, ['yes', 'true', '1', 'si', 'sí', 'y', 'v'], true);

        $duplicate = Student::query()
            ->where('name', $name)
            ->where('class_id', $classId)
            ->exists();

        if ($duplicate) {
            return ['attributes' => [], 'error' => __('api.student_duplicate', [
                'name' => $name,
                'class' => $className,
            ])];
        }

        return [
            'attributes' => [
                'name' => $name,
                'grade' => $grade,
                'class_id' => $classId,
                'pae_enrolled' => $paeEnrolled,
            ],
            'error' => null,
        ];
    }
}
