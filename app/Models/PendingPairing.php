<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-010 — a short-lived arming record that pairs the next fresh card
 * scan (device side) to a student (dashboard side). See PairingService
 * and ADR-020 for the two-step arm-then-pair design.
 */
class PendingPairing extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'reader_id', 'card_id', 'expires_at', 'consumed_at',
        'last_rejected_uid', 'last_rejected_reason', 'last_rejected_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_rejected_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    /** TASK-011 — the exact cards row this pairing created (audit trail). */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /** Active = not consumed and not expired. */
    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture();
    }

    /** Scope: unconsumed, unexpired rows — the pair-lookup set. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
