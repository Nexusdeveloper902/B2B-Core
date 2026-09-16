<?php

namespace App\Services\Pdf;

use App\Models\Student;
use App\Services\RecyclingReportService;
use Illuminate\Support\Carbon;

/**
 * Polished, presentation-ready recycling report PDFs — the same branded
 * shell as the PAE exports (BrandedReportPdf): a report generated under
 * a school wears its primary color, crest and name.
 *
 * Data comes from RecyclingReportService, the same derivations the
 * EcoStation hub reads, so a PDF can never disagree with it. Locale
 * resolves through the session at render time (EN/ES).
 */
class RecyclingReportPdf extends BrandedReportPdf
{
    public function __construct(
        private readonly RecyclingReportService $reports,
    ) {}

    /** Daily report: summary + material mix. */
    public function daily(string $date)
    {
        $daily = $this->reports->daily($date);

        $body = $this->summaryRow(
            __('app.report_stat_items'), (string) $daily['items'],
            __('app.report_stat_points'), (string) $daily['points'],
            __('app.report_stat_date'), $this->prettyDate($date)
        );

        $body .= $this->tableSection(__('app.report_material_mix'), [
            [__('app.material'), __('app.report_col_items'), __('app.report_col_points'), __('app.report_col_rate')],
        ], array_map(fn ($material, $row) => [
            __('app.material_'.$material), (string) $row['items'], (string) $row['points'], $row['rate'].' pts',
        ], array_keys($daily['by_material']), array_values($daily['by_material'])));

        return $this->render(
            __('app.report_recycling_daily_title', ['date' => $this->prettyDate($date)]),
            $date,
            $body,
            __('app.reports_recycling')
        );
    }

    /** Monthly report: totals + per-day bars. */
    public function monthly(string $month)
    {
        $monthly = $this->reports->monthly($month);

        $body = $this->summaryRow(
            __('app.report_stat_items'), (string) $monthly['totals']['items'],
            __('app.report_stat_points'), (string) $monthly['totals']['points'],
            __('app.report_stat_month'), $month
        );

        $max = 1;
        foreach ($monthly['days'] as $day) {
            $max = max($max, $day['items']);
        }

        $bars = '';
        foreach ($monthly['days'] as $day) {
            if ($day['items'] === 0) {
                continue; // quiet days stay honest but compact
            }
            $bars .= $this->barRow(
                $this->prettyDate($day['date']),
                (string) $day['items'],
                (int) round(100 * $day['items'] / $max),
                $this->primary()
            );
        }

        $body .= $this->section(
            __('app.report_daily_trend', ['month' => $month]),
            $bars === '' ? '<p style="color:'.self::MUTED.';font-size:10px;">'.e(__('app.report_empty')).'</p>' : $bars
        );

        return $this->render(
            __('app.report_recycling_monthly_title', ['month' => $month]),
            Carbon::parse($month.'-01')->toDateString(),
            $body,
            __('app.reports_recycling')
        );
    }

    /** Leaderboard report: the points board, straight from the ledger. */
    public function leaderboard(int $limit = 20)
    {
        $board = $this->reports->leaderboard($limit);

        $top = $board[0] ?? null;

        $body = $this->summaryRow(
            __('app.report_stat_leader'), $top !== null ? $top['student_name'] : '—',
            __('app.report_stat_top_points'), $top !== null ? (string) $top['points'] : '0',
            __('app.report_stat_students'), (string) count($board)
        );

        $body .= $this->tableSection(__('app.leaderboard_board'), [
            [__('app.report_col_rank'), __('app.report_col_student'), __('app.report_col_class'), __('app.report_col_points')],
        ], array_map(fn ($row) => [
            (string) $row['rank'], $row['student_name'], (string) $row['class_name'], (string) $row['points'],
        ], $board));

        return $this->render(
            __('app.report_leaderboard_doc_title'),
            Carbon::today()->toDateString(),
            $body,
            __('app.reports_recycling')
        );
    }

    /** Per-student report: earn/spend totals + per-deposit history. */
    public function student(Student $student)
    {
        $history = $this->reports->studentHistory($student, 30);

        $body = '<p style="margin:0 0 12px;font-size:14px;color:'.self::TEXT.';">'
            .e($student->name)
            .' <span style="color:'.self::MUTED.';">· '.e((string) $student->schoolClass?->name).'</span></p>';

        $body .= $this->summaryRow(
            __('app.report_stat_items'), (string) $history['totals']['items'],
            __('app.report_stat_points'), (string) $history['totals']['points'],
            __('app.report_stat_redeemed'), (string) $history['totals']['redeemed']
        );

        $rows = array_map(fn ($row) => [
            $this->prettyDate($row['date']).' '.$row['time'],
            __('app.material_'.$row['material']),
            (string) $row['points'],
            (string) $row['reader'],
        ], array_slice($history['deposits'], 0, 40));

        $body .= $this->tableSection(__('app.report_student_history_title'), [
            [__('app.report_col_time'), __('app.material'), __('app.report_col_points'), __('app.reader')],
        ], $rows);

        return $this->render(
            __('app.report_recycling_student_doc_title', ['name' => $student->name]),
            Carbon::today()->toDateString(),
            $body,
            __('app.reports_recycling')
        );
    }
}
