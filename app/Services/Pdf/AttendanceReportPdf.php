<?php

namespace App\Services\Pdf;

use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;

/**
 * Polished, presentation-ready attendance report PDFs — the same
 * branded shell as the PAE exports (BrandedReportPdf): a report
 * generated under a school wears its primary color, crest and name.
 *
 * Data comes from AttendanceService, the same layer behind the teacher
 * dashboard and the NL functions, so a PDF can never disagree with
 * either. Locale resolves through the session at render time (EN/ES).
 */
class AttendanceReportPdf extends BrandedReportPdf
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    /** Daily report: totals + per-class table + late/absent lists. */
    public function daily(string $date)
    {
        $byClass = $this->attendance->attendanceByClass($date);
        $late = $this->attendance->lateStudents($date);
        $absent = $this->attendance->absentStudents($date);

        $present = array_sum(array_column($byClass, 'present'));
        $absentCount = array_sum(array_column($byClass, 'absent'));

        $body = $this->summaryRow(
            __('app.report_stat_present'), (string) $present,
            __('app.report_stat_late'), (string) count($late),
            __('app.report_stat_absent'), (string) $absentCount
        );

        $body .= $this->tableSection(__('app.report_by_class_attendance'), [
            [__('app.report_col_class'), __('app.report_col_enrolled'), __('app.report_col_present'), __('app.report_col_absent'), __('app.report_col_rate')],
        ], array_map(fn ($row) => [
            $row['class_name'], (string) $row['enrolled'], (string) $row['present'], (string) $row['absent'], $row['rate'].'%',
        ], $byClass));

        $body .= $this->tableSection(__('app.report_late_title'), [
            [__('app.report_col_student'), __('app.report_col_class'), __('app.report_col_tapped')],
        ], array_map(fn ($row) => [
            $row['name'], (string) $row['class_name'], (string) $row['tapped_at'],
        ], array_slice($late, 0, 40)));

        $body .= $this->tableSection(__('app.report_absent_title'), [
            [__('app.report_col_student'), __('app.report_col_class')],
        ], array_map(fn ($row) => [
            $row['name'], (string) $row['class_name'],
        ], array_slice($absent, 0, 40)));

        return $this->render(
            __('app.report_attendance_daily_title', ['date' => $this->prettyDate($date)]),
            $date,
            $body,
            __('app.reports_attendance')
        );
    }

    /** Monthly report: totals + per-day bars. */
    public function monthly(string $month)
    {
        $monthly = $this->attendance->monthlyAttendance($month);
        $days = $monthly['days'];

        $body = $this->summaryRow(
            __('app.report_stat_school_days'), (string) $monthly['totals']['school_days'],
            __('app.report_stat_attendances'), (string) $monthly['totals']['attendances'],
            __('app.report_stat_peak'), (string) $monthly['totals']['peak']
        );

        $max = max(1, $monthly['totals']['peak']);
        $bars = '';
        foreach ($days as $day) {
            if ($day['students'] === 0) {
                continue; // non-service days stay honest but compact
            }
            $bars .= $this->barRow(
                $this->prettyDate($day['date']),
                (string) $day['students'],
                (int) round(100 * $day['students'] / $max),
                $this->primary()
            );
        }

        $body .= $this->section(
            __('app.report_daily_trend', ['month' => $month]),
            $bars === '' ? '<p style="color:'.self::MUTED.';font-size:10px;">'.e(__('app.report_empty')).'</p>' : $bars
        );

        return $this->render(
            __('app.report_attendance_monthly_title', ['month' => $month]),
            $monthly['month'].'-01',
            $body,
            __('app.reports_attendance')
        );
    }

    /** Repeat absentees: students absent at least N of the last M school days. */
    public function absentees(int $days = 30, int $minAbsences = 3)
    {
        $rows = $this->attendance->repeatedlyAbsentStudents($days, $minAbsences);

        $body = $this->summaryRow(
            __('app.report_stat_window'), __('app.report_stat_last_days', ['days' => $days]),
            __('app.report_stat_threshold'), __('app.report_stat_min_absences', ['n' => $minAbsences]),
            __('app.report_stat_students'), (string) count($rows)
        );

        $body .= $this->tableSection(__('app.report_absentees_title'), [
            [__('app.report_col_student'), __('app.report_col_class'), __('app.report_col_absences'), __('app.report_col_school_days')],
        ], array_map(fn ($row) => [
            $row['name'], (string) $row['class_name'], (string) $row['absences'], (string) $row['school_days'],
        ], array_slice($rows, 0, 60)));

        return $this->render(
            __('app.report_absentees_doc_title'),
            Carbon::today()->toDateString(),
            $body,
            __('app.reports_attendance')
        );
    }

    /** Per-student report: totals + per-day status history. */
    public function student(Student $student)
    {
        $history = $this->attendance->studentAttendanceHistory($student, 30);

        $body = '<p style="margin:0 0 12px;font-size:14px;color:'.self::TEXT.';">'
            .e($student->name)
            .' <span style="color:'.self::MUTED.';">· '.e((string) $student->schoolClass?->name).'</span></p>';

        $body .= $this->summaryRow(
            __('app.present'), (string) $history['totals']['present'],
            __('app.late'), (string) $history['totals']['late'],
            __('app.absent'), (string) $history['totals']['absent']
        );

        $rows = array_map(fn ($day) => [
            $this->prettyDate($day['date']),
            __('app.'.($day['status'] === 'present' ? 'present' : ($day['status'] === 'late' ? 'late' : 'absent'))),
            $day['tapped_at'] ?? '—',
        ], array_slice($history['days'], 0, 40));

        $body .= $this->tableSection(__('app.report_student_history_title'), [
            [__('app.report_col_date'), __('app.status'), __('app.tapped_at')],
        ], $rows);

        return $this->render(
            __('app.report_attendance_student_doc_title', ['name' => $student->name]),
            Carbon::today()->toDateString(),
            $body,
            __('app.reports_attendance')
        );
    }
}
