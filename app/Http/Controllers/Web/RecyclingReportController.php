<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Pdf\RecyclingReportPdf;
use App\Services\RecyclingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The recycling reporting desk: /admin/reports/recycling.
 *
 * Mirrors the PAE desk (PaeReportController + PaeExportController) for
 * the recycling area: daily/monthly yields with SVG trend charts, the
 * material mix, the points leaderboard, per-student histories — with
 * PDF and CSV exports. One data layer (RecyclingReportService) shared
 * with the EcoStation hub, so a PDF can never disagree with it.
 * Admin-only, session-authed.
 */
class RecyclingReportController extends Controller
{
    public function __construct(
        private readonly RecyclingReportService $reports,
        private readonly RecyclingReportPdf $pdf,
    ) {}

    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();

        $date = $this->dateParam($request, $today);
        $month = $this->normalizeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));
        $from14 = Carbon::today()->subDays(13)->toDateString();

        return view('admin.reports.recycling', [
            'date' => $date,
            'month' => $month,
            'daily' => $this->reports->daily($date),
            'trend' => $this->reports->trend($from14, $today),
            'monthly' => $this->reports->monthly($month),
            'mix' => $this->reports->byMaterial($from14, $today),
            'leaderboard' => $this->reports->leaderboard(10),
            'students' => Student::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function student(Request $request, Student $student)
    {
        $days = max(1, min(90, (int) $request->query('days', 30)));

        return view('admin.reports.recycling-student', [
            'student' => $student,
            'history' => $this->reports->studentHistory($student, $days),
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
            'leaderboard' => $this->leaderboardCsv(),
            default => $this->dailyCsv($date),
        };

        return $this->streamCsv($filename, $rows);
    }

    public function studentCsv(Student $student): StreamedResponse
    {
        $history = $this->reports->studentHistory($student, 90);

        $rows = [['date', 'time', 'material', 'points', 'reader']];
        foreach ($history['deposits'] as $row) {
            $rows[] = [$row['date'], $row['time'], $row['material'], (string) $row['points'], (string) $row['reader']];
        }

        return $this->streamCsv('recycling-student-'.$student->id.'.csv', $rows);
    }

    public function pdf(Request $request)
    {
        $type = (string) $request->query('type', 'daily');
        $today = Carbon::today()->toDateString();
        $date = $this->safeDate((string) $request->query('date', $today));
        $month = $this->safeMonth((string) $request->query('month', Carbon::parse($date)->format('Y-m')));

        return match ($type) {
            'monthly' => $this->pdf->monthly($month),
            'leaderboard' => $this->pdf->leaderboard(),
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
        $daily = $this->reports->daily($date);

        $rows = [['material', 'items', 'points', 'rate']];
        foreach ($daily['by_material'] as $material => $row) {
            $rows[] = [$material, (string) $row['items'], (string) $row['points'], (string) $row['rate']];
        }
        $rows[] = ['TOTAL', (string) $daily['items'], (string) $daily['points'], ''];

        return ['recycling-daily-'.$date.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function monthlyCsv(string $month): array
    {
        $monthly = $this->reports->monthly($month);

        $rows = [['date', 'items', 'points']];
        foreach ($monthly['days'] as $day) {
            $rows[] = [$day['date'], (string) $day['items'], (string) $day['points']];
        }
        $rows[] = ['TOTAL', (string) $monthly['totals']['items'], (string) $monthly['totals']['points']];

        return ['recycling-monthly-'.$month.'.csv', $rows];
    }

    /**
     * @return array{0: string, 1: array<int, array<int, string>>}
     */
    private function leaderboardCsv(): array
    {
        $rows = [['rank', 'student', 'class', 'points']];
        foreach ($this->reports->leaderboard(50) as $row) {
            $rows[] = [(string) $row['rank'], $row['student_name'], (string) $row['class_name'], (string) $row['points']];
        }

        return ['recycling-leaderboard.csv', $rows];
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
