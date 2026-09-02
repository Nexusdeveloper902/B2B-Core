<?php

namespace App\Models;

use App\Enums\ReaderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical (or simulated) reader. "A reader" is anything that can make an
 * authenticated HTTP POST with its static Bearer API key — a Postman
 * request today, an ESP32 later. The api_key IS the reader's identity;
 * callers never supply a reader ID as truth.
 */
class Reader extends Model
{
    use HasFactory;

    protected $fillable = ['label', 'type', 'active_event_type', 'api_key'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'type' => ReaderType::class,
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }

    public function isRecycling(): bool
    {
        return $this->type === ReaderType::Recycling;
    }
}
