<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Category-refiner factor (band 34, OneShot): for a music professional, the GENRE
 * of their work is a strong style signal — electronic reads bold/heavy/sharp,
 * acoustic reads warm/soft/serif. It reads a genre string via
 * IdentityEvidence::musicGenre() (stored by the Apple Music connect since #76
 * Part B; other music platforms' oEmbed payloads still carry no genre and
 * abstain).
 *
 * Genre lexicon → look nudge (backgrounds are theme_mode-owned since the
 * 2026-07-10 rework — the old dark grounds now read through weight/surface):
 *   electronic / hip-hop / techno / trap → heavy, square, fast, solid, brutalist type.
 *   acoustic / folk / singer-songwriter  → warm, soft, serif, slow.
 *   classical / jazz / ambient           → editorial, elegant, restrained outline.
 *   pop / indie                          → clean medium-weight outline.
 * An unrecognised genre → ABSTAIN (never a guess).
 *
 * OneShot: an artist's genre is identity-stable. Between the category band —
 * above InstagramCategory (30), below GoogleBusinessType (40).
 */
class MusicGenreFactor implements EvidenceFactor
{
    public const SOURCE = 'music-genre:style';

    private const ELECTRONIC = 'electronic';

    private const ACOUSTIC = 'acoustic';

    private const REFINED = 'refined';

    private const POP = 'pop';

    /** Ordered genre keyword => style family. First substring match wins. */
    private const GENRE_FAMILIES = [
        'electronic' => self::ELECTRONIC,
        'techno' => self::ELECTRONIC,
        'house' => self::ELECTRONIC,
        'edm' => self::ELECTRONIC,
        'dubstep' => self::ELECTRONIC,
        'trap' => self::ELECTRONIC,
        'hip hop' => self::ELECTRONIC,
        'hip-hop' => self::ELECTRONIC,
        'hiphop' => self::ELECTRONIC,
        'rap' => self::ELECTRONIC,
        'drum and bass' => self::ELECTRONIC,
        'dnb' => self::ELECTRONIC,

        'acoustic' => self::ACOUSTIC,
        'folk' => self::ACOUSTIC,
        'singer-songwriter' => self::ACOUSTIC,
        'singer/songwriter' => self::ACOUSTIC,
        'country' => self::ACOUSTIC,
        'americana' => self::ACOUSTIC,
        'bluegrass' => self::ACOUSTIC,

        'classical' => self::REFINED,
        'jazz' => self::REFINED,
        'ambient' => self::REFINED,
        'orchestral' => self::REFINED,
        'piano' => self::REFINED,
        'soul' => self::REFINED,

        'pop' => self::POP,
        'indie' => self::POP,
        'rock' => self::POP,
        'alternative' => self::POP,
    ];

    /** Style family → sparse overlay. Values from the established vocabulary. */
    private const FAMILY_TARGETS = [
        self::ELECTRONIC => [
            'border_radius' => '0',             // square — club-flyer geometry; reglo is a brutalist face and the genre is a declared fact, not an inference
            'weight_regular' => '600',          // chunky
            // reglo IS the DJ/producer face (knowledge base §2) — with the dark
            // ground gone this is the family's identity anchor.
            'typography_font_family' => 'reglo',
            'motion_pace' => 'fast',
            'effect_surface' => 'solid',        // club-poster blocks
            'effect_shadow_style' => 'hard',
        ],
        self::ACOUSTIC => [
            'border_radius' => '1.5rem',        // very rounded
            'weight_regular' => '300',          // light
            'typography_font_family' => 'origin',
            'motion_pace' => 'slow',
            'effect_shadow_style' => 'soft',
            'effect_image_treatment' => 'warm',
        ],
        self::REFINED => [
            'weight_regular' => '300',          // light
            'typography_font_family' => 'young-serif',
            'motion_pace' => 'slow',
            'effect_surface' => 'outline',      // gallery restraint
            'effect_shadow_style' => 'flat',
            'effect_image_treatment' => 'duotone', // jazz-poster mood
        ],
        self::POP => [
            'weight_regular' => '500',          // medium
            'motion_pace' => 'normal',
            'effect_surface' => 'outline',      // clean indie-label minimalism
        ],
    ];

    public function key(): string
    {
        return self::SOURCE;
    }

    public function integrationLabel(): string
    {
        return 'music-genre';
    }

    public function mode(): FactorMode
    {
        return FactorMode::OneShot;
    }

    public function priority(): int
    {
        // Band 34 — inside the category band, above InstagramCategory (30), below
        // GoogleBusinessType (40).
        return 34;
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $genre = $evidence->musicGenre();
        if ($genre === null) {
            return []; // no genre stored (today: always) — abstain
        }

        foreach (self::GENRE_FAMILIES as $keyword => $family) {
            if (str_contains($genre, $keyword)) {
                return self::FAMILY_TARGETS[$family];
            }
        }

        return []; // unrecognised genre — abstain rather than guess
    }
}
