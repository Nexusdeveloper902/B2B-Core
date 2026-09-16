<?php

namespace App\Services\Pdf;

use App\Models\Student;
use App\Services\PaeReportService;
use Illuminate\Support\Carbon;

/**
 * TASK-037 — polished, presentation-ready PAE report PDFs (ADR-056).
 *
 * Rendered with dompdf (pure PHP, no external binaries) from
 * inline-styled HTML — dompdf's safe CSS subset: tables, borders,
 * background colors, fixed px dimensions. "Charts" are horizontal CSS
 * bars (one row per day), which render reliably in dompdf where
 * absolute positioning is fragile.
 *
 * Locale: every string resolves through the session locale at render
 * time (EN/ES parity with the web reports). Brand: the shell comes
 * from BrandedReportPdf — a report generated under a school wears its
 * primary color, crest and name; stock Pulse renders exactly as before.
 */
class PaeReportPdf extends BrandedReportPdf
{
    public function __construct(
        private readonly PaeReportService $reports,
    ) {}

    /** Daily report: summary + per-class table + flagged breakdown. */
    public function daily(string $date)
    {
        $daily = $this->reports->dailyMeals($date);
        $flagged = $this->reports->flaggedAttempts($date, $date);

        $body = $this->summaryRow(
            __('app.report_stat_breakfast'), (string) $daily['breakfast'],
            __('app.report_stat_lunch'), (string) $daily['lunch'],
            __('app.report_stat_total'), (string) $daily['total']
        );

        $body .= $this->tableSection(__('app.report_by_class'), [
            [__('app.report_col_class'), __('app.report_col_breakfast'), __('app.report_col_lunch')],
        ], array_map(fn ($row) => [
            $row['class_name'], (string) $row['breakfast'], (string) $row['lunch'],
        ], $daily['by_class']));

        $body .= $this->flaggedSection($flagged);

        return $this->render(
            __('app.report_daily_title', ['date' => $this->prettyDate($date)]),
            $date,
            $body,
            'PAE'
        );
    }

    /** Monthly report: summary + per-day bars. */
    public function monthly(string $month)
    {
        $monthly = $this->reports->monthlyMeals($month);

        $body = $this->summaryRow(
            __('app.report_stat_breakfast'), (string) $monthly['totals']['breakfast'],
            __('app.report_stat_lunch'), (string) $monthly['totals']['lunch'],
            __('app.report_stat_total'), (string) $monthly['totals']['total']
        );

        $max = 1;
        foreach ($monthly['days'] as $day) {
            $max = max($max, $day['breakfast'] + $day['lunch']);
        }

        $bars = '';
        foreach ($monthly['days'] as $day) {
            if ($day['breakfast'] === 0 && $day['lunch'] === 0) {
                continue; // non-service days stay honest but compact
            }
            $total = $day['breakfast'] + $day['lunch'];
            $bars .= $this->barRow(
                $this->prettyDate($day['date']),
                (string) $total,
                (int) round(100 * $total / $max),
                self::GOLD
            );
        }

        $body .= $this->section(__(
            'app.report_daily_trend',
            ['month' => $month]
        ), $bars);

        return $this->render(
            __('app.report_monthly_title', ['month' => $month]),
            Carbon::parse($month.'-01')->toDateString(),
            $body,
            'PAE'
        );
    }

    /** Missed-meal report for one meal + date. */
    public function missed(string $meal, string $date)
    {
        $missed = $this->reports->missedMeals($meal, $date);

        $body = $this->summaryRow(
            __('app.report_stat_meal'), __('api.meal_'.$meal),
            __('app.report_stat_missed'), (string) count($missed),
            __('app.report_stat_date'), $this->prettyDate($date)
        );

        $body .= $this->tableSection(__('app.report_missed_title', ['meal' => __('api.meal_'.$meal)]), [
            [__('app.report_col_student'), __('app.report_col_class'), __('app.report_col_attended')],
        ], array_map(fn ($row) => [
            $row['name'], (string) $row['class_name'], (string) $row['attended_at'],
        ], $missed));

        return $this->render(
            __('app.report_missed_doc_title', ['meal' => __('api.meal_'.$meal)]),
            $date,
            $body,
            'PAE'
        );
    }

