<?php

namespace App\Site\Actions;

use App\Models\Core\Site\Site;

/**
 * The owner's ordering preferences, read from site.sites.settings:
 *
 *   actions    = { mode: newest|smart|manual, slots: [{position, id}] }
 *   pool_order = { <pool>: newest|smart|manual }      (sparse; absent = newest)
 *
 * In smart/newest, `slots` are LOCKS (sparse positions the ranking fills
 * around); in manual they ARE the list. Spec §4.
 */
final class ActionSettings
{
    public const MODES = ['newest', 'smart', 'manual'];

    public const DEFAULT_MODE = 'newest';

    /** Pools that accept a mode — events is always soonest-first, reviews never ranks. */
    public const POOL_ORDER_KEYS = ['watch', 'listen', 'media', 'services', 'shop', 'custom_links', 'menus'];

    /**
     * @param  list<array{position: int, id: string}>  $slots  sorted by position
     * @param  array<string, string>  $poolModes
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $slots,
        private readonly array $poolModes,
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

        return new self($mode, $slots, self::poolModes($site));
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
        return $this->poolModes[$pool] ?? self::DEFAULT_MODE;
    }
}
