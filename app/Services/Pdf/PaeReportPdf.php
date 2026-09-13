<?php

namespace App\Services\Pdf;

use App\Models\Student;
use App\Services\PaeReportService;
use Dompdf\Dompdf;
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
 * time (EN/ES parity with the web reports). Brand: the Pulse palette
 * (sage/gold/ink) as flat fills.
 */
class PaeReportPdf
{
    private const INK = '#242423';

    private const GOLD = '#F5CB5C';

    private const SAGE = '#CFDBD5';

    private const PAPER = '#E8EDDF';

    private const MUTED = '#6B6F6D';

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
            $body
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
                (int) round(100 * $total / $max)
            );
        }

        $body .= $this->section(__(
            'app.report_daily_trend',
            ['month' => $month]
        ), $bars);

        return $this->render(
            __('app.report_monthly_title', ['month' => $month]),
            Carbon::parse($month.'-01')->toDateString(),
            $body
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
            $body
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
            $body
        );
    }

    /** Per-student report: enrollment, totals, per-day history. */
    public function student(Student $student)
    {
        $history = $this->reports->studentHistory($student, 30);

        $body = '<p style="margin:0 0 6px;font-size:14px;color:'.self::INK.';">'
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
            $body
        );
    }

    // ------------------------------------------------------------------
    // Building blocks (dompdf-safe inline styles only)
    // ------------------------------------------------------------------

    private function render(string $title, string $period, string $body)
    {
        $html = '<html><head><meta charset="utf-8">'
            .'<style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#242423;}</style>'
            .'</head><body>'
            .$this->header($title, $period)
            .$body
            .$this->footer()
            .'</body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $slug = 'pae-report-'.str_replace(' ', '-', strtolower($title));

        // dompdf's stream() echoes directly (bypasses Laravel's response
        // pipeline); output() + a real Response keeps headers honest.
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$slug.'.pdf"',
        ]);
    }

    private function header(string $title, string $period): string
    {
        return '<table style="width:100%;background-color:'.self::INK.';border-collapse:collapse;" cellpadding="10"><tr>'
            .'<td style="color:'.self::GOLD.';font-size:20px;font-weight:bold;width:30%;">Pulse</td>'
            .'<td style="color:'.self::PAPER.';font-size:14px;text-align:right;">'
            .e($title).'<br><span style="font-size:10px;color:'.self::SAGE.';">'.e($period).'</span></td>'
            .'</tr></table><div style="height:14px;"></div>';
    }

    private function summaryRow(string $labelA, string $valueA, string $labelB, string $valueB, string $labelC, string $valueC): string
    {
        $cells = '';
        foreach ([$labelA => $valueA, $labelB => $valueB, $labelC => $valueC] as $label => $value) {
            if ($label === '') {
                continue;
            }
            $cells .= '<td style="background-color:'.self::SAGE.';padding:10px;width:33%;border:1px solid '.self::PAPER.';">'
                .'<span style="font-size:9px;color:'.self::MUTED.';">'.e($label).'</span><br>'
                .'<span style="font-size:20px;color:'.self::INK.';">'.e($value).'</span></td>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-bottom:14px;"><tr>'.$cells.'</tr></table>';
    }

    private function section(string $title, string $content): string
    {
        return '<p style="margin:0 0 6px;font-size:12px;border-bottom:2px solid '.self::GOLD.';padding-bottom:3px;">'
            .e($title).'</p>'.$content.'<div style="height:12px;"></div>';
    }

    /**
     * Horizontal CSS bars — dompdf-reliable "chart": one row per entry,
     * bar width proportional to the max.
     */
    private function barRow(string $label, string $value, int $percent): string
    {
        $percent = max(2, min(100, $percent));

        return '<table style="width:100%;border-collapse:collapse;margin-bottom:2px;"><tr>'
            .'<td style="width:22%;font-size:9px;color:'.self::MUTED.';">'.e($label).'</td>'
            .'<td style="width:66%;background-color:'.self::PAPER.';padding:2px;">'
            .'<div style="background-color:'.self::GOLD.';height:10px;width:'.$percent.'%;"></div></td>'
            .'<td style="width:12%;font-size:10px;text-align:right;">'.e($value).'</td>'
            .'</tr></table>';
    }

    /**
     * @param  array<int, array<int, string>>  $headerRow
     * @param  array<int, array<int, string>>  $rows
     */
    private function tableSection(string $title, array $headerRow, array $rows): string
    {
        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 1 ? self::PAPER : '#FFFFFF';
            $cells = '';
            foreach ($row as $cell) {
                $cells .= '<td style="padding:5px 8px;border:1px solid '.self::SAGE.';font-size:10px;">'.e((string) $cell).'</td>';
            }
            $rowsHtml .= '<tr style="background-color:'.$bg.';">'.$cells.'</tr>';
        }

        if ($rows === []) {
            $span = max(1, count($headerRow[0]));
            $rowsHtml = '<tr><td colspan="'.$span.'" style="padding:8px;border:1px solid '.self::SAGE
                .';color:'.self::MUTED.';font-size:10px;">'.e(__('app.report_empty')).'</td></tr>';
        }

        $headCells = '';
        foreach ($headerRow[0] as $cell) {
            $headCells .= '<td style="padding:5px 8px;background-color:'.self::INK.';color:'.self::PAPER
                .';font-size:9px;border:1px solid '.self::INK.';">'.e((string) $cell).'</td>';
        }

        return $this->section($title, '<table style="width:100%;border-collapse:collapse;"><tr>'.$headCells.'</tr>'.$rowsHtml.'</table>');
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
                .'<div style="background-color:'.self::INK.';height:9px;width:'.max(2, $percent).'%;"></div></td>'
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

    private function footer(): string
    {
        return '<div style="height:18px;"></div><table style="width:100%;border-collapse:collapse;"><tr>'
            .'<td style="font-size:8px;color:'.self::MUTED.';">Pulse · PAE</td>'
            .'<td style="font-size:8px;color:'.self::MUTED.';text-align:right;">'
            .e(now()->format('Y-m-d H:i')).'</td></tr></table>';
    }

    private function prettyDate(string $date): string
    {
        try {
            return Carbon::parse($date)->isoFormat('MMM D, YYYY');
        } catch (\Throwable) {
            return $date;
        }
    }
}
