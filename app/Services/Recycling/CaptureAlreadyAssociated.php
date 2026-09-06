<?php

namespace App\Services\Recycling;

/**
 * TASK-025 item 2 — thrown (and caught) inside CaptureService when a
 * concurrent association claimed a pending capture between the outer
 * usability pre-check and the transaction's lockForUpdate re-check.
 * The loser surfaces a friendly already_associated answer, never a
 * race-condition 500.
 */
class CaptureAlreadyAssociated extends \RuntimeException {}
