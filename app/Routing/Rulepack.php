<?php

namespace App\Routing;

use App\Catalog\CompiledCatalog;

/**
 * The compiled routing rulepack: an index from registrable key → the
 * detectors that could possibly match it, plus the scoring inputs. Built at
 * compile time into the catalog artefact (`rulepack`), loaded from there at
 * runtime — the projector never scans all 268 detectors.
 *
 * ONE engine (plan §2): the platform-identity ruling's additive specificity
 * formula survives only as the build-time tie gate that orders candidates
 * within a key; runtime scoring is margin + evidence weight on a 0–100 scale.
 */
final readonly class Rulepack
{
    public function __construct(
        /** @var array<string, list<string>> registrable key => detector ids, most specific first */
        public array $byRegistrableKey,
        /** @var array<string, array<string, mixed>> detector id => detector */
        public array $detectors,
        /** @var array<string, string> detector id => surface key */
        public array $detectorSurface,
        public string $catalogDigest,
    ) {}

    public static function fromCompiledCatalog(): self
    {
        $artefact = CompiledCatalog::load();
        $pack = $artefact['rulepack'] ?? null;

        if (is_array($pack)) {
            return new self(
                byRegistrableKey: $pack['by_registrable_key'],
                detectors: CompiledCatalog::detectors(),
                detectorSurface: $pack['detector_surface'],
                catalogDigest: $artefact['digest'],
            );
        }

        // Artefact predates the rulepack (or was built by an older binary):
        // derive it in-process rather than fail. catalog:compile bakes it in.
        return self::derive(CompiledCatalog::detectors(), $artefact['digest']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $detectors
     */
    public static function derive(array $detectors, string $digest): self
    {
        $byKey = [];
        $surface = [];

        foreach ($detectors as $id => $detector) {
            $key = $detector['registrable_key'];
            if ($key !== null) {
                $byKey[$key][] = $id;
            }
            if ($detector['surface_key'] !== null) {
                $surface[$id] = $detector['surface_key'];
            }
        }

        // Build-time tie gate: within one registrable key, the most specific
        // rule must be tried first, deterministically. Specificity = how much
        // the rule actually constrains (path > subdomain > query > bare host),
        // then evidence strength, then id for a total order.
        foreach ($byKey as $key => $ids) {
            usort($ids, function (string $a, string $b) use ($detectors) {
                $sa = self::specificity($detectors[$a]);
                $sb = self::specificity($detectors[$b]);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }
                if ($detectors[$a]['strength'] !== $detectors[$b]['strength']) {
                    return $detectors[$b]['strength'] <=> $detectors[$a]['strength'];
                }

                return strcmp($a, $b);
            });
            $byKey[$key] = $ids;
        }

        ksort($byKey);

        return new self($byKey, $detectors, $surface, $digest);
    }

    /** @param array<string, mixed> $detector */
    public static function specificity(array $detector): int
    {
        $score = 0;
        if ($detector['path_pattern'] !== null) {
            // A longer, more-anchored pattern constrains more; the length term
            // is bounded so a verbose pattern can't outrank a structural one.
            $score += 100 + min(40, (int) (strlen($detector['path_pattern']) / 4));
        }
        if ($detector['subdomain_pattern'] !== null) {
            $score += 60;
        }
        $score += 25 * count($detector['query_requires']);
        if ($detector['fingerprint'] !== null) {
            $score += 50;
        }
        if ($detector['identifier_capture'] !== null) {
            $score += 15;
        }
        $score += count($detector['reject_patterns']) * 5;

        return $score;
    }

    /** @return list<string> detector ids to try for this key, in order */
    public function candidatesFor(string $registrableKey): array
    {
        return $this->byRegistrableKey[$registrableKey] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function detector(string $id): ?array
    {
        return $this->detectors[$id] ?? null;
    }

    public function surfaceFor(string $detectorId): ?string
    {
        return $this->detectorSurface[$detectorId] ?? null;
    }

    /** @return array{by_registrable_key: array<string, list<string>>, detector_surface: array<string, string>} */
    public function toArtefact(): array
    {
        return [
            'by_registrable_key' => $this->byRegistrableKey,
            'detector_surface' => $this->detectorSurface,
        ];
    }
}
