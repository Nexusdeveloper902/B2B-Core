<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-049 (ADR-068) — the per-credential HMAC key of one HCE phone.
 *
 * `secret` is the 32-byte key as hex, encrypted at rest (APP_KEY) and
 * hidden from every serialization. It is only ever read by
 * HceCredentialAuth to verify a CHALLENGE proof.
 *
 * No school scope of its own: rows are only reached through an already
 * school-scoped Card (`$card->hceKey`), never by id from a request.
 */
class HceCredentialKey extends Model
{
    protected $fillable = ['card_id', 'secret', 'fingerprint', 'provisioned_by_reader_id', 'provisioned_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'provisioned_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
