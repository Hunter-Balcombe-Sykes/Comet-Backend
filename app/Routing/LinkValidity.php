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
 * ── The census, and why WEAK is still not a refusal ─────────────────────────
 *
 * Written against a catalog where 56 of the 116 connectable+active surfaces had
 * NO detector with any grammar at all. The real-URL sweep has since driven that
 * to FOUR (2026-09-03, 538 detectors; 219 still host-only, but every one of
 * those now sits beside a specific sibling on all but four surfaces):
 *
 *   · bandcamp.store     no detectors by design — bandcamp.artist owns routing
 *   · easi.order         app-only; no web ordering pages exist to pattern
 *   · shortcuts.book     both detected hosts 301 elsewhere (owner call pending)
 *   · woocommerce.store  self-hosted on the merchant's own domain, with no
 *                        hosted address at all — structurally ungrammatical,
 *                        which is the case this class exists to handle
 *
 * The temptation now is to gate the WRITE on L1 PASS, since four brands is a
 * price one could pay. Don't. WooCommerce shows why the exemption is permanent
 * rather than transitional: a link can be perfectly valid and still carry no
 * host signal, so PASS is a statement about the URL's shape and never about the
 * link's truth. WEAK means "escalate to L2", and a brand with no L2 mechanism
 * resolves save-and-flag. Nothing here can make a link undeliverable; it only
 * decides whether we may claim the link is an ACCOUNT without checking.
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
            || $capture === '') {
            return null;
        }

        $url = rtrim($m[0], '.,;)');
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        // An empty path is only disqualifying for the path branch, which has
        // nothing to mask without one. For the tenant-host shape it is the NORM
        // — `https://garbageday.substack.com` is the whole link — so the guard
        // that used to sit here rejected exactly the examples the subdomain
        // branch exists to reduce.
        if (! is_string($host) || $host === '') {
            return null;
        }

        // Dispatch on where the IDENTITY lives, not on which pattern happens to
        // be present. Those are different questions and conflating them lost
        // HungryPanda: it constrains a path (`/shop`) AND identifies through
        // the query (`?shopId=`), so a "has a path_pattern → mask a segment"
        // test sent it down the path branch, which then found no such capture
        // in the path and returned nothing.
        $source = $detector['identifier_source'] ?? null;
        if ($source === 'subdomain') {
            return self::subdomainShape($detector, $parts, $host, $path);
        }
        if ($source === 'query') {
            return self::queryShape($detector, $parts, $host, $path, $capture);
        }
        if ($pattern === '' || $path === '') {
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

        // Masking the captured segment ALONE is not enough, and Uber Eats is
        // the proof: /au/store/<restaurant-slug>/<opaque id> captures only the
        // id, so eliding it still published "kfc-sydney-central-plaza" to
        // whoever pasted a bad link — a real business's page, which is the one
        // thing this whole reduction exists to prevent.
        //
        // So a segment survives only if the pattern names it LITERALLY. That is
        // deliberately a blunt test biased toward masking: a fixed routing word
        // ('store', 'restaurant', 'profile') is written into the pattern and
        // survives, while anything the pattern expresses as a character class —
        // the slug, and a locale like '/au' — is masked whether or not we
        // happen to capture it. Over-masking costs a little specificity in a
        // hint; under-masking hands out someone's storefront.
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        $masked = array_map(
            fn (string $s) => str_contains($s, $identifier) || ! str_contains($pattern, $s) ? '…' : $s,
            $segments,
        );
        if ($masked === $segments) {
            return null;
        }

        // Consecutive elisions read as noise ('/…/…/…'); one stands for the
        // whole variable run.
        $collapsed = [];
        foreach ($masked as $segment) {
            if ($segment === '…' && end($collapsed) === '…') {
                continue;
            }
            $collapsed[] = $segment;
        }
        $masked = $collapsed;

        return ($parts['scheme'] ?? 'https').'://'.$host.'/'.implode('/', $masked);
    }

    /**
     * The same reduction for the tenant-host shape — `<you>.substack.com`,
     * `<salon>.book.app`, `<studio>.zenoti.com`. Twelve surfaces identify this
     * way and every one of them was losing its hint, because the path masking
     * needs a path_pattern and a subdomain detector has no path to constrain.
     *
     * The registrable key is what the mask is cut against rather than the PSL:
     * the detector already carries the domain it was declared for, so the
     * subdomain is simply whatever precedes it, and no suffix parsing (or its
     * disagreements) enters into a display string. A note URL whose host is
     * the bare registrable domain has no subdomain to mask and returns null.
     *
     * @param  array<string, mixed>  $detector
     * @param  array<string, mixed>  $parts
     */
    private static function subdomainShape(array $detector, array $parts, string $host, string $path): ?string
    {
        $registrable = (string) ($detector['registrable_key'] ?? '');
        if ($registrable === '' || ! str_ends_with(strtolower($host), '.'.strtolower($registrable))) {
            return null;
        }

        $subdomain = substr($host, 0, -(strlen($registrable) + 1));
        // Same refusal as the query case: if the tenant's name also appears in
        // the path, masking the host alone would still print it.
        if ($subdomain === '' || str_contains(strtolower($path), strtolower($subdomain))) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://….'.$registrable.($path === '/' ? '/' : $path);
    }

    /**
     * The same reduction for a detector that identifies through the QUERY
     * rather than the path — `?restRef=`, `?owner=`, `?studioid=`, `?sh=`.
     *
     * Without this the whole hint is lost for those surfaces, because the path
     * masking above needs a path_pattern and a query detector has none. The
     * user then gets "paste the link to your own page" and no way to tell what
     * distinguishes one: the endpoint is generic (`/schedule.php`,
     * `/Shows/Show.aspx`) and the identity is entirely in the parameter.
     *
     * Two refusals to guess, both on the same privacy ground as the path case:
     * every other parameter of the example is dropped rather than reprinted,
     * and if the identifier ALSO appears somewhere in the path we return null
     * instead — nothing here knows which path segment is the variable one, so
     * masking it would be a guess and printing it would hand out a real
     * business's page.
     *
     * @param  array<string, mixed>  $detector
     * @param  array<string, mixed>  $parts
     */
    private static function queryShape(array $detector, array $parts, string $host, string $path, string $capture): ?string
    {
        if (($detector['identifier_source'] ?? null) !== 'query') {
            return null;
        }

        $required = $detector['query_requires'] ?? [];
        if (! is_array($required) || ! in_array($capture, $required, true)) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $value = null;
        foreach ($query as $key => $v) {
            if (is_string($v) && strcasecmp((string) $key, $capture) === 0) {
                $value = $v;
                break;
            }
        }
        if ($value === null || $value === '' || ($path !== '' && str_contains($path, $value))) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$host.($path === '' ? '/' : $path).'?'.$capture.'=…';
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
