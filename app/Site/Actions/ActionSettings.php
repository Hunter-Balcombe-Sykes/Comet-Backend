<?php

namespace App\Site\Actions;

use App\Models\Core\Site\Site;

/**
 * The owner's ordering preferences, read from site.sites.settings:
 *
 *   actions    = { mode: newest|smart|manual, slots: [{position, id}] }
 *   pool_order = { <pool>: newest|smart|manual }      (sparse; absent = newest)
 *   pool_locks = { <pool>: [{position, id}] }          (newest/smart only: items
 *                                                       held at a position while
 *                                                       the mode fills the rest)
 *
 * In smart/newest, `slots` are LOCKS (sparse positions the ranking fills
 * around); in manual they ARE the list. Spec §4.
 */
final class ActionSettings
{
    public const MODES = ['newest', 'smart', 'manual'];

    /** `smart` since 2026-09-02 (owner): a fresh site's stack led with its
     *  newest custom link. Smart with no popularity data applies the
     *  cold-start prior in ActionSlots::order(); ranks take over after. */
    public const DEFAULT_MODE = 'smart';

    /**
     * D2 (2026-08-27): per-pool default overrides. `newest` is honest for
     * recency pools (watch/listen/media) but meaningless for menus/services —
     * undated items sort by INGESTION recency, which inverted St Ali's curated
     * Uber Eats menu on the live wire (scan stragglers first, the store's own
     * first section last). `smart` degrades to the curated stored-position
     * order for category blocks while no popularity data exists — every fresh
     * signup — and becomes engagement-ranked after claim. An explicit
     * `pool_order` setting always wins; this is only the absent-setting
     * default, so it applies to existing sites that never chose a mode too
     * (owner-flagged as intended).
     *
     * @var array<string, string>
     */
    public const POOL_DEFAULT_MODES = ['menus' => 'smart', 'services' => 'smart'];

    /** Pools that accept a mode — events is always soonest-first, reviews never ranks. */
    public const POOL_ORDER_KEYS = ['watch', 'listen', 'media', 'services', 'shop', 'custom_links', 'menus'];

    /** Locks per pool — enough for any real curation, small enough to stay a settings key. */
    public const POOL_LOCKS_MAX = 50;

    /**
     * @param  list<array{position: int, id: string}>  $slots  sorted by position
     * @param  array<string, string>  $poolModes
     */
    /**
     * @param  array<string, list<array{position: int, id: string}>>  $poolLocks
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $slots,
        private readonly array $poolModes,
        private readonly array $poolLocks,
    ) {}

    public static function fromSite(?Site $site): self
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $actions = is_array($settings['actions'] ?? null) ? $settings['actions'] : [];
        $mode = in_array($actions['mode'] ?? null, self::MODES, true) ? (string) $actions['mode'] : self::DEFAULT_MODE;

        $slots = [];
        foreach (is_array($actions['slots'] ?? null) ? $actions['slots'] : [] as $slot) {
            if (! is_array($slot) || ! is_int($slot['position'] ?? null) || ! is_string($slot['id'] ?? null) || ! ActionId::isValid($slot['id'])) {
                continue;
            }
            $slots[] = ['position' => $slot['position'], 'id' => $slot['id']];
        }
        usort($slots, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return new self($mode, $slots, self::poolModes($site), self::poolLocks($site));
    }

    /** @return array<string, string> sparse pool => mode */
    public static function poolModes(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $raw = is_array($settings['pool_order'] ?? null) ? $settings['pool_order'] : [];
        $out = [];
        foreach ($raw as $pool => $mode) {
            if (in_array($pool, self::POOL_ORDER_KEYS, true) && in_array($mode, self::MODES, true)) {
                $out[$pool] = $mode;
            }
        }

        return $out;
    }

    public function poolMode(string $pool): string
    {
        // Pools keep `newest` as their own default (D2): the ACTIONS default moved
        // to smart on 2026-09-02, and a recency pool must not follow it.
        return $this->poolModes[$pool]
            ?? self::POOL_DEFAULT_MODES[$pool]
            ?? 'newest';
    }

    /**
     * @return array<string, list<array{position: int, id: string}>> pool => locks sorted by position
     */
    public static function poolLocks(?Site $site): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $raw = is_array($settings['pool_locks'] ?? null) ? $settings['pool_locks'] : [];
        $out = [];
        foreach ($raw as $pool => $locks) {
            if (! in_array($pool, self::POOL_ORDER_KEYS, true) || ! is_array($locks)) {
                continue;
            }
            $clean = [];
            foreach ($locks as $lock) {
                if (! is_array($lock) || ! is_int($lock['position'] ?? null) || ! is_string($lock['id'] ?? null) || $lock['id'] === '') {
                    continue;
                }
                $clean[] = ['position' => $lock['position'], 'id' => $lock['id']];
            }
            usort($clean, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
            if ($clean !== []) {
                $out[$pool] = $clean;
            }
        }

        return $out;
    }

    /** @return list<array{position: int, id: string}> */
    public function poolLocksFor(string $pool): array
    {
        return $this->poolLocks[$pool] ?? [];
    }
}
