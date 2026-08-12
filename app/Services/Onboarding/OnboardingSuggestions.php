<?php

namespace App\Services\Onboarding;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\AccountCapabilitySet;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\Profile\SectorProvenance;

/**
 * Server-computed payload for the post-claim signup setup steps (signup-v2
 * phases E/F). ONE endpoint drives both account types: the flags say which
 * steps to render, `suggestions` is the ≤2 sector-based integration asks —
 * already filtered to platforms the account does NOT have connected and to
 * capabilities the account actually carries (the capability filter IS the
 * partna/business divergence: e.g. food sectors list reservations +
 * online-ordering + booking, and can_use_* narrows that to
 * reservations+ordering for a food business but booking for a partna account).
 *
 * The per-sector table is the owner-approved item-14 mapping
 * (docs/superpowers/plans/2026-07-23-signup-v2-execution.md). Eventbrite +
 * Humanitix are deliberately collapsed into ONE 'events' suggestion — the
 * dashboard's POST /platforms/events/add smart-detects either platform from a
 * pasted URL, so two separate cards would just duplicate the same input.
 */
class OnboardingSuggestions
{
    /**
     * D6 — sectors whose owners plausibly work AT a venue that isn't their own
     * site identity (owner-approved list). Partna-only: business accounts
     * auto-seed a workplace from their Google Business connect.
     *
     * @var list<string>
     */
    public const WORKPLACE_SECTORS = [
        // Beauty & Personal Care (all)
        'hair-salon', 'barber', 'nail-technician', 'makeup-artist', 'esthetician',
        'spa', 'tattoo-artist', 'brows-lashes',
        // Health & Fitness (all)
        'personal-trainer', 'gym', 'yoga-instructor', 'nutritionist',
        'physiotherapist', 'chiropractor', 'therapist', 'dentist',
        // Professional Services (employed-professional subset)
        'accountant', 'lawyer', 'financial-advisor', 'real-estate-agent',
        'insurance-broker', 'mortgage-broker',
        // Hospitality
        'bartender',
        // Education & Coaching (venue-taught subset)
        'music-teacher', 'driving-instructor', 'dance-instructor',
    ];

