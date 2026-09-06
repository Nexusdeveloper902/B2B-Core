<?php

namespace App\Models;

use App\Enums\PendingCaptureState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-025 item 2 — a captured image waiting for (or resolving through)
 * a card association. See the migration doc and PendingCaptureState for
 * the flow; the shape deliberately mirrors PendingPairing (expiry +
 * clock-jump guard).
 */
class PendingCapture extends Model
{
    use HasFactory;

    protected $fillable = ['reader_id', 'image_path', 'event_id', 'card_id', 'state', 'expires_at'];

    protected function casts(): array
    {
        return [
            'state' => PendingCaptureState::class,
            'expires_at' => 'datetime',
        ];
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    /** The tap event created when the association resolved this capture. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class);
    }

    /** The card whose tap resolved this capture (audit trail). */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * Usable = awaiting a card, not expired, and not written by a clock
     * the machine no longer has (the PendingPairing TASK-020 clock-jump
     * guard, same rationale: a far-future expires_at computed against an
     * abandoned clock must never keep a capture resolvable).
     */
    public function isUsable(): bool
    {
        return $this->state === PendingCaptureState::AwaitingCard
            && $this->expires_at->isFuture()
            && $this->created_at->lte(now());
    }

    /** Scope: resolvable captures (the association lookup set). */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('state', PendingCaptureState::AwaitingCard->value)
            ->where('expires_at', '>', now())
            ->where('created_at', '<=', now());
    }
}
