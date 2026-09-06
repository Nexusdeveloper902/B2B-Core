<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-025 item 7 — a first-class record of every successful reward
 * redemption (spec §20): one row per spend, structurally 1:1 with its
 * points_ledger movement (ledger_id UNIQUE). Stock accounting and the
 * duplicate-window double-submit guard both read this table.
 */
class RewardRedemption extends Model
{
    use HasFactory;

    protected $fillable = ['reward_id', 'student_id', 'ledger_id', 'points_spent', 'request_id'];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(PointsLedger::class, 'ledger_id');
    }
}
