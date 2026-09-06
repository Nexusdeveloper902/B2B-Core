<?php

namespace App\Models;

use App\Enums\MaterialClass;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecyclingDeposit extends Model
{
    use HasFactory;

    /**
     * TASK-025 items 3 + 8: image_path (persisted capture — audit trail,
     * spec §14) and the AI boundary booleans (spec §9). Points still
     * derive ONLY from material_class + config (backend owns rules).
     */
    protected $fillable = ['event_id', 'image_path', 'material_class', 'confidence', 'points_awarded', 'is_bottle', 'is_recyclable'];

    protected function casts(): array
    {
        return [
            'material_class' => MaterialClass::class,
            'confidence' => 'float',
            'is_bottle' => 'boolean',
            'is_recyclable' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class, 'event_id');
    }
}
