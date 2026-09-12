<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentStoreRequest;
use App\Models\RosterUpdate;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Realtime\RosterUpdateLog;
use App\Services\StudentAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TASK-027 — student management through the GUI: create students and
 * bulk-import a CSV, no more hand-written SQL.
 *
 * POST /api/v1/admin/students        { name, grade, class_id, pae_enrolled }
 * POST /api/v1/admin/students/import  multipart: file (CSV) — admin-only.
+*
+* TASK-030-A (ADR-044) — every created student leaves with a login: the
+* 1:1 account is provisioned in the SAME transaction (convention email
+* + shared initial password + forced first-login rotation). The
+* creation/import responses carry the credentials EXACTLY ONCE
+* (display-once, the reader-API-key rule); roster frames carry only the
+* email. POST /api/v1/admin/students/{student}/account backfills
+* pre-feature rows (idempotent).
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

    public function store(StudentStoreRequest $request, StudentAccountService $accounts): JsonResponse
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

        try {
            ['student' => $student, 'account' => $account] = DB::transaction(function () use ($validated, $accounts) {
                $student = Student::create([
                    'name' => $validated['name'],
                    'grade' => $validated['grade'],
                    'class_id' => (int) $validated['class_id'],
                    'pae_enrolled' => (bool) ($validated['pae_enrolled'] ?? false),
                ]);

                // TASK-030-A — the login ships with the enrollment, same
                // transaction: a student row without an account (or vice
                // versa) can never commit.
                $account = $accounts->provisionFor($student)['user'];

                // TASK-029 — the roster channel frame rides the same
                // transaction: the students desk prepends the row the
                // moment this commits (same transaction = committed-only
                // broadcast, the recycling channel's rule).
                RosterUpdateLog::record(RosterUpdate::TYPE_STUDENT_CREATED, [
                    'id' => $student->id,
                    'name' => $student->name,
                    'grade' => $student->grade,
                    'class_id' => $student->class_id,
                    'class_name' => $student->schoolClass?->name,
                    'pae_enrolled' => $student->pae_enrolled,
                    // Admin-only channel: the desk's account column renders
                    // the email on live arrivals too (never the password —
                    // display-once lives only in this HTTP response).
                    'account_email' => $account->email,
                ]);

                return ['student' => $student, 'account' => $account];
            });
        } catch (QueryException $e) {
            // Finding 3.2: the advisory pre-check above can lose a race
            // against a concurrent identical create — the
            // students_name_class_unique invariant owns the truth. A
            // 23000 with a twin row present renders as the same honest
            // 422 duplicate; any other integrity failure is a true
            // anomaly and rethrows (never mislabeled).
            $twin = $e->getCode() === '23000'
                && Student::query()
                    ->where('name', $validated['name'])
                    ->where('class_id', $validated['class_id'])
                    ->exists();

            if (! $twin) {
                throw $e;
            }

            return response()->json([
                'status' => 'error',
                'reason' => 'duplicate',
                'message' => __('api.student_duplicate', [
                    'name' => $validated['name'],
                    'class' => SchoolClass::find($validated['class_id'])?->name ?? '',
                ]),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'grade' => $student->grade,
                'class_name' => $student->schoolClass?->name,
                'pae_enrolled' => $student->pae_enrolled,
                // The desk's live row renders the account column from
                // the same object (frames carry it as account_email too).
                'account_email' => $account->email,
            ],
            // Display-once credentials (the reader-API-key rule): this
            // response is the ONLY place the temporary password appears.
            'account' => [
                'email' => $account->email,
                'temporary_password' => (string) config('presence.student_initial_password', 'password'),
                'must_change_password' => true,
            ],
            'message' => __('api.student_created', ['name' => $student->name]),
            'account_notice' => __('api.student_account_notice', [
                'email' => $account->email,
                'password' => (string) config('presence.student_initial_password', 'password'),
            ]),
        ]);
    }

    public function import(Request $request, StudentAccountService $accounts): JsonResponse
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

            // TASK-029 — the whole import (creates + the ONE roster
            // frame describing them) runs inside a single transaction:
            // committed-only broadcast, and a mid-import failure can
            // never leave a half-applied roster.
            // TASK-030-A — logins ride the same transaction: every
            // imported student leaves with an account, or nothing
            // commits at all. Accounts allocate in ONE batch after the
            // rows (finding 1.2: per-row existence probes are O(n²)).
            [$created, $errors, $dataRows, $students, $accountEmails] = DB::transaction(function () use ($handle, $columns, $classes, $classesLower, $accounts) {
                $created = 0;
                $errors = [];
                $dataRows = 0;
                $rowNumber = 1; // header is row 1
                $students = [];
                $capped = false;

                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    if (count($row) === 1 && trim((string) $row[0]) === '') {
                        continue; // blank line — not a data row
                    }

                    $dataRows++;

                    // Finding 3.3: rows past the cap are COUNTED, never
                    // silently dropped — failed = dataRows - created
                    // stays exact, and the single cap error says why.
                    if ($capped || $dataRows > self::IMPORT_MAX_ROWS) {
                        if (! $capped) {
                            $capped = true;
                            $errors[] = [
                                'row' => $rowNumber,
                                'message' => __('api.students_import_too_many_rows', ['max' => self::IMPORT_MAX_ROWS]),
                            ];
                        }

                        continue;
                    }

                    $result = $this->importRow($row, $columns, $classes, $classesLower);

                    if ($result['error'] !== null) {
                        $errors[] = ['row' => $rowNumber, 'message' => $result['error']];

                        continue;
                    }

                    try {
                        $students[] = Student::create($result['attributes']);
                    } catch (QueryException $e) {
                        // Finding 3.2 (import half): an external race
                        // against a concurrent enrollment trips the
                        // unique invariant mid-file. Statement-level
                        // failure rolls back only the statement — the
                        // file keeps its per-row semantics with a
                        // duplicate row error, never a 500 mid-import.
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }

                        $errors[] = ['row' => $rowNumber, 'message' => __('api.student_duplicate', [
                            'name' => (string) ($result['attributes']['name'] ?? ''),
                            'class' => (string) (SchoolClass::find($result['attributes']['class_id'] ?? 0)?->name ?? ''),
                        ])];

                        continue;
                    }

                    $created++;
                }

                $accountEmails = $accounts->provisionMany(collect($students));

                if ($students !== []) {
                    // One frame per import: the desk prepends every row
                    // from this single committed payload (idempotent —
                    // a row that already renders is updated, not duplicated).
                    RosterUpdateLog::record(RosterUpdate::TYPE_STUDENTS_IMPORTED, [
                        'students' => array_map(fn (Student $s) => [
                            'id' => $s->id,
                            'name' => $s->name,
                            'grade' => $s->grade,
                            'class_id' => $s->class_id,
                            'class_name' => $s->schoolClass?->name,
                            'pae_enrolled' => $s->pae_enrolled,
                            // Admin-only channel (see store()).
                            'account_email' => $accountEmails[$s->id] ?? null,
                        ], $students),
                    ]);
                }

                return [$created, $errors, $dataRows, $students, $accountEmails];
            });
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

        // Finding 3.3: failed is the TRUE shortfall (every data row is
        // either created or not), not the error-entry count — a capped
        // file reports one cap error for many uncreated rows.
        $failed = $dataRows - $created;

        return response()->json([
            'status' => $status,
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
            'students' => array_map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'class_name' => $s->schoolClass?->name,
                // Display-once (see store()): the email per row; the
                // shared temporary password is documented on the desk
                // and in the API guide, never per-row secret material.
                'account_email' => $accountEmails[$s->id] ?? null,
            ], $students),
            'accounts_created' => $created,
            'message' => __('api.students_import_summary', [
                'created' => $created,
                'failed' => count($errors),
            ]),
        ], $status === 'error' ? 422 : 200);
    }

    /**
     * TASK-030-A (ADR-044) — backfill the login for a pre-feature
     * student row (or any account-less row, e.g. after a console
     * import): idempotent — a student that already has an account
     * keeps it (`already: true`, no temp password re-issued).
     *
     * POST /api/v1/admin/students/{student}/account — admin-only.
     */
    public function provisionAccount(Student $student, StudentAccountService $accounts): JsonResponse
    {
        ['user' => $user, 'already' => $already] = $accounts->provisionFor($student);

        return response()->json([
            'status' => 'ok',
            'already' => $already,
            'account' => array_filter([
                'email' => $user->email,
                // Display-once: only a freshly minted account carries
                // the temporary password (see store()).
                'temporary_password' => $already ? null : (string) config('presence.student_initial_password', 'password'),
                'must_change_password' => (bool) $user->must_change_password,
            ], fn ($value) => $value !== null),
            'message' => $already
                ? __('api.student_account_exists', ['email' => $user->email])
                : __('api.student_account_notice', [
                    'email' => $user->email,
                    'password' => (string) config('presence.student_initial_password', 'password'),
                ]),
        ]);
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
