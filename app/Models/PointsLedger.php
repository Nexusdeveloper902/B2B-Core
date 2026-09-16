<?php

namespace App\Models;

use App\Models\Concerns\InheritsSchoolFromParent;
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
    use InheritsSchoolFromParent;

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

    /**
     * TASK-045 (ADR-064) — organization ownership is INHERITED:
     * this row belongs to whatever its student belongs to.
     *
     * @return array{0: string, 1: string}
     */
    protected static function schoolOwnershipPath(): array
    {
        return ['student_id', 'students'];
    }
}
