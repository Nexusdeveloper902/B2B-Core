<?php

namespace App\Enums;

/**
 * HOW a credential was captured — not WHO it belongs to (that is the
 * student_id link) and not WHETHER it works (that is CardStatus).
 *
 * - Physical: an RF-layer UID read off a MIFARE Classic card by the RC522.
 * - Hce: an application-level credential id obtained from an Android
 *   phone through the Pulse HCE APDU protocol (SELECT AID F0010203040506
 *   + CHALLENGE). The phone's RF UID is randomized per tap by Android
 *   and is NEVER stored or used as identity.
 */
enum CardKind: string
{
    case Physical = 'physical';
    case Hce = 'hce';
}
