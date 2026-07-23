<?php

namespace App\Services\PublicSite\Actions;

/**
 * The fixed action vocabulary — the ONLY things that can ever appear in the
 * unified actions system (replaces the old open-ended page|item|button pool).
 * 24 static ids + 2 dynamic families (`ordering:<resource_id>`, `custom:<key>`).
 *
 * LOCKSTEP with apps/pages test/actions-vocabulary.test.ts' ACTION_IDS export
 * — change both together or the wire and the frontend resolver drift.
 *
 * Design spec: docs/superpowers/plans/2026-07-23-actions-rebuild-demand-rate.md
 */
final class ActionVocabulary
{
    /**
     * Canonical order — also the cold-start display order when every action
     * ties on prior (impossible in practice since priors differ, but keeps a
     * stable tiebreak deterministic).
     *
     * @var list<string>
     */
    public const STATIC_IDS = [
        'reservations', 'shop', 'shop-tracks', 'events', 'booking-services', 'menu', 'contact',
        'spotify', 'soundcloud', 'apple-music', 'apple-podcasts', 'twitch',
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x',
        'snapchat', 'pinterest', 'threads', 'discord', 'reddit', 'telegram',
    ];

    /** @var list<string> */
    public const FAMILIES = ['ordering', 'custom'];

    /** @var array<string, string> */
    private const LABELS = [
        'reservations' => 'Reservations',
        'shop' => 'Shop',
        'shop-tracks' => 'Shop tracks',
        'events' => 'Events',
        'booking-services' => 'Booking and services',
        'menu' => 'Menu',
        'contact' => 'Contact',
        'spotify' => 'Spotify',
        'soundcloud' => 'SoundCloud',
        'apple-music' => 'Apple Music',
        'apple-podcasts' => 'Apple Podcasts',
        'twitch' => 'Twitch',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'x' => 'X',
        'snapchat' => 'Snapchat',
        'pinterest' => 'Pinterest',
        'threads' => 'Threads',
        'discord' => 'Discord',
        'reddit' => 'Reddit',
        'telegram' => 'Telegram',
    ];

    /** A family key's local part: letters/digits/underscore/dot/colon/slash/hyphen, 1..160 chars. */
    private const FAMILY_KEY_PATTERN = '/^[A-Za-z0-9_.:\/-]{1,160}$/';

    public static function isValidId(string $id): bool
    {
        if (in_array($id, self::STATIC_IDS, true)) {
            return true;
        }

        $parts = explode(':', $id, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$family, $key] = $parts;

        return in_array($family, self::FAMILIES, true)
            && $key !== ''
            && preg_match(self::FAMILY_KEY_PATTERN, $key) === 1;
    }

    public static function labelFor(string $staticId): string
    {
        return self::LABELS[$staticId] ?? $staticId;
    }

    /**
     * Prior CTR for an action id — exact static id, else its family prefix
     * (`ordering`/`custom`), else the configured default. Config-driven so
     * priors are tunable without a deploy of this class.
     */
    public static function priorFor(string $id): float
    {
        $priors = (array) config('partna.actions.priors', []);
        $default = (float) config('partna.actions.default_prior', 0.05);

        if (isset($priors[$id])) {
            return (float) $priors[$id];
        }

        $family = strstr($id, ':', true);
        if ($family !== false && isset($priors[$family])) {
            return (float) $priors[$family];
        }

        return $default;
    }
}