    /**
     * Sector slug → ordered suggestion keys (item-14 table). Filtered by
     * capability + already-connected before the ≤2 cap is applied, so listing
     * more than two here is deliberate where capabilities split the audience.
     *
     * @var array<string, list<string>>
     */
    private const SECTOR_SUGGESTIONS = [
        // Food & Drink — capability filter resolves the type split: business
        // food → reservations + online-ordering; partna → booking.
        'restaurant' => ['reservations', 'online-ordering', 'booking'],
        'cafe' => ['reservations', 'online-ordering', 'booking'],
        'bakery' => ['reservations', 'online-ordering', 'booking'],
        'bar' => ['reservations', 'online-ordering', 'booking'],
        'food-truck' => ['online-ordering', 'booking', 'reservations'],
        'caterer' => ['online-ordering', 'booking', 'reservations'],
        'personal-chef' => ['booking', 'online-ordering', 'reservations'],

        // Beauty & Personal Care
        'hair-salon' => ['booking'], 'barber' => ['booking'],
        'nail-technician' => ['booking'], 'esthetician' => ['booking'],
        'spa' => ['booking'], 'brows-lashes' => ['booking'],
        'makeup-artist' => ['booking'],
        'tattoo-artist' => ['booking'],

        // Health & Fitness
        'personal-trainer' => ['booking', 'strava'],
        'yoga-instructor' => ['booking', 'strava'],
        'gym' => ['booking', 'strava'],
        'nutritionist' => ['booking'], 'physiotherapist' => ['booking'],
        'chiropractor' => ['booking'], 'therapist' => ['booking'],
        'dentist' => ['booking'],

        // Professional Services
        'accountant' => ['linkedin', 'booking'], 'lawyer' => ['linkedin', 'booking'],
        'financial-advisor' => ['linkedin', 'booking'], 'consultant' => ['linkedin', 'booking'],
        'real-estate-agent' => ['linkedin', 'booking'], 'insurance-broker' => ['linkedin', 'booking'],
        'mortgage-broker' => ['linkedin', 'booking'], 'marketing-agency' => ['linkedin', 'booking'],
        'it-services' => ['linkedin', 'booking'], 'virtual-assistant' => ['linkedin', 'booking'],

        // Retail & Shopping (the store step covers commerce itself).
        // Pinterest was this vertical's only suggestion until it was retired
        // (2026-07-28), which would have left all six sectors with nothing to
        // suggest and the step silently skipped. TikTok replaces it as the
        // closest available fit: these are visual, product-discovery
        // businesses, and a store LINK would be redundant with the store step
        // that already runs. This is a product judgement made to avoid a
        // silent gap — worth revisiting if a better fit is added.
        'clothing-boutique' => ['tiktok'], 'jewellery' => ['tiktok'],
        'florist' => ['tiktok'], 'gift-shop' => ['tiktok'],
        'homewares' => ['tiktok'], 'artisan-maker' => ['tiktok'],

        // Home & Trade Services
        'plumber' => ['booking'], 'electrician' => ['booking'], 'builder' => ['booking'],
        'painter' => ['booking'], 'cleaner' => ['booking'], 'landscaper' => ['booking'],
        'handyman' => ['booking'], 'removalist' => ['booking'], 'pest-control' => ['booking'],

        // Hospitality & Events
        'event-venue' => ['events'], 'event-planner' => ['events'], 'wedding-planner' => ['events'],
        'accommodation' => ['booking', 'custom'], 'bartender' => ['booking', 'custom'],

        // Automotive
        'mechanic' => ['booking'], 'car-detailer' => ['booking'],
        'auto-electrician' => ['booking'], 'tyre-service' => ['booking'],

        // Creative & Entertainment
        'musician' => ['spotify', 'soundcloud'],
        'photographer' => ['vimeo'], 'videographer' => ['vimeo'],
        'graphic-designer' => ['custom'], 'artist' => ['custom'],
        'content-creator' => ['youtube', 'tiktok'],
        'writer' => ['x', 'custom'],

        // Education & Coaching
        'tutor' => ['booking', 'youtube'], 'life-coach' => ['booking', 'youtube'],
        'music-teacher' => ['booking', 'youtube'], 'driving-instructor' => ['booking', 'youtube'],
        'dance-instructor' => ['booking', 'youtube'], 'course-creator' => ['booking', 'youtube'],

        // Other — no sensible default; the step is skipped entirely.
        'other' => [],
    ];

    /** Suggestion key → display label. */
    private const LABELS = [
        'booking' => 'Booking', 'reservations' => 'Reservations',
        'online-ordering' => 'Online ordering', 'events' => 'Event tickets',
        'linkedin' => 'LinkedIn', 'strava' => 'Strava',
        'spotify' => 'Spotify', 'soundcloud' => 'SoundCloud', 'vimeo' => 'Vimeo',
        'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'x' => 'X',
        'custom' => 'Custom link',
    ];

    /**
     * Suggestion key → the platform rows that count as "already connected".
     * 'custom' is deliberately absent — a custom-link ask is generic and never
     * blocked by existing links.
     *
     * @var array<string, list<string>>
     */
    private const CONNECTED_FAMILIES = [
        'booking' => ['fresha', 'square', 'booking'],
        'reservations' => ['opentable', 'resdiary', 'nowbookit', 'reservations'],
        'online-ordering' => ['online-ordering'],
        'events' => ['eventbrite', 'humanitix', 'events-custom'],
        'linkedin' => ['linkedin'], 'strava' => ['strava'],
        'spotify' => ['spotify'], 'soundcloud' => ['soundcloud'], 'vimeo' => ['vimeo'],
        'youtube' => ['youtube'], 'tiktok' => ['tiktok'], 'x' => ['x'],
    ];

