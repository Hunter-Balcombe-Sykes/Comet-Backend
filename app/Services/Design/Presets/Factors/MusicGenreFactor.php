<?php

namespace App\Services\Design\Presets\Factors;

use App\Services\Design\Presets\EvidenceFactor;
use App\Services\Design\Presets\FactorMode;
use App\Services\Design\Presets\IdentityEvidence;

/**
 * Category-refiner factor (band 34, OneShot): for a music professional, the GENRE
 * of their work is a strong style signal — electronic reads bold/dark/sharp,
 * acoustic reads warm/soft/serif. It reads a genre string via
 * IdentityEvidence::musicGenre().
 *
 * CURRENT STATE (spec §2 row 3): the music platforms (Spotify, Apple Music,
 * SoundCloud, Bandcamp, Deezer, …) store an oEmbed-shaped payload that carries NO
 * genre today (verified). musicGenre() therefore returns null and this factor
 * ABSTAINS. The lexicon below is complete so that if a future payload enrichment
 * adds a genre, the factor lights up unchanged. Until then it is a provable no-op.
 *
 * Genre lexicon → look nudge:
 *   electronic / hip-hop / techno / trap → bold, dark, sharp, fast.
 *   acoustic / folk / singer-songwriter  → warm, soft, serif, slow.
 *   classical / jazz / ambient           → editorial, elegant, restrained.
 *   pop / indie                          → neutral-bright.
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
            'color_bg' => '#151515',            // dark
            'border_radius' => '0.25rem',       // sharp
            'weight_regular' => '600',          // chunky
            'motion_pace' => 'fast',
            'effect_style' => 'bold',
            'effect_shadow_style' => 'hard',
        ],
        self::ACOUSTIC => [
            'color_bg' => '#faf6f7',            // soft pastel ground
            'border_radius' => '1.5rem',        // very rounded
            'weight_regular' => '300',          // light
            'typography_font_family' => 'eb-garamond',
            'motion_pace' => 'slow',
            'effect_shadow_style' => 'soft',
            'effect_image_treatment' => 'warm',
        ],
        self::REFINED => [
            'color_bg' => '#151515',            // dark, gallery-like
            'weight_regular' => '300',          // light
            'typography_font_family' => 'young-serif',
            'motion_pace' => 'slow',
            'effect_style' => 'editorial',
            'effect_shadow_style' => 'flat',
            'effect_image_treatment' => 'duotone',
        ],
        self::POP => [
            'color_bg' => '#fafafa',            // neutral bright
            'weight_regular' => '500',          // medium
            'motion_pace' => 'normal',
            'effect_style' => 'sharp',
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
