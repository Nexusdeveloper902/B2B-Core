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
     * material rate table, and the recycling readers. No new endpoints,
     * no writes. Mockup parts with no backing data are omitted and
     * documented in docs/FRONTEND.md: terminal telemetry, hardware
     * firmware status, compliance box, interactive terminal simulation,
     * cryptographic footers, capture image display (the images are
     * private-disk audit artifacts — showing them needs an authorized
     * image route; gap #E1).
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
        ]);
    }
}
