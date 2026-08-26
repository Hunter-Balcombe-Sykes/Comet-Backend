<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;
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
     * Confidence floor below which nothing is even considered a candidate.
     *
     * This is an IDENTIFICATION floor, not a write gate — the write gate is
     * RoutingPolicy's per-class thresholds (auto 70-80, suggest 45-55), which
     * every value admitted here falls under, so a match this low can only ever
     * become Verdict::Note and Note::writesIntent() is false.
     *
     * It was 35, which is above what a host-only detector can score on a real
     * URL: 40 base, minus the 8-point deep-path penalty below, plus a strength
     * delta of 0 for ProfileLink — 32. So 64 of 114 surfaces matched ONLY the
     * bare host, the one shape carrying no identity, and every real Ko-fi,
     * Booksy, GitHub or Resident Advisor URL came back 'unrecognised link'.
     * That is a stronger claim than the projector is entitled to make, and
     * LinkRouter spent a commerce probe on each one rediscovering the host
     * (N1/N4, 2026-08-11 Instagram build wave).
     *
     * 25 admits every host-only detector of MarketplaceListing strength (30)
     * and above while still excluding Mention (10) — a passing reference to a
     * brand is not evidence of a connection to it.
     */
    private const FLOOR = 25;

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
                $scored = $this->score($iri, $detector, $detectorId);
                if ($scored !== null) {
                    $candidates[] = $scored;
                }
            }
        }

        if ($candidates === []) {
            return Projection::none($anyRuleExists ? 'no-rule-matched' : 'unknown-domain');
        }

        usort($candidates, fn (array $a, array $b) => $b['confidence'] <=> $a['confidence'] ?: strcmp($a['detector'], $b['detector']));

        $best = $candidates[0];

        // Margin is the gap to the best candidate for a DIFFERENT surface, not
        // simply to the second-place row. Several detectors routinely describe
        // ONE surface — Opentable.php declares a `rid` query rule and a
        // `restRef` query rule per TLD, and a booking URL carrying both params
        // matches both at the same score. Measured against the plain runner-up
        // that reads as margin 0, i.e. maximum ambiguity, when the two rules in
        // fact AGREE about the answer. Margin exists to express "which surface
        // is this?" (see Projection's own docblock), so a surface competing
        // only with itself has nothing to be ambiguous about and keeps its full
        // confidence. $candidates is already sorted by confidence descending,
        // so the first different-surface entry IS the strongest rival.
        $rival = null;
        foreach ($candidates as $candidate) {
            if ($candidate['surface'] !== $best['surface']) {
                $rival = $candidate;
                break;
            }
        }

        $margin = $best['confidence'] - ($rival['confidence'] ?? 0);

        return new Projection(
            surfaceKey: $best['surface'],
            detectorId: $best['detector'],
            captures: $best['captures'],
            confidence: $best['confidence'],
            margin: $margin,
            identifier: $best['identifier'],
            reason: null,
            alternatives: array_map(
                fn (array $c) => ['surface' => $c['surface'], 'detector' => $c['detector'], 'confidence' => $c['confidence']],
                array_slice($candidates, 1, 3),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $detector
     * @return array{surface: string, detector: string, captures: array<string,string>, identifier: ?string, confidence: int}|null
     */
    private function score(Iri $iri, array $detector, string $detectorId): ?array
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
            if ($this->matches($reject, $iri->path, $detectorId, "reject_patterns[{$i}]")) {
                return null;
            }
        }

        $captures = [];
        $confidence = 40; // a host-only match is weak but real

        if ($detector['subdomain_pattern'] !== null) {
            if ($iri->subdomain === null || ! $this->matches($detector['subdomain_pattern'], $iri->subdomain, $detectorId, 'subdomain_pattern', $m)) {
                return null;
            }
            $captures += $this->namedGroups($m);
            $confidence += 20;
        }

        if ($detector['path_pattern'] !== null) {
            if (! $this->matches($detector['path_pattern'], $iri->path, $detectorId, 'path_pattern', $m)) {
                return null;
            }
            $captures += $this->namedGroups($m);
            $confidence += 35;
        } elseif ($iri->path !== '/' && $detector['query_requires'] === []) {
            // A host-only rule matching a deep path is a weaker claim than the
            // same rule on the bare host: the path may belong to another
            // product entirely.
            $confidence -= 8;
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
            $confidence += 15;
        }

        // Evidence strength is intrinsic to the rule (a profile link is worth
        // more than a mention) and shifts confidence around the structural
        // score rather than replacing it.
        $confidence += (int) round(((int) $detector['strength'] - 50) / 5);

        // A rule promising an identifier that produced nothing is not a match.
        $identifier = null;
        if ($detector['identifier_capture'] !== null) {
            $identifier = $captures[$detector['identifier_capture']] ?? null;
            if ($identifier === null || $identifier === '') {
                return null;
            }
        }

        // A query-captured identifier is worth what a path-captured one is
        // worth. `?rid=291533` names a restaurant exactly as precisely as
        // `/restaurant/profile/291533`, and Opentable.php deliberately declares
        // both shapes at the same EvidenceStrength — but structurally the path
        // form scored 40+35 and the query form 40+15, a 20-point gap that says
        // nothing about how well either identifies an account. On reservations
        // (suggest 55) with the 10-point indirect penalty that gap IS the
        // difference between a connection and a dead link card: the
        // st-ali-bali signup's real booking link projected cleanly to
        // opentable.reserve, scored 59, and was dropped as Verdict::Note.
        // +20 brings the query form to +35 total — exact parity with the path
        // form, not a thumb on the scale.
        //
        // Gated on an identifier having actually been captured: a detector that
        // merely REQUIRES a query param has not thereby identified an account,
        // and only the one that names its identifier from the query has.
        //
        // Cannot rescue a below-FLOOR detector into matching, which is the only
        // shape that could regress the negative corpus: identifier_source
        // 'query' implies at least one query_requires entry (+15), and the -8
        // deep-path penalty above is gated on query_requires === [], so the
        // worst case is 40+15-8 = 47 — already clear of FLOOR (25) before this.
        if ($identifier !== null && ($detector['identifier_source'] ?? null) === 'query') {
            $confidence += 20;
        }

        $confidence = max(0, min(100, $confidence));
        if ($confidence < self::FLOOR) {
            return null;
        }

        return [
            'surface' => $surfaceKey,
            'detector' => $detectorId,
            'captures' => $captures,
            'identifier' => $identifier,
            'confidence' => $confidence,
        ];
    }

    /**
     * The one place a detector pattern is ever executed. `@` stays INSIDE: with
     * it removed, HandleExceptions::handleError() turns PCRE's compile warning
     * into an ErrorException that escapes score() and 500s the paste preview
     * (LinkRoutingService::preview). So the verdict on an uncompilable pattern
     * is byte-identical to the pre-SLOP-21 behaviour — fail that detector
     * closed — but it is now reported instead of silent.
     *
     * @param  array<int|string, string>|null  $m  populated exactly as preg_match's third argument
     *
     * @param-out array<int|string, string> $m
     */
    private function matches(string $pattern, string $subject, string $detectorId, string $field, ?array &$m = null): bool
    {
        $result = @preg_match($pattern, $subject, $m);

        if ($result === false) {
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
