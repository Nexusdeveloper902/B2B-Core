<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger of every point earned and spent. The current balance
 * is always the SUM of deltas — never stored as a mutable counter, so the
 * full earn/spend history remains auditable.
 */
class PointsLedger extends Model
{
    use HasFactory;

    protected $table = 'points_ledger';

    protected $fillable = ['student_id', 'delta', 'reason', 'event_id', 'reward_id'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }
}
