<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Declared factor (band E, 64): an AESTHETIC EXPRESSION lean — soft ↔ neutral ↔
 * bold — inferred from the account's OWN presentation, and mapped to a design
 * recipe. This is the "expression" reading of a user's self-presentation; it is
 * NOT a demographic inference.
 *
 * SAFETY CONTRACT (spec §9 — enforced, not aspirational):
 *   • This factor NEVER computes, names, or stores a gender. There is no gender
 *     variable anywhere in this class. The only thing it reasons about is an
 *     aesthetic direction (soft / neutral / bold), and the only thing it
 *     persists is that direction's design recipe (real design_kit column values
 *     via the contribution ledger). The evidence — a couple of text signals —
 *     is read, scored, and discarded within detect(); nothing about the signals
 *     is written anywhere.
 *   • CONFIDENCE-GATED: it abstains (returns []) unless at least two INDEPENDENT
 *     signals CONCUR on the same non-neutral direction. One signal, or two
 *     signals that disagree, is not enough — a weak read must degrade to "no
 *     contribution", never to a guess. Neutral likewise needs two concordant
 *     neutral reads; a single signal never concludes anything.
 *
 * Signals (both derived from the stored Instagram payload — no network, no
 * pixels, fully unit-testable):
 *   1. business-category lean  — soft/bold lexicon over the IG business category
 *   2. display-name lean       — soft/bold lexicon over the IG full name
 * (The own-media palette-softness signal the spec also lists belongs with the
 * own-media pixel job and is deferred to that extension — kept out of here so
 * this sensitive inference stays minimal and auditable.)
 *
 * One-shot: an identity-stable lean; churning it would make a site feel unstable.
 */
class AestheticExpressionFactor implements EvidenceFactor
{
    public const SOURCE = 'aesthetic-expression:lean';

    private const SOFT = 'soft';

    private const NEUTRAL = 'neutral';

    private const BOLD = 'bold';

    /** Concordant signals required to conclude a direction. */
    private const MIN_CONCORDANT = 2;

    /** Substrings that lean an aesthetic soft (rounded, gentle, delicate presentation). */
    private const SOFT_LEXICON = [
        'beauty', 'cosmetic', 'skincare', 'skin care', 'lash', 'brow', 'nail',
        'floral', 'flower', 'bloom', 'pastel', 'soft', 'gentle', 'delicate',
        'dreamy', 'boutique', 'bridal', 'wellness', 'yoga', 'spa', 'glow',
        'petal', 'blush', 'aesthetic', 'elegant',
    ];

    /** Substrings that lean an aesthetic bold (high-contrast, chunky, hard-edged presentation). */
    private const BOLD_LEXICON = [
        'fitness', 'gym', 'strength', 'iron', 'barber', 'tattoo', 'ink',
        'street', 'streetwear', 'bold', 'raw', 'grit', 'power', 'beast',
        'combat', 'boxing', 'mma', 'crossfit', 'automotive', 'garage',
        'moto', 'forge', 'heavy', 'hardcore',
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'aesthetic-expression';
    }

    public function mode(): FactorMode
    {
        return FactorMode::OneShot;
    }

    public function priority(): int
    {
        // Band E (declared) — a self-presentation reading sits with declared
        // identity: above category/refiner factors, below own-site evidence.
        return 64;
    }

    /**
     * Each concludeable direction's design recipe (spec §2 row 8). Only SOFT and
     * BOLD produce a recipe — NEUTRAL is an ABSTAIN, not a treatment: "no lean"
     * must leave the axes to lower bands (category etc.), never stamp generic
     * values over them from this high (band E) factor. Neutral votes still count
     * toward BLOCKING a soft/bold conclusion (a soft+neutral split fails the ≥2
     * concordance bar), which is exactly the conservative behaviour we want.
     */
    private const DIRECTION_TARGETS = [
        self::SOFT => [
            'color_bg' => '#faf6f7',        // pastel-tinted
            'border_radius' => '1.5rem',    // very rounded
            'weight_regular' => '300',      // light
            'motion_pace' => 'slow',
            'motion_entrance' => 'fade',
            'effect_shadow_style' => 'soft',
        ],
        self::BOLD => [
            'color_bg' => '#ffffff',        // high-contrast ground
            'border_radius' => '0.25rem',   // sharp
            'weight_regular' => '600',      // chunky
            'motion_pace' => 'fast',
            'effect_shadow_style' => 'hard',
            'effect_style' => 'bold',
        ],
    ];

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $payload = $evidence->instagramPayload();
        if ($payload === null) {
            return []; // no Instagram to read a presentation from
        }

        // Each signal votes an aesthetic lean (soft / bold / neutral) or nothing.
        // We keep the votes as an aesthetic-direction tally ONLY — never anything
        // demographic; only a concordant soft/bold read ever yields a recipe.
        $votes = [];
        foreach ([
            $this->leanOf(is_string($payload['businessCategory'] ?? null) ? $payload['businessCategory'] : ''),
            $this->leanOf(is_string($payload['fullName'] ?? null) ? $payload['fullName'] : ''),
        ] as $vote) {
            if ($vote !== null) {
                $votes[] = $vote;
            }
        }

        $direction = $this->concordantDirection($votes);

        return $direction === null ? [] : self::DIRECTION_TARGETS[$direction];
    }

    /**
     * The aesthetic lean of a text: soft/bold if its lexicon clearly dominates,
     * neutral if the text is present but leans neither way, null if empty. Ties
     * (equal soft and bold hits) resolve to neutral — the text is mixed, not
     * confidently either.
     */
    private function leanOf(string $text): ?string
    {
        $lower = strtolower(trim($text));
        if ($lower === '') {
            return null;
        }

        $soft = $this->hits($lower, self::SOFT_LEXICON);
        $bold = $this->hits($lower, self::BOLD_LEXICON);

        if ($soft > $bold) {
            return self::SOFT;
        }
        if ($bold > $soft) {
            return self::BOLD;
        }

        return self::NEUTRAL;
    }

    /** Count of lexicon substrings present in $text. */
    private function hits(string $text, array $lexicon): int
    {
        $n = 0;
        foreach ($lexicon as $term) {
            if (str_contains($text, $term)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The concludeable direction (SOFT or BOLD) with ≥ MIN_CONCORDANT votes and
     * no equal-strength opposing direction, or null (abstain). This is the
     * confidence gate: it takes at least two signals AGREEING on a NON-neutral
     * lean to conclude anything. A neutral plurality is an abstain — "no opinion"
     * must not stamp a recipe from this high band.
     *
     * @param  list<string>  $votes
     */
    private function concordantDirection(array $votes): ?string
    {
        if (count($votes) < self::MIN_CONCORDANT) {
            return null;
        }

        $counts = array_count_values($votes);
        arsort($counts);
        $ordered = array_values($counts);

        // Top direction must clear the concordance bar and not be tied with the
        // runner-up (disagreement = no confident conclusion).
        if ($ordered[0] < self::MIN_CONCORDANT) {
            return null;
        }
        if (count($ordered) > 1 && $ordered[0] === $ordered[1]) {
            return null;
        }

        $winner = (string) array_key_first($counts);

        // NEUTRAL is not a treatment — a confident "no lean" still abstains.
        return $winner === self::NEUTRAL ? null : $winner;
    }
}
