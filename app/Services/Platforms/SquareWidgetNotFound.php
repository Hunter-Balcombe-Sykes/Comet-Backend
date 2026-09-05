<?php

namespace App\Services\Platforms;

use RuntimeException;

/**
 * Square's own buyer-widget endpoint answered 404 for this merchant/unit —
 * a distinct failure from a transient network/5xx blip: the saved booking
 * page itself is gone, disabled, or was never a real Appointments link
 * (2026-09-05 production incident, issue #519 — merchant token resolved
 * from a stored URL, Square 404s it). Retrying will not help; the caller
 * needs to fix the saved link, not try again.
 */
final class SquareWidgetNotFound extends RuntimeException {}
