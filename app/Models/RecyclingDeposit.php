<?php

namespace App\Models;

use App\Enums\MaterialClass;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecyclingDeposit extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'material_class', 'confidence', 'points_awarded'];

    protected function casts(): array
    {
        return [
            'material_class' => MaterialClass::class,
            'confidence' => 'float',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class, 'event_id');
    }
}
