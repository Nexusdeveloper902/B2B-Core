<?php

namespace App\Services;

use App\Models\RecyclingDeposit;
use App\Models\RewardRedemption;
use App\Models\Student;
use App\Services\Recycling\LeaderboardService;
use Illuminate\Support\Carbon;

/**
 * The recycling reporting data layer: daily/monthly yields, per-day
 * trends, the material mix, the points leaderboard and per-student
 * histories. ONE truth behind the /admin/reports/recycling pages and
 * their CSV/PDF exports — the same events-spine derivations the
 * EcoStation hub reads, so a report can never disagree with it.
 *
 * Everything derives from classified deposits (never raw taps): an
 * unclassified tap has awarded no points and moved no material total.
 */
class RecyclingReportService
{
    public function __construct(
        private readonly LeaderboardService $board,
    ) {}

    /**
     * One day's yield: items + points + the material mix.
     *
     * @return array{date: string, items: int, points: int, by_material: array<string, array{items: int, points: int}>}
     */
    public function daily(string $date): array
    {
        return [
            'date' => $date,
            'items' => $this->itemsOn($date),
            'points' => $this->pointsOn($date),
            'by_material' => $this->byMaterial($date, $date),
        ];
    }

    /**
     * Monthly summary: per-day trend + totals.
     *
     * @return array{month: string, days: array<int, array{date: string, items: int, points: int}>, totals: array{items: int, points: int}}
     */
    public function monthly(string $month): array
    {
        $stamp = Carbon::parse($month.'-01');
        $from = $stamp->copy()->startOfMonth()->toDateString();
        $to = $stamp->copy()->endOfMonth()->toDateString();

        $days = $this->trend($from, $to);

        return [
            'month' => $stamp->format('Y-m'),
            'days' => $days,
            'totals' => [
                'items' => array_sum(array_column($days, 'items')),
                'points' => array_sum(array_column($days, 'points')),
            ],
        ];
    }

    /**
     * Per-day items + points over an inclusive range (zero-filled).
     *
     * @return array<int, array{date: string, items: int, points: int}>
     */
    public function trend(string $from, string $to): array
    {
        $rows = RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q->whereBetween('occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]))
            ->join('events', 'events.id', '=', 'recycling_deposits.event_id')
            ->selectRaw('date(events.occurred_at) as day, count(*) as items, coalesce(sum(recycling_deposits.points_awarded), 0) as points')
            ->groupByRaw('date(events.occurred_at)')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->day] = ['items' => (int) $row->items, 'points' => (int) $row->points];
        }

        $trend = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cursor <= $end) {
            $day = $cursor->toDateString();
            $trend[] = [
                'date' => $day,
                'items' => $byDay[$day]['items'] ?? 0,
                'points' => $byDay[$day]['points'] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $trend;
    }

    /**
     * The material mix over an inclusive range: every classified
     * material, even at zero, so the table is an honest comparison.
     *
     * @return array<string, array{items: int, points: int}>
     */
    public function byMaterial(string $from, string $to): array
    {
        $mix = [];
        foreach ((array) config('recycling.points', []) as $material => $rate) {
            $mix[(string) $material] = ['items' => 0, 'points' => 0, 'rate' => (int) $rate];
        }

        $rows = RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q->whereBetween('occurred_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ]))
            ->selectRaw('material_class, count(*) as items, coalesce(sum(points_awarded), 0) as points')
            ->groupBy('material_class')
            ->get();

        foreach ($rows as $row) {
            $material = $row->material_class->value;
            $mix[$material] = [
                'items' => (int) $row->items,
                'points' => (int) $row->points,
                'rate' => (int) config("recycling.points.{$material}", 0),
            ];
        }

        return $mix;
    }

    /**
     * The points leaderboard, straight from the ledger (never a cached
     * counter that could drift).
     *
     * @return array<int, array{rank: int, student_id: int, student_name: string, class_name: string|null, points: int}>
     */
    public function leaderboard(int $limit = 10): array
    {
        return $this->board->top(max(1, min(50, $limit)));
    }

    /**
     * One student's recycling history: per-deposit rows (material,
     * points, reader, time), newest first, plus earn/spend totals.
     *
     * @return array{student: array{id: int, name: string, class_name: ?string}, deposits: array<int, array{date: string, time: string, material: string, points: int, reader: ?string}>, totals: array{items: int, points: int, redeemed: int, redemptions: int}}
     */
    public function studentHistory(Student $student, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $from = Carbon::today()->subDays($days - 1)->startOfDay();
        $cardIds = $student->cards()->pluck('id');

        $deposits = RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q
                ->whereIn('card_id', $cardIds->isEmpty() ? [0] : $cardIds->all())
                ->where('occurred_at', '>=', $from))
            ->join('events', 'events.id', '=', 'recycling_deposits.event_id')
            ->leftJoin('readers', 'readers.id', '=', 'events.reader_id')
            ->orderByDesc('events.occurred_at')
            ->get([
                'recycling_deposits.material_class',
                'recycling_deposits.points_awarded',
                'events.occurred_at',
                'readers.label as reader_label',
            ]);

        $rows = $deposits->map(fn ($row) => [
            'date' => Carbon::parse($row->occurred_at)->toDateString(),
            'time' => Carbon::parse($row->occurred_at)->format('H:i'),
            'material' => $row->material_class->value,
            'points' => (int) $row->points_awarded,
            'reader' => $row->reader_label !== null ? (string) $row->reader_label : null,
        ])->all();

        $redemptions = RewardRedemption::query()
            ->where('student_id', $student->id)
            ->where('created_at', '>=', $from)
            ->get(['points_spent']);

        return [
            'student' => [
                'id' => (int) $student->id,
                'name' => (string) $student->name,
                'class_name' => $student->schoolClass?->name,
            ],
            'deposits' => $rows,
            'totals' => [
                'items' => count($rows),
                'points' => array_sum(array_column($rows, 'points')),
                'redeemed' => (int) $redemptions->sum('points_spent'),
                'redemptions' => $redemptions->count(),
            ],
        ];
    }

    private function itemsOn(string $date): int
    {
        return RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q->whereDate('occurred_at', $date))
            ->count();
    }

    private function pointsOn(string $date): int
    {
        return (int) RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q->whereDate('occurred_at', $date))
            ->sum('points_awarded');
    }
}
