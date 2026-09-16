<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\Pdf\AttendanceReportPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The attendance reporting desk: /admin/reports/attendance.
 *
 * Mirrors the PAE desk (PaeReportController + PaeExportController) for
 * the attendance area: daily present/late/absent with per-class
 * breakdown and SVG trend charts, monthly aggregates, repeat
 * absentees, perfect attendance, per-student history — with PDF and
 * CSV exports. One data layer (AttendanceService) shared with the
 * teacher dashboard and the NL functions, so a PDF can never disagree
 * with either. Admin-only, session-authed.
 */
class AttendanceReportController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceReportPdf $pdf,
    ) {}

    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();

        $date = $this->dateParam($request, $today);
        $month = $this->normalizeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        $byClass = $this->attendance->attendanceByClass($date);
        $trend = $this->attendance->attendanceTrend(14);

        return view('admin.reports.attendance', [
            'date' => $date,
            'month' => $month,
            'byClass' => $byClass,
            'present' => array_sum(array_column($byClass, 'present')),
            'absent' => array_sum(array_column($byClass, 'absent')),
            'late' => $this->attendance->lateStudents($date),
            'absentList' => $this->attendance->absentStudents($date),
            'trend' => $trend,
            'monthly' => $this->attendance->monthlyAttendance($month),
            'repeatAbsent' => array_slice($this->attendance->repeatedlyAbsentStudents(30, 3), 0, 10),
            'perfectCount' => count($this->attendance->perfectAttendance(30)),
            'students' => Student::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function student(Request $request, Student $student)
    {
        $days = max(1, min(90, (int) $request->query('days', 30)));

        return view('admin.reports.attendance-student', [
            'student' => $student,
            'history' => $this->attendance->studentAttendanceHistory($student, $days),
            'days' => $days,
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $type = (string) $request->query('type', 'daily');
        $today = Carbon::today()->toDateString();
        $date = $this->safeDate((string) $request->query('date', $today));
        $month = $this->safeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        [$filename, $rows] = match ($type) {
            'monthly' => $this->monthlyCsv($month),
            'absentees' => $this->absenteesCsv(),
            default => $this->dailyCsv($date),
        };

        return $this->streamCsv($filename, $rows);
    }

    public function studentCsv(Student $student): StreamedResponse
    {
        $history = $this->attendance->studentAttendanceHistory($student, 90);

        $rows = [['date', 'status', 'tapped_at']];
        foreach ($history['days'] as $day) {
            $rows[] = [$day['date'], $day['status'], $day['tapped_at'] ?? ''];
        }

        return $this->streamCsv('attendance-student-'.$student->id.'.csv', $rows);
    }

    public function pdf(Request $request)
    {
        $type = (string) $request->query('type', 'daily');
        $today = Carbon::today()->toDateString();
        $date = $this->safeDate((string) $request->query('date', $today));
        $month = $this->safeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        return match ($type) {
            'monthly' => $this->pdf->monthly($month),
            'absentees' => $this->pdf->absentees(),
            default => $this->pdf->daily($date),
        };
    }

    public function studentPdf(Student $student)
    {
        return $this->pdf->student($student);
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function dailyCsv(string $date): array
    {
        $rows = [['class', 'enrolled', 'present', 'absent', 'rate_pct']];
        foreach ($this->attendance->attendanceByClass($date) as $row) {
            $rows[] = [$row['class_name'], (string) $row['enrolled'], (string) $row['present'], (string) $row['absent'], (string) $row['rate']];
        }

        return ['attendance-daily-'.$date.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function monthlyCsv(string $month): array
    {
        $monthly = $this->attendance->monthlyAttendance($month);

        $rows = [['date', 'students']];
        foreach ($monthly['days'] as $day) {
            $rows[] = [$day['date'], (string) $day['students']];
        }
        $rows[] = ['TOTAL', (string) $monthly['totals']['attendances']];

        return ['attendance-monthly-'.$month.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function absenteesCsv(): array
    {
        $rows = [['student', 'class', 'absences', 'school_days']];
        foreach ($this->attendance->repeatedlyAbsentStudents(30, 3) as $row) {
            $rows[] = [$row['name'], (string) $row['class_name'], (string) $row['absences'], (string) $row['school_days']];
        }

        return ['attendance-absentees.csv', $rows];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function streamCsv(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function dateParam(Request $request, string $default): string
    {
        $raw = (string) $request->query('date', $default);

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable) {
            return $default;
        }

        if ($date->gt(Carbon::today())) {
            return $default;
        }

        return $date->toDateString();
    }

    private function safeDate(string $raw): string
    {
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }

    private function safeMonth(string $raw): string
    {
        return $this->normalizeMonth($raw);
    }

    private function normalizeMonth(string $month): string
    {
        try {
            return Carbon::parse($month.'-01')->format('Y-m');
        } catch (\Throwable) {
            return Carbon::today()->format('Y-m');
        }
    }
}
