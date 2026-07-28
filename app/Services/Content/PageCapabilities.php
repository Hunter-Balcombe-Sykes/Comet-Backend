<?php

namespace App\Services\Content;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

/**
 * The closed registry behind `site.pages.capability` (plan §7).
 *
 * Capability enforcement moved to WRITE time when the read-time filter died
 * with SitepageId. That is the whole reason this class exists: a page an
 * account may not have must fail to be CREATED. A page that exists but is
 * silently dropped at render is the failure mode that produced "my Menu page
 * disappeared and nothing told me why".
 *
 * Item kinds are gated the same way, because a rule saying `kind_is menu_item`
 * is a menu page wearing a different hat.
 */
final class PageCapabilities
{
    /** page capability key => the AccountCapabilitySet property that grants it. */
    private const CAPABILITIES = [
        'menu' => 'can_use_menu',
        'reservations' => 'can_use_reservations',
        'booking' => 'can_use_booking',
        'online_ordering' => 'can_use_online_ordering',
        'lifestyle' => 'can_use_lifestyle_pages',
        'multipage' => 'can_use_multipage_site',
    ];

    /**
     * Item kinds that are only available behind a capability. Everything not
     * listed is ungated — the default is "allowed", so a new kind cannot
     * accidentally become unreachable by being forgotten here.
     *
     * @var array<string, string>
     */
    private const GATED_KINDS = [
        'menu_item' => 'menu',
    ];

    /**
     * Page addresses that must never be minted: they would collide with a real
     * route on the public site or with the platform's own reserved paths.
     *
     * @var list<string>
     */
    private const RESERVED_KEYS = [
        'api', 'admin', 'assets', 'static', 'public', 'internal',
        'sitemap', 'robots', 'favicon', 'well-known', 'claim', 'login',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CAPABILITIES);
    }

    /** @return list<string> */
    public static function reservedKeys(): array
    {
        return self::RESERVED_KEYS;
    }

    /** May this account have a page carrying $capability? A null capability is ungated. */
    public static function allows(User $user, ?string $capability): bool
    {
        if ($capability === null || $capability === '') {
            return true;
        }

        $property = self::CAPABILITIES[$capability] ?? null;

        // An unknown capability fails CLOSED. The form request already rejects
        // anything outside the registry, so reaching here means the registry
        // shrank under a stored row — and guessing "allowed" would silently
        // resurrect a withdrawn feature.
        if ($property === null) {
            return false;
        }

        return (bool) AccountCapabilities::for($user)->{$property};
    }

    /**
     * The first kind in a rule this account may not use, or null when every
     * kind is available to them.
     *
     * @param  array<string, mixed>  $rule
     */
    public static function deniedKindIn(User $user, array $rule): ?string
    {
        foreach ((array) ($rule['all'] ?? []) as $predicate) {
            if (($predicate['op'] ?? null) !== 'kind_is') {
                continue;
            }

            // A negated kind predicate EXCLUDES that kind, so it can never
            // grant access to it — no gate needed.
            if ((bool) ($predicate['not'] ?? false)) {
                continue;
            }

            foreach ((array) ($predicate['values'] ?? []) as $kind) {
                $capability = self::GATED_KINDS[(string) $kind] ?? null;
                if ($capability !== null && ! self::allows($user, $capability)) {
                    return (string) $kind;
                }
            }
        }

        return null;
    }
}
