<?php

namespace App\Services\Platforms\Strategies\Fetch;

// The upstream fetch returned nothing usable (empty scrape, failed API/oEmbed call).
// Mirrors PlatformRefresher's status='unavailable' bucket — recorded quietly, the
// last-known-good payload preserved (no edge-cache purge).
//
// $message stays the MACHINE code: PlatformRefresher::refresh() records
// $e->getMessage() verbatim in last_refresh_error on the cron path, and nothing
// here changes that. $userMessage is the optional user-facing override
// ConnectFetchJob prefers over the descriptor's vendor-miss copy — for failures
// that never actually asked the vendor (a staff disable, a booking-XOR conflict,
// a mid-flight disconnect, a lock timeout), where "we couldn't read that page"
// would describe a request we never made.
class FetchUnavailableException extends \RuntimeException
{
    // The SAME non-vendor wording ConnectFetchJob already shows for its own
    // staff-disable branch — reused, never re-worded, so no new user-visible
    // copy enters the connect contract.
    public const GENERIC_USER_MESSAGE = 'We could not load that account. Please try again.';

    // Shared with DefersBespokeConnect::bespokeConnectStatus() (the stale-pending
    // poll branch) and ConnectFetchJob's own lock-timeout catch — one wire copy,
    // not a second hand-typed copy of it. Lives here, not on either caller, since
    // PHP cannot reference a trait constant externally and a Job depending on a
    // Controller for a string is a layering inversion; this class is already the
    // neutral home for GENERIC_USER_MESSAGE for the identical reason.
    public const STALE_CONNECT_ERROR = "We couldn't save your connection just then — please try again.";

    public function __construct(string $message = '', private readonly ?string $userMessage = null)
    {
        parent::__construct($message);
    }

    /** Null for a genuine vendor miss — the caller then falls back to the platform's own copy. */
    public function userMessage(): ?string
    {
        return $this->userMessage;
    }
}
