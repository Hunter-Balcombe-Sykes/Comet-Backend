<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;

/**
 * L1 — the STRUCTURAL half of "is this link actually this account's page?".
 *
 * The question this answers is narrow and cheap: did the URL match a rule that
 * constrains more than the brand's domain? It costs no network call and it is
 * a pure function of the compiled catalog, which is what lets it run on every
 * write rather than only where someone remembered to call it.
 *
 * L2 — "does that page actually exist and belong to them" — is the other half
 * and is NOT here; it needs the network and therefore a queue.
 *
 * ── Why this shape, and not a host+path grammar ──────────────────────────────
 *
 * The obvious definition ("the detector declares a path pattern") is WRONG and
 * would refuse every valid OpenTable ccTLD link: 32 of OpenTable's 48 detectors
 * carry an EMPTY path_pattern with `query_requires: ['restRef']` and
 * `identifier_source: 'query'`. `opentable.com.au/?restRef=291533` identifies a
 * restaurant exactly as precisely as `/restaurant/profile/291533` does, and
 * Opentable.php declares both at the same evidence strength on purpose.
 *
 * So the test is not "has a path" but "constrains something beyond the
 * registrable domain" — ANY of: a path pattern, a required query parameter, a
 * subdomain pattern (the tenant-host shape: `<store>.myshopify.com`), or a
 * named identifier capture.
 *
 * A detector that constrains none of those matches the whole domain. Under it,
 * `quandoo.fi/anything-at-all` projects to quandoo.reserve with confidence to
 * spare and no identifier, and SourceReconciler then files the entire URL as
 * the account's resource_id — the "URL-as-account row" that renders as a
 * nameless card and can never be refreshed, named, or verified. That is one
 * bug, not several: it is also why an Uber Eats card shows no restaurant name.
 *
 * ── The census this was written against (2026-09-03) ────────────────────────
 *
 * 219 of 400 detectors are host-only. 56 of the 116 connectable+active surfaces
 * have NO detector with any grammar at all, and 15 more have a mix. So L1 WEAK
 * is not an edge case today — it is the majority of the reservations/ordering/
 * booking shelf, and gating a WRITE on L1 PASS before the pattern fleet lands
 * would make 56 brands unconnectable.
 *
 * That is why WEAK is not a refusal. WEAK means "escalate to L2", and a brand
 * with no L2 mechanism at all resolves save-and-flag. Nothing here can make a
 * link undeliverable; it can only decide whether we are allowed to claim the
 * link is an ACCOUNT without checking.
 */
final class LinkValidity
{
    public const PASS = 'pass';

    public const WEAK = 'weak';

    /** No detector matched at all — not a validity question, a routing one. */
    public const NONE = 'none';

    /**
     * Identity kinds a PERSON hands us rather than ones we read off a URL.
     * L1 never judges these: there is no path to have failed.
     *
     * @var list<string>
     */
    private const GIVEN_IDENTITY_KINDS = ['place_id', 'domain', 'handle'];

    /**
     * L1 for a projection: PASS when the matched detector constrains more than
     * the registrable domain, WEAK when it matched the domain and nothing else.
     */
    public static function l1(Projection $projection): string
    {
        if (! $projection->matched() || $projection->detectorId === null) {
            return self::NONE;
        }

        return self::l1ForDetector($projection->detectorId);
    }

    /**
     * L1 from a detector id alone — what the ACCEPT path has.
     *
     * `routing.source_intents.detector_id` records which rule matched when the
     * suggestion was made, so accept can re-ask this question without
     * re-projecting the URL, and without the answer drifting if the catalog
     * changed in between (the intent also stores catalog_digest, which is what
     * a future audit would compare).
     *
     * An unknown id is WEAK, not PASS: a detector that no longer exists cannot
     * vouch for anything, and the fail-safe direction here is to CHECK.
     */
    public static function l1ForDetector(?string $detectorId): string
    {
        if ($detectorId === null || $detectorId === '') {
            return self::NONE;
        }

        $detector = CompiledCatalog::detectors()[$detectorId] ?? null;

        return $detector !== null && self::detectorIsSpecific($detector) ? self::PASS : self::WEAK;
    }

    /**
     * Whether a detector constrains anything beyond its registrable domain.
     *
     * Public because the catalog tooling reports on it directly (the pattern
     * fleet's job is to drive the WEAK count down, and it needs to enumerate
     * exactly which detectors are still weak).
     *
     * @param  array<string, mixed>  $detector
     */
    public static function detectorIsSpecific(array $detector): bool
    {
        return ((string) ($detector['path_pattern'] ?? '')) !== ''
            || ($detector['query_requires'] ?? []) !== []
            || ((string) ($detector['subdomain_pattern'] ?? '')) !== ''
            || ((string) ($detector['identifier_capture'] ?? '')) !== '';
    }

    /**
     * Whether L1 is a question worth asking for this surface at all.
     *
     * Only CONNECTABLE surfaces can hold a wrong claim: a Note-only surface
     * produces a link card, which says nothing about ownership.
     *
     * And only url-kind identities are at risk. `place_id`, `domain` and
     * `handle` are the identities a person GIVES us — the Google Business place
     * they picked, the Instagram handle they signed up with — so they must
     * never be able to fail closed here; a url-kind identity is the only one
     * that degrades into "the whole URL is the account" when no rule captured
     * anything. The census backs the scoping: of the 56 connectable+active
     * surfaces whose every detector is host-only, 54 are url-kind, 1 handle,
     * 1 slug.
     *
     * @param  array<string, mixed>|null  $surface
     */
    public static function applies(?array $surface): bool
    {
        return $surface !== null
            && (bool) ($surface['is_connectable'] ?? false)
            && ($surface['lifecycle'] ?? null) === 'active'
            && ! in_array($surface['identifier_kind'] ?? null, self::GIVEN_IDENTITY_KINDS, true);
    }
}
