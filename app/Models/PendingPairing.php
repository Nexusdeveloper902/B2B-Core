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

    /**
     * Active = not consumed, not expired, and not written by a clock
     * the machine no longer has.
     *
     * TASK-020 — the third clause is the clock-jump guard: created_at
     * is stamped by the SAME now() that computed expires_at, so a
     * legitimate row always satisfies created_at <= now (second-floor
     * precision keeps it <=, never after). A row that fails it can
     * only exist if the system clock moved BACKWARD after the row was
     * written (NTP correction, VM resume, dual-boot RTC) — and that is
     * exactly the row that used to resurface as "Armed for Maria —
     * 12468 s left": its far-future expires_at was computed against a
     * clock the machine has since abandoned. Such a row is stale by
     * definition and must not arm anything, countdown anywhere, or
     * accept a pairing tap.
     */
    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->created_at->lte(now());
    }

    /**
     * Scope: unconsumed, unexpired, credibly-dated rows — the
     * pair-lookup set (TASK-020 guard included; see isActive()).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('created_at', '<=', now());
    }
}
