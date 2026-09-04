<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
use App\Catalog\Enums\EvidenceStrength;
use App\Exceptions\Routing\MalformedDetectorPatternException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pure projection: f(Iri, Rulepack) → Projection. Cannot fetch, cannot read
 * the database, cannot look at caller context (that is PlacementPolicy's
 * job). Deterministic — the same inputs always yield the same verdict, which
 * is what makes `routing:reproject` a real diff tool.
 *
 * Purity is a property of the returned Projection, not of the process: the
 * malformed-pattern report in matches() is observability only — it cannot
 * change a verdict, and it cannot throw. See reportMalformedPattern() for the
 * throttle idiom and its precedents.
 */
class LinkProjector
{
    /**
     * A `Mention`-strength rule that constrains nothing but the host does not
     * match a DEEP path — only the bare host.
     *
     * This is the structural form of what a numeric floor used to do, and it
     * is the entire behavioural content that floor ever had. The old rule was
     * "reject a candidate scoring under 25", and the arithmetic meant exactly
     * one shape could get there: host-only (40 base) on a deep path (−8) at
     * Mention strength (−8) = 24. Every other combination cleared it before
     * the comparison was made — the code's own comment worked out that the
     * worst case with a query rule was 47.
     *
     * So the floor was a threshold with one member, and saying who that member
     * is beats recomputing the sum that identified them. The reason it should
     * hold has not changed: a passing reference to a brand is not evidence of
     * a connection to it, and on a deep path a host-only Mention rule is not
     * even a reference to the right page.
     */
    private static function tooWeakForDeepPath(array $detector, Iri $iri): bool
    {
        return $iri->path !== '/'
            && $detector['path_pattern'] === null
            && $detector['subdomain_pattern'] === null
            && $detector['query_requires'] === []
            && (int) $detector['strength'] <= EvidenceStrength::Mention->value;
    }

    public function __construct(private readonly Rulepack $rulepack) {}

    public function project(Iri $iri): Projection
    {
        if (! $iri->isRoutable()) {
            return Projection::none($iri->rejected ?? 'unroutable');
        }

        $candidates = [];
        $anyRuleExists = false;

        foreach ($iri->candidateKeys() as $key) {
            foreach ($this->rulepack->candidatesFor($key) as $detectorId) {
                // Set BEFORE the suspension check, deliberately. A suspended
                // detector is a rule that exists and was switched off, so the
                // no-match reason stays 'no-rule-matched'. Skipping the flag
                // would report 'unknown-domain' — and that string is what
                // catalog.unmatched_domains.has_detectors is derived from, so
                // it would file a suspended brand in the triage queue as a
                // host nobody has written a detector for.
                $anyRuleExists = true;

                // The staff kill-switch (catalog.detector_suspensions),
                // resolved onto the Rulepack when the singleton was built —
                // never read from here, which is what keeps project() pure.
                if ($this->rulepack->isSuspended($detectorId)) {
                    continue;
                }

                $detector = $this->rulepack->detector($detectorId);
                if ($detector === null) {
                    continue;
                }
                $matched = $this->match($iri, $detector, $detectorId);
                if ($matched !== null) {
                    $candidates[] = $matched;
                }
            }
        }

        if ($candidates === []) {
            return Projection::none($anyRuleExists ? 'no-rule-matched' : 'unknown-domain');
        }

        // Ordered by how much the rule CONSTRAINS, best first — the
        // structural replacement for sorting by a confidence score.
        //
        // The old sum ranked a path pattern (+35) over a subdomain (+20) over
        // each required query param (+15), then nudged by evidence strength.
        // The tuple below says the same thing without pretending the gaps
        // between those numbers meant anything, and the routing corpus is what
        // proves the two orders agree on real URLs.
        usort($candidates, fn (array $a, array $b) => self::rank($b) <=> self::rank($a)
            ?: strcmp($a['detector'], $b['detector']));

        $best = $candidates[0];

        // Contested = a rule for a DIFFERENT surface matched too, so which
        // brand this link belongs to is actually open.
        //
        // This replaces `margin`, and the reason it is a boolean is the reason
        // margin needed a paragraph of explanation: several detectors routinely
        // describe ONE surface (Opentable declares a `rid` rule and a `restRef`
        // rule per TLD, and a URL carrying both matches both), so the gap to
        // the plain runner-up read as maximum ambiguity precisely when the two
        // rules AGREED. Asking about a different surface is the question margin
        // was trying to ask; the arithmetic was the part that kept getting it
        // wrong.
        // A brand's HOST-ONLY fallback never contests a rule that constrained
        // something. `square.site/s/order` matches square.order's `/s/order`
        // path rule and, underneath it, square.book's bare-host rule — and the
        // catalog says in its own comments that booking deliberately owns the
        // ambiguous root. That is the specific rule overriding the default by
        // design, not two brands disagreeing, and calling it contested demoted
        // every Square ordering link to a question we already knew the answer
        // to. Two host-only rules for different brands on one domain still
        // contest each other: there, neither side constrained anything.
        $bestIsSpecific = self::constrains($best);
        $contested = false;
        $alternatives = [];
        foreach (array_slice($candidates, 1) as $candidate) {
            if ($candidate['surface'] !== $best['surface']
                && (self::constrains($candidate) || ! $bestIsSpecific)) {
                $contested = true;
            }
            if (count($alternatives) < 3) {
                $alternatives[] = ['surface' => $candidate['surface'], 'detector' => $candidate['detector']];
            }
        }

        return new Projection(
            surfaceKey: $best['surface'],
            detectorId: $best['detector'],
            captures: $best['captures'],
            identifier: $best['identifier'],
            reason: null,
            contested: $contested,
            alternatives: $alternatives,
        );
    }

