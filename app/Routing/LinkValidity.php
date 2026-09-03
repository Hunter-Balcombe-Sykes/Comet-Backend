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
     * Does this surface know what a real account link LOOKS like?
     *
     * The difference between "this link is wrong" and "we cannot tell" — and
     * the only thing that entitles us to REFUSE a link outright. A surface with
     * at least one specific detector has a shape on file, so a URL that matched
     * only its bare-domain sibling is a link we can say is not an account page.
     * A surface with no specific detector at all (9 of them as of 2026-09-03)
     * has no such standing: refusing there would be inventing a rule.
     *
     * @return array{0: bool, 1: ?string} [has a shape, a generic hint like
     *                                    "https://www.doordash.com/store/…"]
     */
    /**
     * The boolean half of shapeFor(), without building the hint.
     *
     * Separate because this one runs on the ROUTING path — every link of every
     * harvest passes through PlacementPolicy — where the hint is never used and
     * a surface like OpenTable would otherwise cost 48 preg_match calls per
     * link to compute a string nobody reads.
     */
    public static function hasShape(string $surfaceKey): bool
    {
        $surface = CompiledCatalog::surface($surfaceKey);
        if ($surface === null) {
            return false;
        }

        $detectors = CompiledCatalog::detectors();
        foreach ($surface['detectors'] as $id) {
            if (isset($detectors[$id]) && self::detectorIsSpecific($detectors[$id])) {
                return true;
            }
        }

        return false;
    }

    public static function shapeFor(string $surfaceKey): array
    {
        $surface = CompiledCatalog::surface($surfaceKey);
        if ($surface === null) {
            return [false, null];
        }

        $detectors = CompiledCatalog::detectors();
        $hasShape = false;

        foreach ($surface['detectors'] as $id) {
            $detector = $detectors[$id] ?? null;
            if ($detector === null || ! self::detectorIsSpecific($detector)) {
                continue;
            }
            // A shape on file is enough to refuse; the hint is a bonus. They are
            // found in one pass because a surface can carry several specific
            // detectors and only some of them record an example — Uber Eats has
            // the shape but no note, so it refuses with the plain sentence.
            $hasShape = true;

            // The pattern fleet recorded a REAL, live-verified URL in each
            // detector's note as `e.g. https://…`. Reusing it is what lets the
            // refusal say "paste the link to your store page, like
            // https://www.doordash.com/store/…" instead of a bare "invalid".
            $hint = self::exampleFromNote($detector);
            if ($hint !== null) {
                return [true, $hint];
            }
        }

        return [$hasShape, null];
    }

    /**
     * A note's `e.g. https://…` reduced to its SHAPE — the same path with the
     * account's own segment replaced by an ellipsis.
     *
     * The fleet's examples are real, live-verified pages belonging to real
     * businesses, which is what makes them trustworthy as catalog evidence and
     * exactly what makes them wrong to quote at a user: "paste a link like
     * https://www.quandoo.com.au/place/ricks-place-92706" hands a stranger's
     * restaurant to someone trying to connect their own. The origin and the
     * routing segment are the whole message anyway — the last segment is the
     * part they are supposed to supply.
     */
    /**
     * @param  array<string, mixed>  $detector
     */
    private static function exampleFromNote(array $detector): ?string
    {
        $pattern = (string) ($detector['path_pattern'] ?? '');
        $capture = (string) ($detector['identifier_capture'] ?? '');
        if (preg_match('~\bhttps?://\S+~', (string) ($detector['note'] ?? ''), $m) !== 1
            || $pattern === '' || $capture === '') {
            return null;
        }

        $url = rtrim($m[0], '.,;)');
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        if (! is_string($host) || $host === '' || $path === '') {
            return null;
        }

        // Mask the segment the detector's OWN capture matched, rather than
        // guessing which segment is the variable one. The naive guess — "keep
        // the first segment" — is right for /store/{slug} and wrong for
        // /{slug}/menu, where it would print a real restaurant's name at
        // someone trying to connect their own. Running the real pattern is the
        // only thing that knows which part is theirs to supply.
        if (@preg_match($pattern, $path, $captured) !== 1
            || ! isset($captured[$capture]) || $captured[$capture] === '') {
            return null;
        }
        $identifier = (string) $captured[$capture];

        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $masked = array_map(fn ($s) => str_contains($s, $identifier) ? '…' : $s, $segments);
        if ($masked === $segments) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$host.'/'.implode('/', $masked);
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
