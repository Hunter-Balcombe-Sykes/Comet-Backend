<?php

namespace App\Services\Profile;

use App\Services\Design\SectorStylePresets;

/**
 * Curated, user-facing industry list for the profile "sector" field.
 *
 * Every entry carries a `bucket` = one of the ten SectorStylePresets bucket
 * constants, which ProfileDesignPresets reads at render time to auto-style a
 * user's sitepage (bucket base + optional per-slug refinement — see
 * SectorStylePresets). fromGoogleCategory()/fromInstagramCategory() fold a
 * connected platform's raw category text into this same curated slug
 * vocabulary, so a Google- or Instagram-sourced sector styles identically to
 * a manually-picked one.
 *
 * The list is deliberately mid-size (~70 entries) — broad enough that most
 * solo professionals find themselves, tight enough to stay a curated picker
 * rather than the full Google place-type enum.
 */
final class SectorTaxonomy
{
    /**
     * The "Food & Drink" group's slugs, exactly — the single source of truth
     * for every food-derived capability (AccountCapabilities::can_use_menu /
     * can_use_reservations / can_use_booking / can_use_online_ordering). Kept
     * as an explicit list rather than derived from SECTORS at call time so the
     * food set is a visible, reviewable contract, not an implicit side effect
     * of the picker's grouping.
     *
     * @var list<string>
     */
    public const FOOD_SECTORS = [
        'restaurant', 'cafe', 'bakery', 'bar', 'food-truck', 'caterer', 'personal-chef',
    ];

    /**
     * Values a scraper emits in place of a real category — the figue actor
     * stringifies Python's None. Never classified, and never stored: the
     * Instagram seeder reads this same list at its write seam, because
     * businessCategory is on the public wire (F4, 2026-08-10).
     *
     * @var list<string>
     */
    public const PLACEHOLDER_CATEGORIES = ['none', 'null', 'n/a', '-'];

