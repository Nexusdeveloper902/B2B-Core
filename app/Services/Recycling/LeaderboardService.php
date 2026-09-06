<?php

namespace App\Services\Recycling;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * TASK-025 item 4 — the leaderboard (spec §22/§28).
 *
 * Ranking derives EXCLUSIVELY from the real points ledger (SUM(delta)
 * per student — students with no movements rank at zero) — never from
 * a denormalized counter that could drift. Tie-breaking is
 * deterministic: equal points share a rank in competition order
 * (1, 2, 2, 4) and ties order by student id, so the same data always
 * renders the same board on every client.
 */
class LeaderboardService
{
    /**
     * The top-N entries, rank-stamped.
     *
     * @return array<int, array{rank: int, student_id: int, student_name: string, class_name: string|null, points: int}>
     */
    public function top(int $limit = 10): array
    {
        $rows = $this->scored()
            ->limit($limit)
            ->get();

        return $this->stampRanks($rows);
    }

    /**
     * A student's own standing (rank + points), competition-ranked.
     *
     * @return array{rank: int|null, points: int}
     */
    public function rankOf(Student $student): array
    {
        $rows = $this->scored()->get();

        $points = null;
        $rank = null;

        foreach ($this->stampRanks($rows) as $entry) {
            if ($entry['student_id'] === $student->id) {
                $rank = $entry['rank'];
                $points = $entry['points'];

                break;
            }
        }

        return ['rank' => $rank, 'points' => $points ?? 0];
    }

    /**
     * A small top-N snapshot for the leaderboard_updated frame payload
     * (computed inside the award/redeem transaction — deliberately
     * cheap; school-scale data).
     *
     * @return array<int, array{rank: int, student_id: int, student_name: string, points: int}>
     */
    public function snapshot(int $limit = 3): array
    {
        return array_map(
            fn (array $entry) => [
                'rank' => $entry['rank'],
                'student_id' => $entry['student_id'],
                'student_name' => $entry['student_name'],
                'points' => $entry['points'],
            ],
            $this->top($limit),
        );
    }

    /**
     * The ordered, point-scored board base: EVERY student appears (zero
     * movements = zero points — a new student still gets a rank), points
     * DESC, student_id ASC (the documented deterministic tie-break).
     */
    private function scored()
    {
        return DB::table('students')
            ->leftJoin('points_ledger', 'points_ledger.student_id', '=', 'students.id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->groupBy('students.id', 'students.name', 'classes.name')
            ->orderByDesc(DB::raw('COALESCE(SUM(points_ledger.delta), 0)'))
            ->orderBy('students.id')
            ->select([
                'students.id as student_id',
                'students.name as student_name',
                'classes.name as class_name',
                DB::raw('CAST(COALESCE(SUM(points_ledger.delta), 0) AS INTEGER) as points'),
            ]);
    }

    /**
     * Competition ranking (1, 2, 2, 4): equal points share the better
     * place; the next distinct value skips ahead by the tie size.
     *
     * @param  iterable<object{student_id: int, student_name: string, class_name: string|null, points: int}>  $rows
     * @return array<int, array{rank: int, student_id: int, student_name: string, class_name: string|null, points: int}>
     */
    private function stampRanks(iterable $rows): array
    {
        $entries = [];
        $rank = 0;
        $previousPoints = null;

        foreach ($rows as $index => $row) {
            $points = (int) $row->points;

            if ($points !== $previousPoints) {
                $rank = $index + 1;
                $previousPoints = $points;
            }

            $entries[] = [
                'rank' => $rank,
                'student_id' => (int) $row->student_id,
                'student_name' => (string) $row->student_name,
                'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
                'points' => $points,
            ];
        }

        return $entries;
    }
}
