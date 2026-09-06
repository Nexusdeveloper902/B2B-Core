<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-025 item 6 — one append-only broadcast row per recycling event
 * the realtime feed should push (capture created, validation started,
 * validated, points awarded, reward redeemed, leaderboard updated).
 *
 * Rows are written inside the SAME transaction as the state change they
 * describe, so realtime:serve only ever polls committed truth. Payloads
 * are precomputed by the writer; this model is the Eloquent read side.
 */
class RecyclingUpdate extends Model
{
    use HasFactory;

    public const TYPE_CAPTURE_CREATED = 'capture_created';

    public const TYPE_VALIDATION_STARTED = 'validation_started';

    public const TYPE_VALIDATED = 'validated';

    public const TYPE_POINTS_AWARDED = 'points_awarded';

    public const TYPE_REWARD_REDEEMED = 'reward_redeemed';

    public const TYPE_LEADERBOARD_UPDATED = 'leaderboard_updated';

    protected $fillable = ['type', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