    /**
     * Ordered sector rows. Order within a group is the display order in the
     * picker. `group` is the section header the picker renders.
     *
     * @var list<array{slug: string, label: string, group: string, bucket: string}>
     */
    private const SECTORS = [
        ['slug' => 'restaurant', 'label' => 'Restaurant', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'cafe', 'label' => 'Café / Coffee shop', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'bakery', 'label' => 'Bakery', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'bar', 'label' => 'Bar / Pub', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'food-truck', 'label' => 'Food truck / Street food', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'caterer', 'label' => 'Caterer', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],
        ['slug' => 'personal-chef', 'label' => 'Personal chef', 'group' => 'Food & Drink', 'bucket' => SectorStylePresets::FOOD_DRINK],

        ['slug' => 'hair-salon', 'label' => 'Hair salon', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'barber', 'label' => 'Barber', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'nail-technician', 'label' => 'Nail technician', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'makeup-artist', 'label' => 'Makeup artist', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'esthetician', 'label' => 'Esthetician / Skincare', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'spa', 'label' => 'Spa / Massage', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'tattoo-artist', 'label' => 'Tattoo artist', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],
        ['slug' => 'brows-lashes', 'label' => 'Brows & lashes', 'group' => 'Beauty & Personal Care', 'bucket' => SectorStylePresets::BEAUTY_PERSONAL_CARE],

        ['slug' => 'personal-trainer', 'label' => 'Personal trainer', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'gym', 'label' => 'Gym / Studio', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'yoga-instructor', 'label' => 'Yoga / Pilates instructor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'nutritionist', 'label' => 'Nutritionist / Dietitian', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'physiotherapist', 'label' => 'Physiotherapist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'chiropractor', 'label' => 'Chiropractor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'therapist', 'label' => 'Therapist / Counsellor', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'dentist', 'label' => 'Dentist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'medical-clinic', 'label' => 'Medical clinic', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'optometrist', 'label' => 'Optometrist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'veterinarian', 'label' => 'Vet / Animal hospital', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],

        ['slug' => 'accountant', 'label' => 'Accountant / Bookkeeper', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'lawyer', 'label' => 'Lawyer / Solicitor', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'financial-advisor', 'label' => 'Financial advisor', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'consultant', 'label' => 'Consultant', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'real-estate-agent', 'label' => 'Real estate agent', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'insurance-broker', 'label' => 'Insurance broker', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'mortgage-broker', 'label' => 'Mortgage broker', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'marketing-agency', 'label' => 'Marketing / PR', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'it-services', 'label' => 'IT / Tech services', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
        ['slug' => 'virtual-assistant', 'label' => 'Virtual assistant', 'group' => 'Professional Services', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],

        ['slug' => 'clothing-boutique', 'label' => 'Clothing / Fashion boutique', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'jewellery', 'label' => 'Jewellery', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'florist', 'label' => 'Florist', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'gift-shop', 'label' => 'Gift shop', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'homewares', 'label' => 'Homewares / Décor', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'artisan-maker', 'label' => 'Artisan / Handmade goods', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'retail-store', 'label' => 'Shop / Retail store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'grocer', 'label' => 'Grocer / Food store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'liquor-store', 'label' => 'Bottle shop / Liquor store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'market', 'label' => 'Market', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],

        ['slug' => 'plumber', 'label' => 'Plumber', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'electrician', 'label' => 'Electrician', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'builder', 'label' => 'Builder / Carpenter', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'painter', 'label' => 'Painter / Decorator', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'cleaner', 'label' => 'Cleaner', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'landscaper', 'label' => 'Landscaper / Gardener', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'handyman', 'label' => 'Handyman', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'removalist', 'label' => 'Removalist / Moving', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'pest-control', 'label' => 'Pest control', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'pet-services', 'label' => 'Pet grooming / Care', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'laundry', 'label' => 'Laundry / Dry cleaning', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'locksmith', 'label' => 'Locksmith', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],

        ['slug' => 'accommodation', 'label' => 'Accommodation / Stays', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'event-venue', 'label' => 'Event venue', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'event-planner', 'label' => 'Event planner', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'wedding-planner', 'label' => 'Wedding planner', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],
        ['slug' => 'bartender', 'label' => 'Bartender / Mobile bar', 'group' => 'Hospitality & Events', 'bucket' => SectorStylePresets::HOSPITALITY],

        ['slug' => 'mechanic', 'label' => 'Mechanic / Auto repair', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'car-detailer', 'label' => 'Car detailer / Wash', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'auto-electrician', 'label' => 'Auto electrician', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],
        ['slug' => 'tyre-service', 'label' => 'Tyre / Wheel service', 'group' => 'Automotive', 'bucket' => SectorStylePresets::AUTOMOTIVE],

        ['slug' => 'photographer', 'label' => 'Photographer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'videographer', 'label' => 'Videographer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'graphic-designer', 'label' => 'Graphic designer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'artist', 'label' => 'Artist / Illustrator', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'musician', 'label' => 'Musician / DJ', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'content-creator', 'label' => 'Content creator / Influencer', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'writer', 'label' => 'Writer / Copywriter', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
        ['slug' => 'museum-gallery', 'label' => 'Museum / Gallery', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],

        ['slug' => 'tutor', 'label' => 'Tutor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'life-coach', 'label' => 'Life / Business coach', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'music-teacher', 'label' => 'Music teacher', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'driving-instructor', 'label' => 'Driving instructor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'dance-instructor', 'label' => 'Dance instructor', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],
        ['slug' => 'course-creator', 'label' => 'Course creator / Educator', 'group' => 'Education & Coaching', 'bucket' => SectorStylePresets::EDUCATION_COACHING],

        // No bucket of its own — falls in with the trustworthy/neutral
        // professional-services preset so a chosen "other" never looks broken.
        ['slug' => 'other', 'label' => 'Something else', 'group' => 'Other', 'bucket' => SectorStylePresets::PROFESSIONAL_SERVICES],
    ];

    /**
     * Ordered keyword => sector-slug map for classifying a raw Google/Instagram
     * business category string. Specific-before-generic ordering discipline
     * ('barber' before 'bar', etc.) — classify() returns the FIRST substring
     * match, so a generic keyword must never precede a more specific colliding
     * one.
     *
     * @var array<string, string>
     */
    private const KEYWORD_SECTORS = [
        'barber' => 'barber',
        'hair' => 'hair-salon',
        'nail' => 'nail-technician',
        'makeup' => 'makeup-artist',
        'make-up' => 'makeup-artist',
        'spa' => 'spa',
        // 'spa' is WHOLE_WORD, so it cannot reach "Massage" — the category
        // lakshmi-thai-massage arrived with, and synced no sector from. The
        // free-text map has carried this fold since 2026-08-12; the category
        // map never did.
        'massage' => 'spa',
        'tattoo' => 'tattoo-artist',
        // M-10: "Body art service" is what body_art_service humanizes to when
        // Google marks a tattoo shop's primary type as the generic "store".
        'body art' => 'tattoo-artist',
        'piercing' => 'tattoo-artist',
        'gym' => 'gym',
        'fitness' => 'gym',
        'yoga' => 'yoga-instructor',
        'trainer' => 'personal-trainer',
        'chiropractor' => 'chiropractor',
        'dentist' => 'dentist',
        // Google files bondi-junction-dental under "Dental Clinic", which the
        // 'dentist' stem cannot reach — the account synced no sector at all
        // (cold-build audit, 2026-08-31).
        'dental' => 'dentist',
        'physio' => 'physiotherapist',
        // Stem, not 'veterinary': Google emits both "Veterinary Care" and
        // "Veterinarian", and a key that only covers one is a silent null on
        // the other.
        'veterinar' => 'veterinarian',
        'medical clinic' => 'medical-clinic',
        'photographer' => 'photographer',
        'photo' => 'photographer',
        'art gallery' => 'artist',
        'gallery' => 'artist',
        // A venue that HOSTS music is not a musician — northcote-social-club, a
        // pub with a bandroom, classified 'musician' on 2026-08-31 and was
        // handed the musician page front (listen/events/watch/shop). These MUST
        // stay above 'music'.
        'live music venue' => 'event-venue',
        'music venue' => 'event-venue',
        'concert hall' => 'event-venue',
        'music' => 'musician',
        'real estate' => 'real-estate-agent',
        'accountant' => 'accountant',
        'lawyer' => 'lawyer',
        'attorney' => 'lawyer',
        'consultant' => 'consultant',
        // Guards the 'market' key added below: 'market' is a stem, so it eats
        // "Marketing agency" whole. The specific-before-generic discipline is
        // the whole reason this key exists — there is no marketing account in
        // the 2026-08-31 audit, only a collision waiting for one.
        'marketing' => 'marketing-agency',
        'clothing' => 'clothing-boutique',
        'florist' => 'florist',
        'flower' => 'florist',
        'jewel' => 'jewellery',
        'gift shop' => 'gift-shop',
        'plumber' => 'plumber',
        'electrician' => 'electrician',
        'clean' => 'cleaner',
        'landscap' => 'landscaper',
        'hotel' => 'accommodation',
        'event venue' => 'event-venue',
        'event planner' => 'event-planner',
        'wedding' => 'wedding-planner',
        'car repair' => 'mechanic',
        'auto repair' => 'mechanic',
        'mechanic' => 'mechanic',
        'car wash' => 'car-detailer',
        'car dealer' => 'mechanic',
        'tutor' => 'tutor',
        'dance school' => 'dance-instructor',
        'dance' => 'dance-instructor',
        'driving school' => 'driving-instructor',
        'restaurant' => 'restaurant',
        'cafe' => 'cafe',
        'coffee' => 'cafe',
        'bakery' => 'bakery',
        // M-11 (B6 DOH live): "Donut Shop" classified to nothing, so a donut
        // shop synced no sector and its food capabilities stayed dark.
        'donut' => 'bakery',
        'doughnut' => 'bakery',
        // Same failure as 'donut', found the expensive way: pret-a-manger is
        // filed "Sandwich Shop" and gelato-messina-darlinghurst "Ice Cream
        // Shop". Neither classified, so neither had a sector, so isFood() was
        // false, so can_use_menu was false — a sandwich chain served the
        // BOOKING capability set and its menu OCR bailed in 14ms (2026-08-31).
        'sandwich' => 'cafe',
        'ice cream' => 'cafe',
        'food truck' => 'food-truck',
        'caterer' => 'caterer',
        // Ahead of 'bar' only for tidiness — none of the three contains it.
        // little-creatures-brewery-fremantle, chandon-australia, corner-hotel
        // and exeter-hotel all synced sector null.
        'brewery' => 'bar',
        'winery' => 'bar',
        // WHOLE_WORD: the 'pub' stem opens "PUBlic figure", the Facebook
        // category a tattooist or a musician sits in, which is pinned to null.
        'pub' => 'bar',
        'bar' => 'bar',

        // ── Retail, civic and the remaining trades (F5, 2026-08-31) ─────────
        // 68 of 209 ready unclaimed accounts carried sector NULL, and the cause
        // was never a missing sector — it was a Google category string with no
        // key to land on. Every key below is one of those strings. The four
        // catch-alls they need ('market', 'store', 'health', 'school') are the
        // broadest in the map and sit at the very END, after 'sport'.
        'butcher' => 'grocer',
        'food store' => 'grocer',
        'liquor' => 'liquor-store',
        'book store' => 'retail-store',
        'toy store' => 'retail-store',
        'electronics store' => 'retail-store',
        'bicycle' => 'retail-store',
        'garden center' => 'retail-store',
        'garden centre' => 'retail-store',
        'pet care' => 'pet-services',
        'museum' => 'museum-gallery',
        'laundry' => 'laundry',
        'locksmith' => 'locksmith',
        'educational institution' => 'tutor',

        // LAST AMONG THE TRADE KEYS, on purpose. 'sport' is a QUALIFIER, not a
        // trade: it names what a venue is about, while the key it collides with
        // names what the venue IS. "Sports bar" is a bar, "Sports cafe" is a
        // cafe. The head noun wins, so the qualifier must sit after every key it
        // can co-occur with — which, for a word this generic, means the end.
        // It stays a stem (not in WHOLE_WORD_KEYWORDS) so it still catches
        // "Sports centre"/"Sporting club"; the leading boundary is what
        // keeps it out of "Transport service".
        'sport' => 'gym',

        // ── The four category-wide catch-alls. Nothing may follow them. ─────
        // These are not trades at all — they are the top of Google's own
        // taxonomy, the string it falls back to when it has nothing narrower
        // ("Store" for milligram and northside-records, "Market" for
        // adelaide-central-market, "Health" for oscar-wylee-optometrist,
        // "School" for melbourne-guitar-academy). They classify LAST because
        // every one of them appears inside a more specific category above:
        // "Liquor Store", "Food Store", "Medical Clinic", "Dance School". A
        // reorder that lifts one of these is a silent downgrade of every
        // specific key it contains.
        'market' => 'market',
        'store' => 'retail-store',
        'health' => 'medical-clinic',
        'school' => 'tutor',
        // NO bare 'artist' key, deliberately — but not for the old reason.
        // "Artist" is one of Instagram's most generic categories: tattooists,
        // musicians, hairdressers and photographers all pick it, and the
        // substring map has no other signal to disambiguate it. It is handled
        // in fromInstagramProfile's last tier instead, AFTER the handle and
        // display name have had a go — jess.hair.stylist resolves to
        // hair-salon there. Keeping it out of THIS map also keeps it off the
        // Google path, which has no handle to fall back to.
        // 'art gallery'/'gallery' stay: unambiguous.
    ];

    /**
     * KEYWORD_SECTORS keys that must match as WHOLE words rather than stems.
     *
     * The default is a stem match anchored at a LEADING word boundary — that is
     * what 'landscap' => "Landscaping" and 'jewel' => "Jewelry" rely on, and it
     * already stops mid-word capture ("Transport service" no longer reads as
     * 'sport'). A key belongs HERE instead when it also opens common words from
     * an unrelated trade, which a leading anchor alone cannot separate:
     *
     *   'spa' → "SPAnish restaurant", "SPAce rental"
     *   'bar' → "BARbecue joint", "BARrister chambers", "BARre studio"
     *
     * Neither has a stem extension a real category would use, so requiring the
     * trailing boundary too costs nothing — beyond a plural 's', which the
     * matcher allows explicitly ("Day spas", "Wine bars"), because losing a
     * slug is a SILENT downgrade to "no sector" where a wrong one at least
     * shows up. Add a key here only with a worked example of the word it
     * wrongly opens; anything with a real participle or agent form ('clean' =>
     * "Cleaning", 'landscap' => "Landscaper") must stay a stem.
     *
     * @var list<string>
     */
    private const WHOLE_WORD_KEYWORDS = ['spa', 'bar', 'pub'];

    /**
     * Whole category strings that name a DOMAIN rather than a trade. Folded to
     * null on BOTH paths, checked as an exact match on the trimmed, lowercased
     * whole string before the keyword loop runs.
     *
     * 'health/beauty' is the case this exists for, and it exists because F5's
     * 'health' catch-all is a stem. Google files oscar-wylee-optometrist under
     * the bare category "Health", so the key has to be there; Instagram's
     * "Health/Beauty" is the Facebook-taxonomy bucket a tattooist, a
     * hairdresser and a dietitian all share, and it has been pinned to null
     * since 2026-08-10 for exactly that reason. Denying the whole string keeps
     * both: the specific account gets its sector, the domain bucket still
     * refuses to guess. Placeholder strings live in PLACEHOLDER_CATEGORIES —
     * those are a scraper's junk, these are real categories that mean too much.
     *
     * @var list<string>
     */
    private const VAGUE_CATEGORIES = ['health/beauty'];

    /**
     * Instagram business categories that the shared substring map gets WRONG or
     * misses entirely. Instagram's categories come from Facebook's Page
     * taxonomy — a CLOSED vocabulary, unlike Google's free-ish place-type
     * strings — so this is an EXACT match on the whole normalised category, not
     * a substring pass. Exact matching is what makes it collision-free: a
     * substring entry for lashes would capture "Flash Tattoo".
     *
     * Only entries the fallback gets wrong belong here. Anything the substring
     * map already resolves correctly ("Hair Stylist", "Barber Shop", "Tattoo &
     * Piercing Shop", "Restaurant") is deliberately absent.
     *
     * Keys MUST be lowercase and trimmed — fromInstagramCategory() normalises
     * the input before looking up, and does not normalise these.
     *
     * @var array<string, string>
     */
    private const INSTAGRAM_CATEGORY_SECTORS = [
        // Corrections — the substring map returns the wrong slug for these.
        'fitness trainer' => 'personal-trainer',
        'fitness coach' => 'personal-trainer',
        'music lessons & instruction school' => 'music-teacher',
        'music teacher' => 'music-teacher',

        // Where the shared substring map still lands elsewhere. Narrower than
        // it was: since 'bar' became a WHOLE_WORD_KEYWORD and 'sport' moved
        // last (2026-08-19), "bartender" and "barre studio" fall through to
        // null rather than to the FOOD slug 'bar', and "sports bar" resolves
        // to 'bar' unaided — that entry is now belt-and-braces. 'juice bar'
        // (a cafe), 'sportswear store' and 'hair removal service' still need
        // an exact entry to reach the right slug.
        'sports bar' => 'bar',
        'juice bar' => 'cafe',
        'bartender' => 'bartender',
        'barre studio' => 'yoga-instructor',
        'sportswear store' => 'clothing-boutique',
        'hair removal service' => 'esthetician',

        // Creative
        'digital creator' => 'content-creator',
        'content creator' => 'content-creator',
        'blogger' => 'content-creator',
        'videographer' => 'videographer',
        'video creator' => 'videographer',
        'graphic designer' => 'graphic-designer',
        'writer' => 'writer',

        // Beauty & personal care
        'skin care service' => 'esthetician',
        'skincare service' => 'esthetician',
        'waxing service' => 'esthetician',
        'eyelash service' => 'brows-lashes',
        'eyebrow service' => 'brows-lashes',
        'massage service' => 'spa',
        'massage therapist' => 'spa',

        // Health & fitness
        'pilates studio' => 'yoga-instructor',
        'nutritionist' => 'nutritionist',
        'dietitian' => 'nutritionist',
        'physical therapist' => 'physiotherapist',

        // Education & coaching
        'life coach' => 'life-coach',
        'business coach' => 'life-coach',

        // Professional services
        'consulting agency' => 'consultant',
        'marketing agency' => 'marketing-agency',
        'advertising agency' => 'marketing-agency',
        'financial planner' => 'financial-advisor',
        'insurance agent' => 'insurance-broker',

        // Trades & automotive
        'automotive repair shop' => 'mechanic',
        'plumbing service' => 'plumber',
        'contractor' => 'builder',
        'general contractor' => 'builder',

        // Hospitality
        'bed and breakfast' => 'accommodation',
        'vacation home rental' => 'accommodation',

        // NOT mapped, deliberately: 'health/beauty', 'beauty, cosmetic &
        // personal care', 'public figure', 'personal blog', 'entrepreneur',
        // 'product/service', 'local business'. Too vague to pick a sector from,
        // and sector drives FOOD_SECTORS capability gating — null is safer than
        // a guess, and the user can pick from the dashboard.
    ];

    /**
     * Keywords safe to substring-match against FREE TEXT — an Instagram handle
     * or display name — when the category is too vague to classify.
     *
     * Separate from KEYWORD_SECTORS on purpose. That map is safe against
     * Facebook's closed category vocabulary and dangerous against free text:
     * it holds 'spa' at index 5 and 'fitness' at index 8, so "Spartan Fitness"
     * resolves to 'spa'. Word-boundary anchoring would fix that but break the
     * run-together handles this exists to catch ('\btattoo' misses
     * crucibletattooco). A vetted map with plain substring matching does both.
     *
     * A key qualifies only if it (1) is >=5 characters, (2) is not a substring
     * of a common English word or Australian surname, (3) names a TRADE rather
     * than a medium, and (4) cannot be manufactured by joining across a
     * separator in a plausible handle. Clause 4 is why there is no bare 'hair'
     * (beth.airbnb -> bethairbnb), no bare 'chiro' ('Arch Ironing Services' ->
     * archironingservices), and no bare 'plumb' ('Kim Plumbago Florals', the
     * plant genus, -> kimplumbagoflorals).
     *
     * NO VALUE MAY BE IN FOOD_SECTORS. A wrong food slug flips four
     * capabilities and misroutes links via LinkRouter's own copy of the arms.
     * That rules out coffee/catering/baker — Baker is a top-20 AU surname.
     *
     * ORDER: first substring hit wins. Where a key is commonly a surname
     * ('barber') or a qualifier of another trade ('realestate', 'wedding'), it
     * MUST come after every trade key that can co-occur with it in one handle.
     * The medium someone practises beats the subject they practise it on.
     *
     * @var array<string, string>
     */
    private const TEXT_KEYWORD_SECTORS = [
        // Media first — these co-occur with surnames and qualifiers below.
        'photograph' => 'photographer',
        'videograph' => 'videographer',
        'graphicdesign' => 'graphic-designer',

        // Beauty & personal care
        'hairstylist' => 'hair-salon',
        'hairdress' => 'hair-salon',
        'hairsalon' => 'hair-salon',
        'hairstudio' => 'hair-salon',
        'barber' => 'barber',
        'tattoo' => 'tattoo-artist',
        'makeup' => 'makeup-artist',
        'lashes' => 'brows-lashes',
        'airbrushtanning' => 'esthetician',
        'spraytan' => 'esthetician',
        'skincare' => 'esthetician',
        'esthetic' => 'esthetician',
        // ACCEPTED false positive (round-1 fix review, 2026-08-12): 'Amass
        // Agency' -> amassagency -> contains 'massage'. No safe substring
        // variant exists — 'massage' IS the trade word — so this is kept, like
        // thebarberlin -> barber below. See the corpus entry for the ruling.
        'massage' => 'spa',
        'nailtech' => 'nail-technician',
        'nailsalon' => 'nail-technician',

        // Health & fitness
        'pilates' => 'yoga-instructor',
        'yogateacher' => 'yoga-instructor',
        'personaltrainer' => 'personal-trainer',
        'fitness' => 'gym',
        'physio' => 'physiotherapist',
        // Not bare 'chiro' — 'Arch Ironing Services'/'Monarch Ironworks'
        // normalise to archironingservices/monarchironworks, both containing
        // 'chiro'. 'chiroprac' still catches baysidechiropractic /
        // melbchiropractor; bare-'chiro' handles (bayside.chiro) are an
        // accepted miss.
        'chiroprac' => 'chiropractor',
        'dentist' => 'dentist',
        'nutrition' => 'nutritionist',

        // Trades & automotive
        // Two keys, not one 'plumb': 'kimplumbagoflorals' (the plant genus)
        // would match a bare 'plumb' stem. 'plumbing' catches abcplumbing,
        // 'plumber' catches samtheplumber; neither alone covers both.
        'plumbing' => 'plumber',
        'plumber' => 'plumber',
        'electrician' => 'electrician',
        'landscap' => 'landscaper',
        'carpentry' => 'builder',
        'carpenter' => 'builder',
        'mechanic' => 'mechanic',
        'cardetailing' => 'car-detailer',

        // Retail & professional — after the media keys above.
        'florist' => 'florist',
        'jeweller' => 'jewellery',
        'realestate' => 'real-estate-agent',
        'bookkeep' => 'accountant',
        'tutoring' => 'tutor',
    ];

    /**
     * Categories too vague to classify on their own, mapped as a LAST RESORT —
     * only after the category pass and the free-text pass have both missed.
     *
     * "Artist" is the case this exists for: tattooists, musicians, hairdressers
     * and photographers all pick it. Under the old first-writer-wins rule
     * stamping a guess here locked Google out permanently, so the map's policy
     * was "vague => null". The rank ladder makes the guess correctable, so a
     * last-resort guess is now better than nothing.
     *
     * Still deliberately absent: health/beauty, public figure, personal blog,
     * entrepreneur, product/service, local business. No single slug is a
     * defensible guess for any of them.
     *
     * @var array<string, string>
     */
    private const AMBIGUOUS_CATEGORY_SECTORS = ['artist' => 'artist'];

    /**
     * Classify free text (an Instagram handle or display name) into a sector.
     *
     * Normalises to a-z only — dots, underscores, spaces, digits and emoji all
     * removed — then takes the first substring hit in map order. Stripping
     * separators is what lets 'crucibletattooco' match; the map's clause-4
     * admission rule is what stops it manufacturing false positives.
     */
    public static function classifyText(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $normalised = preg_replace('/[^a-z]/', '', strtolower($raw)) ?? '';
        if ($normalised === '') {
            return null;
        }

        foreach (self::TEXT_KEYWORD_SECTORS as $keyword => $slug) {
            if (str_contains($normalised, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * The picker payload: sectors grouped into their sections, in list order.
     *
     * @return list<array{group: string, options: list<array{slug: string, label: string}>}>
     */
    public static function all(): array
    {
        $groups = [];
        foreach (self::SECTORS as $sector) {
            $groups[$sector['group']][] = [
                'slug' => $sector['slug'],
                'label' => $sector['label'],
            ];
        }

        // Preserve first-seen group order (SECTORS is already ordered by group).
        return array_map(
            fn (string $group, array $options) => ['group' => $group, 'options' => $options],
            array_keys($groups),
            array_values($groups),
        );
    }

    public static function isValid(string $slug): bool
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function labelFor(string $slug): ?string
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return $sector['label'];
            }
        }

        return null;
    }

    /**
     * The SectorStylePresets bucket a sector belongs to — read by
     * ProfileDesignPresets to auto-style a sitepage. Null for unknown slugs.
     */
    public static function bucketFor(string $slug): ?string
    {
        foreach (self::SECTORS as $sector) {
            if ($sector['slug'] === $slug) {
                return $sector['bucket'];
            }
        }

        return null;
    }

    /**
     * Whether a sector slug is in the Food & Drink group — the single predicate
     * every food-derived AccountCapabilities flag calls through. Null (no
     * sector chosen/synced yet) is NOT food: a business with no sector defaults
     * to the booking-only capability set until an industry is picked or synced.
     */
    public static function isFood(?string $sector): bool
    {
        return $sector !== null && in_array($sector, self::FOOD_SECTORS, true);
    }

    /**
     * Map a raw Google Business category (the payload's `category` — Places
     * `primaryTypeDisplayName`, or a humanized types[] entry when the primary
     * is generic (M-10), e.g. "Italian restaurant", "Barber shop", "Body art
     * service") to the closest curated sector slug
     * via the shared ordered-keyword classifier. Null when nothing matches or
     * the input is empty — callers leave the stored sector untouched on null.
     */
    public static function fromGoogleCategory(?string $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        return self::classify($category, self::KEYWORD_SECTORS);
    }

    /**
     * Map a raw Instagram business category (Apify `businessCategoryName`, e.g.
     * "Hair Stylist", "Artist", "None,Fast food restaurant") to the closest
     * curated sector slug.
     *
     * Instagram comma-joins up to three categories, PRIMARY FIRST, so this
     * resolves on the first SEGMENT that matches either map — not on the first
     * map that matches any segment. Two maps are consulted per segment: the
     * Instagram-vocabulary exact map, then the shared ordered-keyword
     * classifier fromGoogleCategory() uses, so a category the exact map doesn't
     * list still lands on the same sector Google would give the same business.
     *
     * Null when nothing matches or the input is empty — callers leave the
     * stored sector untouched on null.
     */
    public static function fromInstagramCategory(?string $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        // Whole-string exact FIRST, before any splitting — a genuine category
        // name can itself contain a comma ("Beauty, Cosmetic & Personal Care"),
        // and splitting one of those apart would match on a fragment.
        $exact = self::INSTAGRAM_CATEGORY_SECTORS[strtolower(trim($category))] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        // Then segment by segment, IN ORDER, trying BOTH maps on each segment
        // before moving to the next. Checking one map across every segment
        // first would let a secondary category outrank the primary one: a
        // restaurant whose page also lists "Digital Creator" would resolve to
        // content-creator, isFood() would go false, and can_use_menu /
        // can_use_reservations / can_use_online_ordering would silently switch
        // off — no longer permanent: Google or a manual pick outranks Instagram
        // and can correct it, but the capabilities stay dark until one does.
        //
        // No trailing whole-string classify(): a single (comma-free) input is
        // itself one segment, and no KEYWORD_SECTORS key contains a comma, so
        // splitting cannot lose a substring match. Pinned by a test.
        foreach (self::categorySegments($category) as $segment) {
            $mapped = self::INSTAGRAM_CATEGORY_SECTORS[strtolower($segment)]
                ?? self::classify($segment, self::KEYWORD_SECTORS);

            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * Resolve a sector from a whole Instagram profile — the live classifier.
     *
     * Three tiers, in order:
     *   1. fromInstagramCategory() per candidate, UNCHANGED. First that maps wins.
     *   2. classifyText() over the handle, then the display name.
     *   3. AMBIGUOUS_CATEGORY_SECTORS per segment.
     *
     * TIER 1 DELEGATES ON PURPOSE — do not inline it. fromInstagramCategory is
     * segment-major (for each segment: exact ?? keyword), and Instagram
     * comma-joins categories PRIMARY FIRST, so segment-major is what makes the
     * primary category win. Resolving exact-matches across all segments before
     * keyword-matches lets a secondary category outrank the primary one:
     * "Restaurant, Digital Creator" becomes content-creator, isFood() goes
     * false, and can_use_menu / can_use_reservations / can_use_online_ordering
     * silently switch off. Three revisions of the design spec proposed exactly
     * that reordering; delegating makes it unrepresentable.
     *
     * @param  list<mixed>  $categoryCandidates  raw per-actor category keys, in precedence order
     */
    public static function fromInstagramProfile(array $categoryCandidates, ?string $username, ?string $fullName): ?string
    {
        foreach ($categoryCandidates as $candidate) {
            $mapped = self::fromInstagramCategory(is_string($candidate) ? $candidate : null);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        foreach ([$username, $fullName] as $text) {
            $mapped = self::classifyText($text);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // Per segment, not whole-string: Instagram emits its literal "None" as a
        // real segment, so "None,Artist" would never match whole-string.
        foreach ($categoryCandidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            foreach (self::categorySegments($candidate) as $segment) {
                $mapped = self::AMBIGUOUS_CATEGORY_SECTORS[strtolower(trim($segment))] ?? null;
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        return null;
    }

    /**
     * The comma-separated segments of a raw category string: trimmed, with
     * empty and placeholder segments dropped, original order preserved.
     *
     * Instagram returns multiple categories comma-joined and includes its
     * literal "None" as a real segment — `hungryjacksau` returns
     * "None,Fast food restaurant" on a fully successful scrape (verified
     * 2026-08-11). A whole-string placeholder check therefore leaves the junk
     * prefix in place, both for classification and for the stored payload
     * (InstagramConnectionSeeder reads this too, because businessCategory is on
     * the public wire).
     *
     * @return list<string>
     */
    public static function categorySegments(?string $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', trim($raw))),
            fn (string $segment) => $segment !== ''
                && ! in_array(strtolower($segment), self::PLACEHOLDER_CATEGORIES, true),
        ));
    }

    /**
     * Classify a raw category string against an ORDERED keyword => slug map.
     * First match wins (case-insensitive), and a keyword only matches at a
     * WORD BOUNDARY.
     *
     * Boundary matching is safe here and NOT in classifyText because the two
     * scan different kinds of string. This map is scanned against spaced,
     * human-readable category names — Google's primaryTypeDisplayName (or a
     * humanized types[] entry when the primary is generic, M-10),
     * Instagram's businessCategoryName — where every keyword that is really
     * present starts a word. classifyText scans run-together handles, where
     * anchoring would lose the matches it exists to catch ('\btattoo' misses
     * crucibletattooco), so it keeps bare substring matching by design.
     *
     * Keys match as stems ('landscap' => "Landscaping"); WHOLE_WORD_KEYWORDS
     * anchors the tail as well for the short keys that open unrelated words.
     *
     * ORDER still settles genuine collisions where both keys appear as whole
     * words: specific-before-generic ('barber' before 'bar', so "Barber shop"
     * doesn't fall through), and head-noun-before-qualifier ('bar' before
     * 'sport', so "Sports bar" is a bar and not a gym).
     *
     * @param  array<string, string>  $orderedKeywordToSlug
     */
    private static function classify(string $raw, array $orderedKeywordToSlug): ?string
    {
        $lower = strtolower(trim($raw));
        if ($lower === ''
            || in_array($lower, self::PLACEHOLDER_CATEGORIES, true)
            || in_array($lower, self::VAGUE_CATEGORIES, true)) {
            return null;
        }

        foreach ($orderedKeywordToSlug as $keyword => $slug) {
            $tail = in_array($keyword, self::WHOLE_WORD_KEYWORDS, true) ? 's?\b' : '';

            if (preg_match('/\b'.preg_quote($keyword, '/').$tail.'/', $lower) === 1) {
                return $slug;
            }
        }

        return null;
    }
}
