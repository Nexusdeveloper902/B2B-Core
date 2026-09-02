<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use Illuminate\Support\Carbon;

/**
 * Dashboard derivations (Phase F): attendance / PAE / recycling views are
 * all computed from the same events table — never stored separately.
 *
 * Also backs the NL-query callable functions (Phase E): every number the
 * LLM can report comes through these same methods, so the LLM can never
 * fabricate data that disagrees with the dashboards.
 */
class AttendanceService
{
    /**
     * Today's CLASS_ATTENDANCE status for one class: present / late / absent.
     *
     * @return array<int, array{
     *   student: Student, status: 'present'|'late'|'absent', tapped_at: ?string
     * }>
     */
    public function classAttendanceToday(int $classId): array
    {
        $cutoff = (string) config('presence.late_cutoff');
        $today = now()->toDateString();

        $students = Student::where('class_id', $classId)
            ->with('cards')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($students as $student) {
            $event = PresenceEvent::whereIn('card_id', $student->cards->pluck('id'))
                ->where('type', 'CLASS_ATTENDANCE')
                ->whereDate('occurred_at', $today)
                ->orderBy('occurred_at')
                ->first();

            $status = 'absent';
            $tappedAt = null;

            if ($event !== null) {
                $tappedAt = $event->occurred_at->format('H:i');
                $status = $event->occurred_at->format('H:i') > $cutoff ? 'late' : 'present';
            }

            $rows[] = compact('student', 'status', 'tappedAt');
        }

        return $rows;
    }

    /**
     * Distinct students with a CLASS_ATTENDANCE event on the given date
     * (Y-m-d), optionally scoped to one class.
     */
    public function attendanceCount(string $date, ?int $classId = null): int
    {
        return $this->studentCountForEvent('CLASS_ATTENDANCE', $date, $classId);
    }

    /** Distinct students with a PAE meal event ('breakfast'|'lunch') on the given date. */
    public function paeCount(string $meal, string $date): int
    {
        $type = $meal === 'breakfast' ? 'PAE_BREAKFAST' : 'PAE_LUNCH';

        return $this->studentCountForEvent($type, $date);
    }

    private function studentCountForEvent(string $type, string $date, ?int $classId = null): int
    {
        $query = PresenceEvent::query()
            ->where('events.type', $type)
            ->whereDate('occurred_at', $date)
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id');

        if ($classId !== null) {
            $query->where('students.class_id', $classId);
        }

        return $query->distinct()->count('students.id');
    }

    /**
     * Recycling totals for an inclusive date range (Y-m-d to Y-m-d).
     *
     * @return array{items: int, points: int, by_material: array<string, int>}
     */
    public function recyclingTotals(string $from, string $to): array
    {
        $rows = RecyclingDeposit::whereHas('event', function ($q) use ($from, $to) {
            $q->whereBetween('occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]);
        })->get();

        $byMaterial = [];
        foreach (MaterialClass::cases() as $material) {
            $byMaterial[$material->value] = $rows->where('material_class', $material->value)->count();
        }

        return [
            'items' => $rows->count(),
            'points' => (int) $rows->sum('points_awarded'),
            'by_material' => $byMaterial,
        ];
    }

    /**
     * Chronological timeline of all events for one student (parent view +
     * NL query function). PresenceEvent rows joined with readable context.
     *
     * @return array<int, array{
     *   event_id: int, type: string, occurred_at: string, reader: string, material: ?string, points: ?int
     * }>
     */
    public function studentTimeline(Student $student): array
    {
        return PresenceEvent::whereIn('card_id', $student->cards()->pluck('id'))
            ->with(['reader', 'deposit'])
            ->orderBy('occurred_at')
            ->get()
            ->map(function (PresenceEvent $event) {
                return [
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'reader' => $event->reader?->label,
                    'material' => $event->deposit?->material_class?->value,
                    'points' => $event->deposit?->points_awarded,
                ];
            })
            ->all();
    }
}
