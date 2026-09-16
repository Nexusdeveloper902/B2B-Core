<?php

namespace App\Models;

use App\Enums\MaterialClass;
use App\Models\Concerns\InheritsSchoolFromParent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecyclingDeposit extends Model
{
    use HasFactory;
    use InheritsSchoolFromParent;

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

    /**
     * TASK-045 (ADR-064) — organization ownership is INHERITED:
     * this row belongs to whatever its event belongs to.
     *
     * @return array{0: string, 1: string}
     */
    protected static function schoolOwnershipPath(): array
    {
        return ['event_id', 'events'];
    }
}
