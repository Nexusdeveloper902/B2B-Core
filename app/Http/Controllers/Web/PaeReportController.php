<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\PaeReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TASK-037 — the PAE reporting desk (ADR-056): /admin/reports/pae.
 *
 * General report (daily + monthly views, charts, missed meals, flagged
 * attempts) and per-student report (meal history with graphics). One
 * data layer (PaeReportService) shared with the NL functions. Exports
 * live in PaeExportController (PDF + CSV).
 */
class PaeReportController extends Controller
{
    private const MAX_TREND_DAYS = 31;

    public function __construct(
        private readonly PaeReportService $reports,
        private readonly AttendanceService $attendance,
    ) {}

    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();

        $date = $this->dateParam($request, $today, 'date', 365);
        $month = (string) $request->query('month', Carbon::parse($date)->format('Y-m'));
        $meal = $this->mealParam($request);

        $trendFrom = Carbon::today()->subDays(13)->toDateString();

        return view('admin.reports.pae', [
            'date' => $date,
            'month' => $this->normalizeMonth($month),
            'meal' => $meal,
            'daily' => $this->reports->dailyMeals($date),
            'trend' => $this->reports->mealsTrend($trendFrom, $today),
            'monthly' => $this->reports->monthlyMeals($this->normalizeMonth($month)),
            'missed' => $this->reports->missedMeals($meal, $date),
            'missedTrend' => $this->reports->missedMealTrend($meal, 14),
            'flagged' => $this->reports->flaggedAttempts(
                Carbon::today()->subDays(13)->toDateString(),
                $today,
            ),
            'enrollment' => $this->reports->enrollmentSummary(),
            'students' => Student::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function student(Request $request, Student $student)
    {
        $days = max(1, min(90, (int) $request->query('days', 30)));

        return view('admin.reports.pae-student', [
            'student' => $student,
            'history' => $this->reports->studentHistory($student, $days),
            'days' => $days,
        ]);
    }

    private function dateParam(Request $request, string $default, string $key, int $maxDaysBack): string
    {
        $raw = (string) $request->query($key, $default);

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

    private function mealParam(Request $request): string
    {
        $meal = (string) $request->query('meal', 'lunch');

        return $meal === 'breakfast' ? 'breakfast' : 'lunch';
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
