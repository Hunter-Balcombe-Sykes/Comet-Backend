<?php

namespace App\Services\Accounts;

use App\Models\Core\User\User;
use App\Services\Profile\SectorTaxonomy;

/**
 * Runtime capability registry — answers "can this account access feature X right now?"
 * Most capabilities are constant; `can_book_storewide`, the Google Business sync
 * flags, and `can_use_multipage_site` derive from `account_type` — this is the
 * single sanctioned place that reads the type; everything else gates on the
 * derived capability. (Internal staff are NOT an account type — see PartnaStaff.)
 */
final class AccountCapabilities
{
    /**
     * Per-account memoization (audit SCALE-1). WeakMap so memoized instances
     * don't pin the account alive longer than necessary.
     */
    private static ?\WeakMap $cache = null;

    public static function for(User $pro): AccountCapabilitySet
    {
        self::$cache ??= new \WeakMap;
        if (isset(self::$cache[$pro])) {
            return self::$cache[$pro];
        }

        $set = self::individualCapabilities($pro);
        self::$cache[$pro] = $set;

        return $set;
    }

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized account. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function individualCapabilities(User $pro): AccountCapabilitySet
    {
        $status = (string) ($pro->status ?? '');
        $isBusiness = $pro->isBusiness();
        // NULL sector reads as not-food — for a business that means the
        // booking-only default until an industry is picked (Settings) or
        // synced (Google); for a partna it means no Menu until the same
        // happens. See SectorTaxonomy::isFood().
        $isFood = SectorTaxonomy::isFood($pro->sector);

        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
            can_submit_feedback: true,
            can_be_reported: $status === 'active',
            receive_moderation_notifications: in_array($status, ['active'], true),
            can_book_storewide: $isBusiness,
            google_business_full_sync: $isBusiness,
            google_business_sets_display_name: $isBusiness,
            can_use_multipage_site: $isBusiness,
            can_use_lifestyle_pages: ! $isBusiness,
            // Sector-derived (2026-07-15 industry/sector gating contract — LAW,
            // do not rederive): "partna is never gated by sector, only business
            // is." Amended 2026-09-01 in ONE clause — can_use_menu — and the
            // amendment is deliberate, not a workaround.
            //
            // The law's booking half is untouched and stays type-branched,
            // because that half is genuinely about type: a venue books either
            // tables or appointments (never both — see BookingXorLockTest), a
            // person does both. Menu was never that. `$isBusiness && $isFood`
            // was written when `sector` was in practice a business-only field,
            // so the `$isBusiness &&` half was a guard against reading a column
            // partna accounts never filled; dropping it lets a food account keep
            // its Menu whatever enum it was filed under. ollies is a
            // Google-Business-sourced CAFE filed account_type=partna: it shipped
            // 105 ingested menu items in its public payload with no page to
            // render them, as did broken-oven (171) and fred-sarson (69).
            // ra33rty — the fourth partna account with a Google-sourced sector —
            // is a gym, and still gets no Menu, which is the point: the gate got
            // narrower in what it reads, not looser in what it grants.
            //
            // CORRECTION (the amending commit's premise was false, and the
            // scope of this clause depends on it). That commit justified the
            // change with "IdentitySync and InstagramIdentitySync have stamped
            // `sector` for BOTH types since". InstagramIdentitySync does.
            // IdentitySync does NOT: applySector() returns early unless
            // `workplace_brand_is_site_identity` — business only — because the
            // 2026-08-19 identity plan (decision 12) holds that a partna's
            // industry must not be set by where they WORK. So the Google path
            // never stamps a partna's sector, and the next Google-sourced partna
            // cafe arrives here with sector NULL, reads as not-food, and is
            // refused the Menu its listing plainly has. ollies is fixed by the
            // sector it already carries, not by anything that will stamp the
            // next one. Do not read this clause as "the class is closed" — it is
            // open at the Instagram-or-manual boundary, and closing it means
            // either revisiting decision 12 or giving the Google path a partna
            // sector source of its own. What it must NOT mean is a render-time
            // veto making up the difference: SitepageDataResolverService
            // ::presentPageIds carries the note on why the page a menu already
            // exists for is not this capability's to withdraw.
            can_use_menu: $isFood,
            can_use_reservations: $isBusiness ? $isFood : true,
            can_use_booking: $isBusiness ? ! $isFood : true,
            // Online ordering deliberately did NOT move with Menu: it is a
            // transactional surface the owner scoped to businesses outright
            // (AccountCapabilitySet), not a food-ness question wearing an enum.
            // A partna cafe gets its menu and no order button — intended.
            can_use_online_ordering: $isBusiness && $isFood,
            // Pre-account auto-sync gate (2026-07-25): previously gated on
            // !isUnclaimed() (DISC-7 consent). Removed — unclaimed users now
            // receive the same auto-sync as claimed users. Kept as an always-true
            // flag rather than deleted so it remains a one-line kill switch if the
            // consent question returns before pilot.
            can_autosync_scraped_connections: true,
            // Both types curate their own duplicates — the platform has no
            // reason to let one account type merge items and not the other.
            can_curate_identity: true,
            reviews_scoped_to_person: ! $isBusiness,
            workplace_brand_is_site_identity: $isBusiness,
        );
    }
}
