<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;
use App\Services\Design\Presets\LaunchRecipes;
use App\Services\Design\Presets\PresetTargetableColumns;
use App\Services\Design\Presets\RecipeSignals;

/**
 * Combination factor (band 70): when a strong, recognizable identity ARCHETYPE
 * is evidenced by a COMBINATION of independent signals, stamp a complete,
 * hand-tuned, coherent look in one shot — instead of letting the individual
 * per-axis factors (bands 10–64) stack into a possibly-incoherent combination
 * (spec §1). Band 70 wins those columns over the piecemeal nudges, while
 * PreviousWebsite (84) still overrides the specific brand colours it actually
 * knows, and manual overrides everything.
 *
 * SELECTION (spec §1a): evaluate every recipe's trigger against ONE RecipeSignals
 * read; each trigger returns the number of INDEPENDENT signal groups that concur.
 * Pick the single highest-scoring recipe that clears its minGroups bar. A tie for
 * top score → ABSTAIN (an ambiguous archetype must not guess). No recipe clears
 * its bar → ABSTAIN (the individual factors carry the look).
 *
 * ONE-SHOT: an archetype is identity-stable; a full-look recipe that churned would
 * make a site feel unstable. It stamps once at first strong match, then leaves the
 * user free to tweak. (The resolver freezes resolved one-shots.)
 *
 * SAFETY: values are a fixed curated set from the established vocabulary — the
 * factor computes nothing sensitive; the only inputs are business-category /
 * price / platform signals, read and discarded here. A malformed signal read
 * degrades to abstain (the resolver also wraps detect() in try/report).
 */
class LaunchRecipeFactor implements EvidenceFactor
{
    public const SOURCE = 'launch-recipe:archetype';

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'launch-recipe';
    }

    public function mode(): FactorMode
    {
        return FactorMode::OneShot;
    }

    public function priority(): int
    {
        // Band 70 — above every individual inference factor (10–64), below
        // PreviousWebsite (84). Manual design_kits values still win outright.
        return 70;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $signals = new RecipeSignals($evidence);

        // Score every recipe. Keep only those clearing their own minGroups bar.
        $scored = [];
        foreach (LaunchRecipes::all() as $recipe) {
            $score = ($recipe['trigger'])($signals);
            if ($score >= $recipe['minGroups']) {
                $scored[] = ['score' => $score, 'values' => $recipe['values']];
            }
        }

        if ($scored === []) {
            return []; // no archetype strongly evidenced — individual factors carry it
        }

        // Highest score wins; a tie for top score is an ambiguous archetype → abstain.
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        if (count($scored) > 1 && $scored[0]['score'] === $scored[1]['score']) {
            return [];
        }

        // Filter to targetable columns as a belt-and-braces guard (the resolver
        // filters too); a recipe should only ever carry whitelisted columns.
        return PresetTargetableColumns::filter($scored[0]['values']);
    }
}
