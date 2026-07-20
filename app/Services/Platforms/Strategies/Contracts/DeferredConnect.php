<?php

namespace App\Services\Platforms\Strategies\Contracts;

// SEAM INTERFACE — a connect strategy whose vendor CONTENT fetch can be split off
// from its input VALIDATION, so a future controller path can write a partial row
// and return 202 while a queued job fills the payload (Phase 2 of
// docs/superpowers/plans/2026-07-20-platform-connect-async.md). NOT wired into
// any production call site yet — identify() exists but nothing dispatches a job
// or returns 202 in this unit. Matches the ApiKeyConnect/OAuthConnect seam-interface
// convention in this directory.
//
// The split point is NOT arbitrary. identify() must return the exact identity
// key(s) the platform's registered FetchStrategy reads out of the stored payload
// (YoutubeFetch -> 'handle', VimeoFetch -> 'apiPath', OEmbedFetch -> 'link' ?? 'url',
// ...), because THAT strategy — not new code — is what the eventual connect job
// runs. A selection returned here is a partial payload: valid to store, incomplete
// to render.
//
// identify() MUST be cheap: no network, no vendor call, no blocking I/O. The one
// deliberate exception is YoutubeMusicConnect, whose @handle -> channelId
// resolution is a single fetch retained inline so its frozen 422 contract is
// preserved exactly; it is bounded by ConnectResolver's wall-clock budget (Phase 1).
// Any future implementer that wants to make a network call in identify() must
// justify it the same way.
//
// resolve() is INTENTIONALLY still required (inherited from ConnectStrategy) and
// is NOT reimplemented in terms of identify(): it remains the byte-identical
// synchronous path used today and pinned by
// tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php. The
// small duplication of parse logic between the two is deliberate and guarded by
// tests/Feature/Platforms/Strategies/DeferredConnectParityTest.php.
interface DeferredConnect extends ConnectStrategy
{
    /**
     * Cheap syntactic validation only. On success returns ConnectResult::ok()
     * whose selection is the PARTIAL payload — the FetchStrategy's identity key
     * plus any value derivable with zero network (e.g. Spotify's deterministic
     * embedUrl). On failure returns ConnectResult::fail() with the SAME message
     * and status the platform's resolve() would have returned for the same input,
     * so the inline 422 contract is unchanged.
     */
    public function identify(string $input): ConnectResult;
}
