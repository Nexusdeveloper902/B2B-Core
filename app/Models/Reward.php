<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    use HasFactory;

    /**
     * TASK-025 item 7 — the catalog columns (spec §19/§20):
     * type (informational), value (optional), active (soft switch),
     * stock (NULL = unlimited; else remaining units).
     */
    protected $fillable = ['name', 'point_cost', 'description', 'type', 'value', 'active', 'stock'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'active' => 'boolean',
            'stock' => 'integer',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** NULL stock = unlimited; otherwise the remaining units. */
    public function stockRemaining(): ?int
    {
        return $this->stock;
    }
}
