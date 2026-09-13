<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MealServingService;
use App\Services\Realtime\RealtimeFeed;
use App\Services\Realtime\RealtimeToken;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * TASK-037 — the kitchen meal-service desk (ADR-054).
 *
 * /kitchen is designed for meal-service staff working at speed: the
 * page's primary surface is a huge glanceable accept/reject state driven
 * by realtime tap frames, with a compact recent-taps list behind it for
 * context. No dense dashboards, no student detail navigation, no photos
 * (the platform has none) — recognition over information density.
 *
 * Kitchen users authenticate through the normal login flow but are
 * restricted to this workflow (role walls elsewhere); admins may also
 * open the page. SSR-first: the recent list renders server-side so the
 * page is useful while the realtime server reconnects.
 */
class KitchenController extends Controller
{
    public function __construct(
        private readonly MealServingService $meals,
        private readonly RealtimeFeed $feed,
        private readonly SettingsService $settings,
    ) {}

    public function page(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $now = Carbon::now();

        // Recent meal-related taps only (the kitchen's working set):
        // served meals, flagged attempts, out-of-window attempts.
        $recent = array_values(array_filter(
            $this->feed->recent((int) config('realtime.history_limit')),
            fn (array $row): bool => str_starts_with((string) $row['type'], 'PAE_'),
        ));

        return view('kitchen.dashboard', [
            'recentEvents' => $recent,
            'realtimeToken' => RealtimeToken::issue((int) $user->id),
            'realtimeTokenExpires' => RealtimeToken::freshExpiry(),
            'activeMeal' => $this->meals->activeMeal($now),
            'mealWindows' => $this->settings->mealWindows(),
            'today' => $now->toDateString(),
        ]);
    }
}
