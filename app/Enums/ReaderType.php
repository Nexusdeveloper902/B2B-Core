<?php

namespace App\Enums;

/**
 * Physical reader classifications. TASK-037: `entry` is REMOVED — the
 * entry/exit gate workflow is gone from the platform (supersedes
 * ADR-038); the PAE attendance prerequisite rides CLASS_ATTENDANCE.
 *
 * A `pae` reader is a cafeteria/meal reader: its taps go through the
 * MealServingEngine, which auto-detects breakfast vs lunch from the
 * configured serving windows — the reader's active_event_type is not the
 * meal authority for cafeteria operation (ADR-053).
 */
enum ReaderType: string
{
    case Classroom = 'classroom';
    case Pae = 'pae';
    case Recycling = 'recycling';
}
