<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;

// Carries HTTP conditional-request state (ETag / Last-Modified) between a fetch
// strategy and the scraper that performs the GET, WITHOUT changing the
// FetchStrategy::fetch(): array contract or any scraper's return shape — it is an
// OPTIONAL out-param a wired scraper accepts and mutates.
//
// Flow (source-agnostic — any single-GET fetch strategy opts in identically):
//   1. for($connection)   → the stored validators, or null when the kill-switch is
//      off (the strategy then fetches unconditionally, exactly as before).
//   2. headers()          → the If-None-Match / If-Modified-Since to send.
//   3. handle($res)       → true on a 304 (sets ->notModified so the strategy raises
//      FetchNotModifiedException); on a 200 captures the response's fresh validators.
//   4. applyTo($connection) → writes the captured validators onto the model (dirty;
//      ScheduledRefresh::run() persists them with the payload on success).
//
// Graceful degradation: no stored validator ⇒ headers() is empty ⇒ a normal 200 ⇒
// validators captured for next time. An upstream that never emits validators ⇒ we
// keep capturing null ⇒ every poll is a full fetch, exactly as today.
//
// Opting in a further single-GET strategy (no spine change needed):
//   1. Give the scraper's GET method an optional `?ConditionalContext $cond = null`
//      last param; merge `$cond?->headers() ?? []` into the request headers; after
//      the fetch, `if ($cond !== null && $cond->handle($res)) return null;` before
//      the existing status/null guard.
//   2. In the strategy: `$cond = ConditionalContext::for($connection);` pass it in;
//      `if ($cond?->notModified) throw new FetchNotModifiedException($platform);`
//      BEFORE the empty/null guard; `$cond?->applyTo($connection);` on success.
// Ready candidates (all route through SafeUrlFetcher::tryFetch), deferred only to
// bound this plan's blast radius — NOT because the upstream is unsuitable:
//   (TwitchFetch and StravaFetch were listed here until 2026-08-16; both were
//    deleted when Phase 1.2 demoted their platforms to link-only, so neither is
//    a candidate for anything now.)
//   • EventbriteFetch / HumanitixFetch — the standalone `kind==='event'` path only
//     (the organiser/account path is multi-URL via fetchMany — NOT a candidate)
//   • YoutubeFetch  — needs channelId cached first (today it resolves handle→id via
//     a prior channel-page GET, making it a 2-call fetch)
// NOT candidates: iTunes (already app-cached, Plan 4), Google Places (billed, raw
// Http::, 6-day gated), the menu (Apify actor — no HTTP validator; #CACHE-1 stays
// Bundle C), and any strategy whose payload needs >1 upstream call.
final class ConditionalContext
{
    /** Set true by handle() on a 304; the strategy raises FetchNotModifiedException. */
    public bool $notModified = false;

    private ?string $newEtag = null;

    private ?string $newLastModified = null;

    private function __construct(
        private readonly ?string $etag,
        private readonly ?string $lastModified,
    ) {}

    /** Null when the conditional-request feature is disabled (master kill-switch). */
    public static function for(IntegrationConnection $connection): ?self
    {
        if (! config('partna.refresh.conditional.enabled')) {
            return null;
        }

        return new self($connection->refresh_etag, $connection->refresh_last_modified);
    }

    /**
     * Conditional request headers for the stored validators (empty when none stored).
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        $headers = [];
        if ($this->etag !== null && $this->etag !== '') {
            $headers['If-None-Match'] = $this->etag;
        }
        if ($this->lastModified !== null && $this->lastModified !== '') {
            $headers['If-Modified-Since'] = $this->lastModified;
        }

        return $headers;
    }

    /**
     * Inspect a SafeUrlFetcher result. Returns true when it was a 304 (the caller
     * stops and keeps the prior payload); on a 200 captures the fresh validators.
     *
     * @param  array{status?:int, etag?:?string, lastModified?:?string}  $res
     */
    public function handle(array $res): bool
    {
        if (($res['status'] ?? null) === 304) {
            $this->notModified = true;

            return true;
        }
        if (($res['status'] ?? null) === 200) {
            $this->newEtag = $res['etag'] ?? null;
            $this->newLastModified = $res['lastModified'] ?? null;
        }

        return false;
    }

    /** Write the freshly-captured validators onto the connection (dirty; saved by ScheduledRefresh). */
    public function applyTo(IntegrationConnection $connection): void
    {
        $connection->refresh_etag = $this->newEtag;
        $connection->refresh_last_modified = $this->newLastModified;
    }
}