    /**
     * Did this rule constrain anything beyond the brand's registrable domain?
     * The matched-flag twin of LinkValidity::detectorIsSpecific().
     *
     * @param  array{path: bool, subdomain: bool, queries: int}  $c
     */
    private static function constrains(array $c): bool
    {
        return $c['path'] || $c['subdomain'] || $c['queries'] > 0;
    }

    /**
     * How much a matched rule constrains, as one comparable number.
     *
     * NOT a confidence score and never compared against a threshold — it only
     * orders candidates against each other, so its absolute value means
     * nothing and no tuning of it can change whether a link is written. That
     * is the whole difference from what this replaced.
     *
     * @param  array{path: bool, subdomain: bool, queries: int, strength: int}  $c
     */
    private static function rank(array $c): int
    {
        return ($c['path'] ? 4000 : 0)
            + ($c['subdomain'] ? 2000 : 0)
            + min($c['queries'], 9) * 1000
            + $c['strength'];
    }

    /**
     * Does this rule match, and if so what did it constrain and capture?
     *
     * Renamed from score(): it no longer computes anything, it only matches.
     * Every `$confidence +=` this used to carry has gone — the facts they were
     * summing (a path pattern matched, a subdomain matched, N query params were
     * required) are now returned as themselves and ordered by rank().
     *
     * @param  array<string, mixed>  $detector
     * @return array{surface: string, detector: string, captures: array<string,string>, identifier: ?string, path: bool, subdomain: bool, queries: int, strength: int}|null
     */
    private function match(Iri $iri, array $detector, string $detectorId): ?array
    {
        $surfaceKey = $this->rulepack->surfaceFor($detectorId);
        if ($surfaceKey === null) {
            return null; // pure signal detector — informs evidence, never places
        }

        // Reserved paths are a property of the SURFACE: a path on this list can
        // never auto-connect regardless of which rule matched it.
        $surface = CompiledCatalog::surface($surfaceKey);
        foreach ($surface['reserved_paths'] ?? [] as $reserved) {
            if (str_starts_with($iri->path, $reserved)) {
                return null;
            }
        }

        foreach ($detector['reject_patterns'] as $i => $reject) {
            if ($this->rejects($reject, $iri->path, $detectorId, "reject_patterns[{$i}]")) {
                return null;
            }
        }

        if (self::tooWeakForDeepPath($detector, $iri)) {
            return null;
        }

        $captures = [];

        if ($detector['subdomain_pattern'] !== null) {
            if ($iri->subdomain === null || ! $this->matches($detector['subdomain_pattern'], $iri->subdomain, $detectorId, 'subdomain_pattern', $m)) {
                return null;
            }
            $captures += $this->namedGroups($m);
        }

        if ($detector['path_pattern'] !== null) {
            if (! $this->matches($detector['path_pattern'], $iri->path, $detectorId, 'path_pattern', $m)) {
                return null;
            }
            $captures += $this->namedGroups($m);
        }

        foreach ($detector['query_requires'] as $param) {
            $found = null;
            foreach ($iri->query as $key => $value) {
                if (strcasecmp($key, $param) === 0) {
                    $found = $value;
                    break;
                }
            }
            if ($found === null || $found === '') {
                return null;
            }
            $captures[$param] = $found;
        }

        // A rule promising an identifier that produced nothing is not a match.
        $identifier = null;
        if ($detector['identifier_capture'] !== null) {
            $identifier = $captures[$detector['identifier_capture']] ?? null;
            if ($identifier === null || $identifier === '') {
                return null;
            }
        }

        return [
            'surface' => $surfaceKey,
            'detector' => $detectorId,
            'captures' => $captures,
            'identifier' => $identifier,
            'path' => $detector['path_pattern'] !== null,
            'subdomain' => $detector['subdomain_pattern'] !== null,
            'queries' => count($detector['query_requires']),
            'strength' => (int) $detector['strength'],
        ];
    }

