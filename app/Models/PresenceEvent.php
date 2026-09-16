<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Named "PresenceEvent" (not "Event") to avoid clashing with Laravel's
 * event system. Table name is `events` per the platform schema.
 *
 * This table is the single source of truth — the event-type spine. One tap
 * produces one row; attendance, PAE and recycling views are all derived
 * from `type` + `occurred_at` + card/student joins.
 *
 * TASK-037 — the valid-served vs flagged-excluded distinction is explicit:
 * `served` defaults to true; a rejected PAE attempt is still an events row
 * (auditable, feed-visible) but carries served=false plus a machine-stable
 * `reason`. Only served rows contribute to PAE statistics — the `served()`
 * scope is the single choke point every derived query goes through.
 */
class PresenceEvent extends Model
{
    use BelongsToSchool, HasFactory;

    protected $table = 'events';

    protected $fillable = ['card_id', 'reader_id', 'type', 'occurred_at', 'metadata', 'served', 'reason', 'school_id'];

    /**
     * TASK-045 (ADR-064) — an event's organization is its READER's.
     *
     * The device's own identity (its API key / HMAC secret, ADR-002 and
     * ADR-062) is the only trustworthy source here: a tap payload is
     * device-supplied, so nothing in it may decide ownership. This runs
     * in addition to the generic creation inheritance and always wins,
     * which keeps an event and its reader in the same organization even
     * when the row is written from a system-wide context (a seeder).
     */
    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ($event->reader_id === null) {
                return;
            }

            // Read through the wall: a reader in another organization is
            // still the authority on its OWN event (device requests run
            // scoped to that reader, so the scope would be a no-op here —
            // the explicit bypass makes the intent unmissable).
            $schoolId = Reader::withoutGlobalScopes()
                ->whereKey($event->reader_id)
                ->value('school_id');

            $event->school_id = $schoolId !== null ? (int) $schoolId : null;
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
            'served' => 'boolean',
        ];
    }

    /** Only rows that count toward PAE/statistics surfaces. */
    public function scopeServed($query)
    {
        return $query->where('served', true);
    }

    /** Only flagged/excluded rows (rejected PAE attempts). */
    public function scopeFlagged($query)
    {
        return $query->where('served', false);
    }

    /** True PAE meal event types (breakfast/lunch), regardless of served. */
    public function scopeMealTypes($query)
    {
        return $query->whereIn('type', ['PAE_BREAKFAST', 'PAE_LUNCH']);
    }

    public function isMealEvent(): bool
    {
        return $this->type === 'PAE_BREAKFAST' || $this->type === 'PAE_LUNCH' || $this->type === 'PAE_ATTEMPT';
    }

    /** 'breakfast' | 'lunch' | null — the attempted meal, derived from type. */
    public function mealName(): ?string
    {
        return match ($this->type) {
            'PAE_BREAKFAST' => 'breakfast',
            'PAE_LUNCH' => 'lunch',
            default => null,
        };
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    /** The student that produced this event (via the tapped card). */
    public function student()
    {
        return $this->hasOneThrough(Student::class, Card::class, 'id', 'id', 'card_id', 'student_id');
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(RecyclingDeposit::class, 'event_id');
    }
}
