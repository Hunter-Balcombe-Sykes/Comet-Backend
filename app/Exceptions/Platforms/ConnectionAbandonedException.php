<?php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported exactly once per abandoned SYSTEM-initiated connection: the
// SYSTEM_RETRY_DELAYS chain (ConnectFetchJob::markTerminal, T2 2026-08-27)
// ran out for a first-fetch vendor miss on a connection nobody is watching a
// modal for, and F26 is about to hard-delete the never-fetched row with no
// health-state row left to carry the failure (the refresh lane's
// consecutive_failures + PlatformHealthNotifier never got a chance to see
// this connection). Unthrottled and unconditional by design — it fires once,
// terminally, and is naturally rate-limited by the retry chain itself.
class ConnectionAbandonedException extends RuntimeException
{
    public function __construct(
        public readonly string $platform,
        public readonly string $connectionId,
        public readonly int $attempts,
    ) {
        parent::__construct(
            "system-initiated {$platform} connection {$connectionId} abandoned after {$attempts} failed attempts"
        );
    }
}