    /**
     * A reject rule fails CLOSED (#W1-SEC-11). matches() collapses a preg_match()
     * RUNTIME failure — PREG_BACKTRACK_LIMIT_ERROR, or PREG_BAD_UTF8_ERROR on a
     * crafted path against a /u pattern — into the same `false` a clean non-match
     * returns. Every OTHER caller already reads that `false` as "this detector
     * does not apply" and returns null; only this loop read it as "carry on", so
     * a subject pathological enough to break the engine skipped the very rule
     * written to exclude it and the detector went on to score. A reject pattern
     * that errors is at least as suspicious as one that matches, so it costs the
     * detector its match — which is also what an uncompilable one already did
     * everywhere else in match().
     *
     * "Execution error" is BOTH halves of preg_match's `false`, so this changes
     * COMPILE failure here too, not only the runtime case the finding named: an
     * uncompilable reject pattern used to let the detector carry on and now
     * fails it closed. That half is not attacker-reachable (CatalogCompileCommand
     * rejects an uncompilable pattern, so the shipped catalog cannot carry one)
     * and it moves in the same direction, which is why it is not split out.
     *
     * Purity is untouched: the verdict still depends only on (Iri, Rulepack).
     */
    private function rejects(string $pattern, string $subject, string $detectorId, string $field): bool
    {
        $m = null;
        $errored = false;

        return $this->matches($pattern, $subject, $detectorId, $field, $m, $errored) || $errored;
    }

    /**
     * The one place a detector pattern is ever executed. `@` stays INSIDE: with
     * it removed, HandleExceptions::handleError() turns PCRE's compile warning
     * into an ErrorException that escapes match() and 500s the paste preview
     * (LinkRoutingService::preview). So the verdict on an uncompilable pattern
     * is byte-identical to the pre-SLOP-21 behaviour — fail that detector
     * closed — but it is now reported instead of silent.
     *
     * @param  array<int|string, string>|null  $m  populated exactly as preg_match's third argument
     * @param  bool|null  $errored  true when preg_match itself failed, as opposed to cleanly not matching
     *
     * @param-out array<int|string, string> $m
     * @param-out bool $errored
     */
    private function matches(string $pattern, string $subject, string $detectorId, string $field, ?array &$m = null, ?bool &$errored = null): bool
    {
        $errored = false;

        $result = @preg_match($pattern, $subject, $m);

        if ($result === false) {
            $errored = true;

            // preg_match leaves $matches UNTOUCHED on a compile failure (it
            // only overwrites on 0 or 1), so a caller reusing one $m across two
            // patterns could read the previous pattern's groups. No caller does
            // today — every false path returns immediately — but the reset makes
            // that a property of the wrapper rather than of its callers.
            $m = [];

            $this->reportMalformedPattern($detectorId, $field, $pattern);

            return false;
        }

        return $result === 1;
    }

    /**
     * Once per detector+field per window. The projector runs on every paste, so
     * an unthrottled report would turn one bad catalog entry into a Nightwatch
     * flood. Cache::add is an atomic SETNX — false means we already reported
     * this pattern inside the window (idiom: LogLeadRateLimits, AnalyticsDedupGuard).
     */
    private function reportMalformedPattern(string $detectorId, string $field, string $pattern): void
    {
        try {
            // A non-positive TTL makes Cache::add() return false forever
            // (Laravel treats it as an already-expired write) — total silence,
            // the opposite of what "no throttling" means. Floor it.
            $ttl = max(1, (int) config('partna.routing.malformed_pattern_report_ttl_seconds', 3600));

            if (! Cache::add("routing:malformed-detector:{$detectorId}:{$field}", 1, $ttl)) {
                return;
            }

            Log::error('routing.detector.malformed_pattern', [
                'detector_id' => $detectorId,
                'field' => $field,
                'pattern' => $pattern,
                'catalog_digest' => $this->rulepack->catalogDigest,
            ]);

            report(new MalformedDetectorPatternException($detectorId, $field, $pattern));
        } catch (Throwable) {
            // Observability must never become the fault. A cache outage costs
            // the signal, never the verdict — and never a 500 on the preview.
        }
    }

    /**
     * @param  array<int|string, string>  $matches
     * @return array<string, string>
     */
    private function namedGroups(array $matches): array
    {
        $named = [];
        foreach ($matches as $key => $value) {
            if (is_string($key) && $value !== '') {
                $named[$key] = $value;
            }
        }

        return $named;
    }
}
