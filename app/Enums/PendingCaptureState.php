<?php

namespace App\Enums;

/**
 * TASK-025 item 2 — backend-side states of a pending capture (the
 * bottle-first half of the recycling flow, spec §3 Case B / §32).
 *
 * The spec's station state machine (IDLE → CARD_IDENTIFIED →
 * WAITING_FOR_CAPTURE → IMAGE_CAPTURED → WAITING_FOR_CARD →
 * READY_FOR_VALIDATION → VALIDATING → ACCEPTED → REJECTED → FAILED)
 * is the DEVICE lifecycle; these are the durable backend states a
 * pending_captures row moves through:
 *
 *   awaiting_card — image stored, no student yet (Case B start)
 *   validating    — card associated, event created, classification in flight
 *   accepted      — classified, points awarded (terminal)
 *   rejected      — card invalid at association (terminal)
 *   failed        — validation crashed after association (terminal)
 *   expired       — no card arrived before expires_at (terminal)
 */
enum PendingCaptureState: string
{
    case AwaitingCard = 'awaiting_card';
    case Validating = 'validating';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';

    /** States that can still transition (everything else is terminal). */
    public function isOpen(): bool
    {
        return $this === self::AwaitingCard || $this === self::Validating;
    }
}
