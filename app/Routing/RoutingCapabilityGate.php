<?php

namespace App\Routing;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

/**
 * The one place the booking/reservations/ordering capability match-arms
 * live. PlacementPolicy (record time) and SuggestionApplier (apply time)
 * both call this instead of each carrying their own copy — see #DRIFT-1.
 */
final class RoutingCapabilityGate
{
    /**
     * Origins whose page belongs to the WORKPLACE rather than the account
     * holder.
     *
     * `website_import` is the previous-website scan: for a partna that URL is
     * `site.workplaces.website` — WorkplaceObserver dispatches the scan off
     * the workplace row, so the page being read is the salon's.
     *
     * `google_business` is the venue's LISTING. A partna never connects a
     * Google listing as their own identity; the one attached to them is the
     * workplace's, put there by FreshaWorkplaceLinker / LinkFreshaVenueToGoogleJob.
     * The platform already takes this position elsewhere — IdentitySync::applySector
     * refuses to set a partna's industry from where they work (identity plan
     * decision 12, 2026-08-19).
     *
     * Both entries are inert for a business account, whose
     * workplace_brand_is_site_identity is true: the website and the listing
     * really are its own.
     *
     * @var list<string>
     */
    private const WORKPLACE_SOURCED_ORIGINS = ['website_import', 'google_business'];

    /**
     * Classes that assert WHO SOMEONE IS — an ACCOUNT. A profile or channel
     * found on the venue's own page names the venue, or (on a staff page) a
     * colleague, never the account holder. The account holder has their own:
     * a partna signs up with their Instagram, and the rest of their socials
     * arrive from their own bio, not from where they work.
     *
     * `content` sits here with `social` because its surfaces are accounts too
     * — youtube.channel, spotify.player, soundcloud.player, vimeo.account.
     * Splitting them would have left the shop's YouTube on a barber's page
     * while its Instagram was refused, which is the same claim in a different
     * vocabulary (jaidenacallar carried @sondermens on both).
     *
     * The ACTION classes are deliberately absent, and that is the whole line:
     * an account says who you are, an action link says how to reach you. A
     * barber really does book through the shop's Fresha, so booking,
     * reservations, ordering, events and shop keep routing off the workplace
     * website exactly as before.
     *
     * @var list<string>
     */
    private const FOREIGN_IDENTITY_CLASSES = ['social', 'content'];

    /** The sanctioned capability read for a routing_class — never a raw account_type branch. */
    public static function denialFor(User $user, string $routingClass): ?string
    {
        $capabilities = AccountCapabilities::for($user);

        return match ($routingClass) {
            'booking' => $capabilities->can_use_booking ? null : 'booking is not available for this account',
            'reservations' => $capabilities->can_use_reservations ? null : 'reservations are not available for this account',
            'ordering' => $capabilities->can_use_online_ordering ? null : 'online ordering is not available for this account',
            default => null,
        };
    }

    /**
     * "This page is not this person's, so the identities on it are not theirs
     * either." Returns the refusal reason, or null when the link may route.
     *
     * Extends the 2026-08-19 owner ruling that already keeps a partna's
     * workplace website from supplying DESIGN evidence
     * (ScanPreviousWebsiteContentJob's design-evidence gate: "a partna
     * account's workplace website is someone else's brand") from brand to
     * identity. Without it the venue's Instagram was offered as a Swap against
     * the user's own, and — wherever the single social slot happened to be
     * free — the venue's TikTok/Facebook/LinkedIn was applied outright, since
     * the 2026-08-18 harvest-maximisation ruling auto-applies the suggest band
     * on every indirect origin.
     *
     * Origin-scoped on purpose: this refuses a HARVEST, not a platform. A
     * partna pasting that same Instagram is stating a fact about themselves
     * and still routes — the same manual-vs-automatic line PreviousWebsiteGate
     * draws.
     */
    public static function foreignIdentityDenial(User $user, string $routingClass, string $origin): ?string
    {
        if (! in_array($origin, self::WORKPLACE_SOURCED_ORIGINS, true)
            || ! in_array($routingClass, self::FOREIGN_IDENTITY_CLASSES, true)
            || AccountCapabilities::for($user)->workplace_brand_is_site_identity
        ) {
            return null;
        }

        return 'workplace_not_identity';
    }
}
