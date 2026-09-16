<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = ['name', 'grade', 'pae_breakfast_enrolled', 'pae_lunch_enrolled', 'class_id', 'school_id'];

    protected function casts(): array
    {
        return [
            'pae_breakfast_enrolled' => 'boolean',
            'pae_lunch_enrolled' => 'boolean',
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

    /** TASK-025 item 7 — this student's reward redemptions (spend history). */
    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    /** TASK-025 item 5 — the 1:1 self-service account referencing this row. */
    public function account(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Current balance: sum of ledger deltas. */
    public function pointBalance(): int
    {
        return (int) $this->pointsLedger()->sum('delta');
    }

    /**
     * TASK-037 — per-meal PAE enrollment. Breakfast and lunch are fully
     * independent: either flag may be set without the other.
     */
    public function enrolledForMeal(string $meal): bool
    {
        return $meal === 'breakfast' ? $this->pae_breakfast_enrolled : $this->pae_lunch_enrolled;
    }

    /** First word of the name — used for device feedback displays. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }
}
