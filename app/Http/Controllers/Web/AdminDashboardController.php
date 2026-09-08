<?php

namespace App\Http\Controllers\Web;

use App\Enums\ReaderType;
use App\Http\Controllers\Controller;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\Reward;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimeToken;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly RealtimeFeed $realtimeFeed,
    ) {}

    public function dashboard(): View
    {
        $today = now()->toDateString();

        $recyclingToday = $this->attendance->recyclingTotals($today, $today);

        return view('admin.dashboard', [
            'attendanceToday' => $this->attendance->attendanceCount($today),
            'paeBreakfastToday' => $this->attendance->paeCount('breakfast', $today),
            'paeLunchToday' => $this->attendance->paeCount('lunch', $today),
            'recyclingToday' => $recyclingToday,
            'readers' => Reader::orderBy('label')->get(),
            'students' => Student::orderBy('name')->with('schoolClass')->get(),
            'rewards' => Reward::orderBy('point_cost')->get(),
            'nlQueryConfigured' => ! empty(config('recycling.nl_query.api_key')),
            'recentEvents' => $this->realtimeFeed->recent((int) config('realtime.history_limit')),
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }

    /**
     * TASK-026 — the EcoStation hub (mockup "EcoStation & Recycling
     * Hub"). READ-ONLY view over existing data: the deposit ledger (with
     * per-event reader + student via the event spine), the config-driven
     * material rate table, and the recycling readers.
     *
     * TASK-027 — the hub is LIVE now: the page boots the realtime client
     * (recycling frames prepend ledger rows and bump the metrics without
     * a reload), and the latest capture displays the REAL image through
     * the admin-authed streaming route (gap #E1 closed).
     */
    public function ecostation(): View
    {
        $recentDeposits = RecyclingDeposit::query()
            ->with(['event.reader', 'event.card.student'])
            ->latest('id')
            ->limit(15)
            ->get();

        $byMaterial = RecyclingDeposit::query()
            ->selectRaw('material_class, count(*) as items, coalesce(sum(points_awarded), 0) as points')
            ->groupBy('material_class')
            ->orderByDesc('items')
            ->get();

        return view('admin.ecostation', [
            'totalItems' => (int) RecyclingDeposit::query()->count(),
            'totalPoints' => (int) RecyclingDeposit::query()->sum('points_awarded'),
            'byMaterial' => $byMaterial,
            'rates' => (array) config('recycling.points'),
            'readers' => Reader::query()
                ->where('type', ReaderType::Recycling)
                ->orderBy('label')
                ->get(),
            'recentDeposits' => $recentDeposits,
            'latestCapture' => RecyclingDeposit::query()
                ->whereNotNull('image_path')
                ->latest('id')
                ->with(['event.reader', 'event.card.student'])
                ->first(),
            'realtimeToken' => RealtimeToken::issue((int) auth()->id()),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
        ]);
    }
}
