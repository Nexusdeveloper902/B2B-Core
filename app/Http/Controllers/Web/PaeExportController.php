<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PaeReportService;
use App\Services\Pdf\PaeReportPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-037 — PAE report exports (ADR-056).
 *
 * GET /admin/reports/pae/export/csv?type=daily|monthly|missed|flagged
 * GET /admin/reports/pae/student/{student}/export/csv
 * GET /admin/reports/pae/export/pdf?type=...
 * GET /admin/reports/pae/student/{student}/export/pdf
 *
 * CSV = raw structured rows (header row, UTF-8, semicolon-free simple
 * commas, BOM-free; ready for further analysis). PDF = polished,
 * presentation-ready (branded header, charts, tables) rendered by the
 * dompdf-based PaeReportPdf service. Admin-only, session-authed.
 */
class PaeExportController extends Controller
{
    public function __construct(
        private readonly PaeReportService $reports,
        private readonly PaeReportPdf $pdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $type = (string) $request->query('type', 'daily');
        $today = Carbon::today()->toDateString();
        $date = $this->safeDate((string) $request->query('date', $today));
        $meal = $this->safeMeal($request);
        $month = $this->safeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        [$filename, $rows] = match ($type) {
            'monthly' => $this->monthlyCsv($month),
            'missed' => $this->missedCsv($meal, $date),
            'flagged' => $this->flaggedCsv((string) $request->query('from', $today), (string) $request->query('to', $today)),
            default => $this->dailyCsv($date),
        };

        return $this->streamCsv($filename, $rows);
    }

    public function studentCsv(Student $student): StreamedResponse
    {
        $history = $this->reports->studentHistory($student, 90);

        $rows = [[
            'date', 'breakfast', 'breakfast_status', 'breakfast_reason',
            'lunch', 'lunch_status', 'lunch_reason',
        ]];

        foreach ($history['days'] as $day) {
            $rows[] = [
                $day['date'],
                $day['breakfast'] ? $day['breakfast']['time'] : '',
                $day['breakfast'] ? $day['breakfast']['status'] : '',
                $day['breakfast'] ? ($day['breakfast']['reason'] ?? '') : '',
                $day['lunch'] ? $day['lunch']['time'] : '',
                $day['lunch'] ? $day['lunch']['status'] : '',
                $day['lunch'] ? ($day['lunch']['reason'] ?? '') : '',
            ];
        }

        return $this->streamCsv('pae-student-'.str_replace(' ', '-', strtolower($student->name)).'.csv', $rows);
    }

    public function pdf(Request $request)
    {
        $type = (string) $request->query('type', 'daily');
        $today = Carbon::today()->toDateString();
        $date = $this->safeDate((string) $request->query('date', $today));
        $meal = $this->safeMeal($request);
        $month = $this->safeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        return match ($type) {
            'monthly' => $this->pdf->monthly($month),
            'missed' => $this->pdf->missed($meal, $date),
            'flagged' => $this->pdf->flagged((string) $request->query('from', $today), (string) $request->query('to', $today)),
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
        $daily = $this->reports->dailyMeals($date);

        $rows = [['class', 'breakfast_students', 'lunch_students']];
        foreach ($daily['by_class'] as $row) {
            $rows[] = [$row['class_name'], (string) $row['breakfast'], (string) $row['lunch']];
        }
        $rows[] = ['TOTAL', (string) $daily['breakfast'], (string) $daily['lunch']];

        return ['pae-daily-'.$date.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function monthlyCsv(string $month): array
    {
        $monthly = $this->reports->monthlyMeals($month);

        $rows = [['date', 'breakfast_students', 'lunch_students']];
        foreach ($monthly['days'] as $day) {
            $rows[] = [$day['date'], (string) $day['breakfast'], (string) $day['lunch']];
        }
        $rows[] = ['TOTAL', (string) $monthly['totals']['breakfast'], (string) $monthly['totals']['lunch']];

        return ['pae-monthly-'.$month.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function missedCsv(string $meal, string $date): array
    {
        $rows = [['student', 'class', 'attended_at', 'meal']];
        foreach ($this->reports->missedMeals($meal, $date) as $row) {
            $rows[] = [$row['name'], (string) $row['class_name'], (string) $row['attended_at'], $row['meal']];
        }

        return ['pae-missed-'.$meal.'-'.$date.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function flaggedCsv(string $from, string $to): array
    {
        $from = $this->safeDate($from);
        $to = $this->safeDate($to);

        $flagged = $this->reports->flaggedAttempts($from, $to);

        $rows = [['occurred_at', 'student', 'class', 'meal', 'reason']];
        foreach ($flagged['rows'] as $row) {
            $rows[] = [
                $row['occurred_at'],
                (string) $row['student_name'],
                (string) $row['class_name'],
                $row['meal'] ?? 'attempt',
                $row['reason'],
            ];
        }

        return ['pae-flagged-'.$from.'_'.$to.'.csv', $rows];
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

    private function safeDate(string $raw): string
    {
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }

    private function safeMeal(Request $request): string
    {
        return $request->query('meal') === 'breakfast' ? 'breakfast' : 'lunch';
    }

    private function safeMonth(string $raw): string
    {
        try {
            return Carbon::parse($raw.'-01')->format('Y-m');
        } catch (\Throwable) {
            return Carbon::today()->format('Y-m');
        }
    }
}
