<?php

namespace App\Models;

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
 */
class PresenceEvent extends Model
{
    use HasFactory;

    protected $table = 'events';

    protected $fillable = ['card_id', 'reader_id', 'type', 'occurred_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
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