    private const MAX_SUGGESTIONS = 2;

    public function __construct(private readonly WebsiteLinkHarvester $harvester) {}

    /** @return array{sector: ?string, askSector: bool, askStore: bool, askWorkplace: bool, askInstagram: bool, suggestions: list<array{key: string, label: string, prefillUrl: ?string}>} */
    public function for(User $user): array
    {
        $caps = AccountCapabilities::for($user);
        // The one sanctioned account-type discriminator (never raw account_type).
        $isBusiness = $caps->google_business_full_sync;
        $sector = is_string($user->sector) && $user->sector !== '' ? $user->sector : null;

        $connected = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('platform')
            ->unique()
            ->flip()
            ->all();

        $hasWorkplace = $user->site !== null && Workplace::query()
            ->where('site_id', $user->site->id)
            ->exists();

        // The Instagram scan's unmatched links, read once — payload goes
        // through the model cast (value('payload') would return raw JSON).
        $igPayload = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', 'instagram')
            ->first()?->payload;
        $unmatched = is_array($igPayload['unmatched'] ?? null) ? $igPayload['unmatched'] : [];

        $suggestions = [];
        foreach (self::SECTOR_SUGGESTIONS[$sector] ?? [] as $key) {
            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
            if (! $this->capabilityAllows($key, $caps)) {
                continue;
            }
            $family = self::CONNECTED_FAMILIES[$key] ?? [];
            if ($family !== [] && array_intersect_key(array_flip($family), $connected) !== []) {
                continue;
            }
            $suggestions[] = [
                'key' => $key,
                'label' => self::LABELS[$key],
                'prefillUrl' => $this->prefillFor($key, $unmatched),
            ];
        }

        return [
            'sector' => $sector,
            // Ask until a HUMAN picks one. Gating on `$sector === null` meant a
            // scraped guess silently removed the only prompt that lets the user
            // correct it. Business included: it is the one type where sector
            // gates capabilities, and it can acquire an Instagram guess via
            // GoogleBusinessAutoSync::dispatchInstagram.
            'askSector' => $user->sector_source !== SectorProvenance::MANUAL,
            'askStore' => ! $isBusiness,
            'askWorkplace' => ! $isBusiness
                && $sector !== null
                && in_array($sector, self::WORKPLACE_SECTORS, true)
                && ! $hasWorkplace,
            'askInstagram' => $isBusiness && ! isset($connected['instagram']),
            'suggestions' => $suggestions,
        ];
    }

    /** The sector-gating LAW: gated categories consult AccountCapabilities, never type. */
    private function capabilityAllows(string $key, AccountCapabilitySet $caps): bool
    {
        return match ($key) {
            'booking' => $caps->can_use_booking,
            'reservations' => $caps->can_use_reservations,
            'online-ordering' => $caps->can_use_online_ordering,
            default => true,
        };
    }

    /**
     * A URL already sitting in the Instagram scan's `unmatched` suggestions
     * that classifies to this suggestion's platform family — pre-fills the
     * card so the user confirms instead of typing.
     *
     * @param  list<mixed>  $unmatched
     */
    private function prefillFor(string $key, array $unmatched): ?string
    {
        foreach ($unmatched as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            if (! is_string($url)) {
                continue;
            }
            $classified = $this->harvester->classify($url);
            if ($classified === null) {
                continue;
            }
            $matches = match ($key) {
                'booking' => $classified['category'] === 'booking',
                'reservations' => $classified['category'] === 'reservations',
                'online-ordering' => $classified['category'] === 'online-ordering',
                'events' => in_array($classified['category'], ['event', 'event-organiser'], true),
                default => $classified['platform'] === $key,
            };
            if ($matches) {
                return $url;
            }
        }

        return null;
    }
}
