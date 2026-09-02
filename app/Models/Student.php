<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'grade', 'pae_enrolled', 'class_id'];

    protected function casts(): array
    {
        return [
            'pae_enrolled' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function pointsLedger(): HasMany
    {
        return $this->hasMany(PointsLedger::class);
    }

    /** Current balance: sum of ledger deltas. */
    public function pointBalance(): int
    {
        return (int) $this->pointsLedger()->sum('delta');
    }

    public function scopePaeEnrolled(Builder $query): Builder
    {
        return $query->where('pae_enrolled', true);
    }

    /** First word of the name — used for device feedback displays. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }
}
