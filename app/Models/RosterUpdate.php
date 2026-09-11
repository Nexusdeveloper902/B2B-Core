<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-029 — one append-only broadcast row per roster change the
 * realtime feed should push (student created, students imported,
 * class created, reader updated).
 *
 * TASK-030-B — reader_created joins the channel: provisioning a reader
 * announces the birth the same way (the WS server polls rows, not
 * types, so zero wire changes were needed).
 *
 * Rows are written inside the SAME transaction as the state change
 * they describe, so realtime:serve only ever polls committed truth.
 * Payloads are precomputed by the writer; this model is the Eloquent
 * read side. Frames are admin-only on the wire (roster management is
 * an admin surface — the same data the admin REST endpoints serve).
 */
class RosterUpdate extends Model
{
    use HasFactory;

    public const TYPE_STUDENT_CREATED = 'student_created';

    public const TYPE_STUDENTS_IMPORTED = 'students_imported';

    public const TYPE_CLASS_CREATED = 'class_created';

    public const TYPE_READER_UPDATED = 'reader_updated';

    public const TYPE_READER_CREATED = 'reader_created';

    protected $fillable = ['type', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