    /** Flagged (excluded) attempts report for a range. */
    public function flagged(string $from, string $to)
    {
        $flagged = $this->reports->flaggedAttempts($from, $to);

        $body = $this->summaryRow(
            __('app.report_stat_attempts'), (string) $flagged['total'],
            __('app.report_stat_range'), $this->prettyDate($from).' – '.$this->prettyDate($to),
            '', ''
        );

        $body .= $this->flaggedSection($flagged, 40);

        return $this->render(
            __('app.report_flagged_title'),
            $from,
            $body,
            'PAE'
        );
    }

    /** Per-student report: enrollment, totals, per-day history. */
    public function student(Student $student)
    {
        $history = $this->reports->studentHistory($student, 30);

        $body = '<p style="margin:0 0 6px;font-size:14px;color:'.self::TEXT.';">'
            .e($student->name)
            .' <span style="color:'.self::MUTED.';">· '.e((string) $student->schoolClass?->name).'</span></p>';

        $enrollment = ($student->pae_breakfast_enrolled ? '✓ '.__('app.report_breakfast') : '✗ '.__('app.report_breakfast'))
            .' &nbsp; '.($student->pae_lunch_enrolled ? '✓ '.__('app.report_lunch') : '✗ '.__('app.report_lunch'));
        $body .= '<p style="margin:0 0 12px;font-size:11px;color:'.self::MUTED.';">'
            .__('app.pae_enrolled').' — '.$enrollment.'</p>';

        $body .= $this->summaryRow(
            __('app.report_stat_breakfasts'), (string) $history['totals']['breakfasts'],
            __('app.report_stat_lunches'), (string) $history['totals']['lunches'],
            __('app.report_stat_flagged'), (string) $history['totals']['flagged']
        );

        $rows = array_map(fn ($day) => [
            $this->prettyDate($day['date']),
            $day['breakfast'] ? $day['breakfast']['time'].' ('.$day['breakfast']['status'].($day['breakfast']['reason'] ? ', '.$day['breakfast']['reason'] : '').')' : '—',
            $day['lunch'] ? $day['lunch']['time'].' ('.$day['lunch']['status'].($day['lunch']['reason'] ? ', '.$day['lunch']['reason'] : '').')' : '—',
        ], array_slice($history['days'], 0, 40));

        $body .= $this->tableSection(__('app.report_student_history_title'), [
            [__('app.report_col_date'), __('app.report_breakfast'), __('app.report_lunch')],
        ], $rows);

        return $this->render(
            __('app.report_student_doc_title', ['name' => $student->name]),
            Carbon::today()->toDateString(),
            $body,
            'PAE'
        );
    }

    /**
     * Flagged attempts: reason breakdown bars + recent rows table.
     *
     * @param  array{rows: array<int, array<string, mixed>>, by_reason: array<string, int>, total: int}  $flagged
     */
    private function flaggedSection(array $flagged, int $limit = 25): string
    {
        $body = '<table style="width:100%;border-collapse:collapse;margin-bottom:8px;">';

        $max = max(1, ...array_values($flagged['by_reason'] ?: [1]));
        foreach ($flagged['by_reason'] as $reason => $count) {
            $percent = (int) round(100 * $count / $max);
            $body .= '<tr>'
                .'<td style="width:30%;font-size:10px;">'.e(__('api.pae_reason_'.$reason)).'</td>'
                .'<td style="width:58%;background-color:'.self::PAPER.';padding:2px;">'
                .'<div style="background-color:'.$this->primary().';height:9px;width:'.max(2, $percent).'%;"></div></td>'
                .'<td style="width:12%;font-size:10px;text-align:right;">'.e((string) $count).'</td>'
                .'</tr>';
        }

        if ($flagged['by_reason'] === []) {
            $body .= '<tr><td style="color:'.self::MUTED.';font-size:10px;">'.e(__('app.report_empty')).'</td></tr>';
        }
        $body .= '</table>';

        $rows = array_map(fn ($row) => [
            substr((string) $row['occurred_at'], 0, 16),
            (string) $row['student_name'],
            (string) $row['class_name'],
            $row['meal'] !== null ? __('api.meal_'.$row['meal']) : '—',
            __('api.pae_reason_'.$row['reason']),
        ], array_slice($flagged['rows'], 0, $limit));

        $body .= $this->tableSection(__('app.report_flagged_title'), [[
            __('app.report_col_time'), __('app.report_col_student'), __('app.report_col_class'),
            __('app.report_col_meal'), __('app.report_col_reason'),
        ]], $rows);

        return $this->section(__('app.report_excluded_note'), $body);
    }
}
