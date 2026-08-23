<?php

namespace App\Site\Actions;

/**
 * The action id grammar — `<kind>:<ref>`. It is both the analytics key
 * (analytics.action_events.action_id, content_popularity_scores.content_key)
 * and the settings ref (site.sites.settings.actions.slots[].id), so it must
 * survive a disconnect/reconnect: platforms key on platform slug, not
 * connection uuid; items on content.items.id.
 */
final class ActionId
{
    public const KINDS = ['page', 'platform', 'item', 'category'];

    /** Regex body without delimiters — shared with the request rules. */
    public const PATTERN = '^(page|platform|item|category):[A-Za-z0-9_.:\/-]{1,160}$';

    public static function isValid(string $id): bool
    {
        return preg_match('/'.self::PATTERN.'/', $id) === 1;
    }

    public static function kind(string $id): ?string
    {
        return self::isValid($id) ? substr($id, 0, (int) strpos($id, ':')) : null;
    }
}
