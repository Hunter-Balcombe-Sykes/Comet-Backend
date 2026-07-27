<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;

/**
 * Pure projection: f(Iri, Rulepack) → Projection. Cannot fetch, cannot read
 * the database, cannot look at caller context (that is PlacementPolicy's
 * job). Deterministic — the same inputs always yield the same verdict, which
 * is what makes `routing:reproject` a real diff tool.
 */
class LinkProjector
{
    /** Confidence floor below which nothing is even considered a candidate. */
    private const FLOOR = 35;

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
                $anyRuleExists = true;
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
        $margin = $best['confidence'] - ($candidates[1]['confidence'] ?? 0);

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

        foreach ($detector['reject_patterns'] as $reject) {
            if (@preg_match($reject, $iri->path) === 1) {
                return null;
            }
        }

        $captures = [];
        $confidence = 40; // a host-only match is weak but real

        if ($detector['subdomain_pattern'] !== null) {
            if ($iri->subdomain === null || @preg_match($detector['subdomain_pattern'], $iri->subdomain, $m) !== 1) {
                return null;
            }
            $captures += $this->namedGroups($m);
            $confidence += 20;
        }

        if ($detector['path_pattern'] !== null) {
            if (@preg_match($detector['path_pattern'], $iri->path, $m) !== 1) {
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
