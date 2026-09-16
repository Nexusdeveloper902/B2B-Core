<?php

namespace App\Models;

use App\Enums\CardKind;
use App\Enums\CardStatus;
use App\Models\Concerns\InheritsSchoolFromParent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    use HasFactory;
    use InheritsSchoolFromParent;

    protected $fillable = ['credential_uid', 'kind', 'student_id', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => CardKind::class,
            'status' => CardStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === CardStatus::Active;
    }

    /**
     * TASK-045 (ADR-064) — organization ownership is INHERITED:
     * this row belongs to whatever its student belongs to.
     *
     * @return array{0: string, 1: string}
     */
    protected static function schoolOwnershipPath(): array
    {
        return ['student_id', 'students'];
    }
}
