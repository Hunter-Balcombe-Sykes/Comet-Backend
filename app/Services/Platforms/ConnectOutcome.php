<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;

/**
 * Widened return type for ConnectResolver::resolve() (Unit 11 W6, Phase 2 of
 * docs/superpowers/plans/2026-07-20-platform-connect-async.md §2c/§7). Carries
 * a plain ConnectResult PLUS the branch the resolver took, so a call site is
 * forced to consult $deferred before treating $result as a completed write —
 * deliberately NOT a `ConnectResult::pending()` variant (that was rejected in
 * W4: ConnectResult::failed() is `selection === null`, so a pending result
 * would report failed() === false and a forgotten call site would silently
 * write it through as 'ok'). Wrapping in a distinct top-level type makes that
 * mistake impossible to make silently — GenericPlatformController::connect()
 * is the only call site and must branch on ->deferred explicitly.
 *
 * $result->failed() means the same thing on both branches (identify() and
 * resolve() share the exact same parse-fail shape — see
 * DeferredConnectParityTest) — check it FIRST, regardless of $deferred, before
 * treating a deferred outcome as a partial success.
 */
final readonly class ConnectOutcome
{
    private function __construct(
        public ConnectResult $result,
        public bool $deferred,
    ) {}

    /** The synchronous path — today's byte-identical resolve() call. */
    public static function complete(ConnectResult $result): self
    {
        return new self($result, false);
    }

    /** The async path — result.selection is a PARTIAL payload from identify(), not yet written. */
    public static function pending(ConnectResult $result): self
    {
        return new self($result, true);
    }
}
