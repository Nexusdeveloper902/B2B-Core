<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TASK-037 — one runtime-configurable setting row (ADR-055).
 *
 * `key` is a dotted string ('pae.breakfast_start'); `value` is stored as
 * JSON so scalars, ints and strings all round-trip one way. Reads and
 * writes go through SettingsService (which owns defaults + validation +
 * the per-request cache) — never query this model directly for runtime
 * values; direct queries bypass the fallback chain and the cache.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
