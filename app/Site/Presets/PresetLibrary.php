<?php

namespace App\Site\Presets;

use App\Models\Core\User\User;
use App\Services\Design\SectorStylePresets;
use App\Services\Profile\SectorTaxonomy;

/**
 * The v1 preset library, decided in plan §15 — ten presets plus blank, each a
 * page/section arrangement over the bounded rule DSL.
 *
 * Preset = f(AccountCapabilities, bucket [, slug refinement]) — capabilities,
 * never raw account_type (doctrine). The library speaks §15's preset names;
 * the live sector taxonomy speaks its ten style buckets — the map between
 * them lives here and nowhere else. Three §15 presets are slug refinements of
 * the creative bucket (music_audio, video_creator) or the hospitality bucket
 * (events_nightlife) rather than buckets of their own.
 *
 * Authored in CODE, like every other platform plane (catalog surfaces, kit
 * seeds) — §15's "versioned rows + staff arrangement-diff" storage arrives
 * with the staff tooling that needs it; the arrangement content is identical
 * either way. Kit seeding stays where it already lives: SectorStylePresets is
 * a read-time overlay, so a preset never writes kit columns.
 *
 * Every preset gets Home, Contact and Links (§15); the table below adds only
 * each preset's signature pages.
 */
final class PresetLibrary
{
    public const GENERAL = 'general';

    /**
     * bucket constant => §15 preset key.
     *
     * @var array<string, string>
     */
    private const BUCKET_PRESETS = [
        SectorStylePresets::BEAUTY_PERSONAL_CARE => 'hair_beauty',
        SectorStylePresets::FOOD_DRINK => 'food_drink',
        SectorStylePresets::HEALTH_FITNESS => 'fitness_wellbeing',
        SectorStylePresets::PROFESSIONAL_SERVICES => 'trades_services',
        SectorStylePresets::RETAIL_SHOPPING => 'retail_commerce',
        SectorStylePresets::HOME_SERVICES => 'trades_services',
        SectorStylePresets::HOSPITALITY => 'events_nightlife',
        SectorStylePresets::AUTOMOTIVE => 'trades_services',
        SectorStylePresets::CREATIVE_ENTERTAINMENT => self::GENERAL,
        SectorStylePresets::EDUCATION_COACHING => 'education_coaching',
    ];

    /**
     * slug => §15 preset key, sharpening the bucket default — how music_audio
     * and video_creator exist at all (§15's table names them; the taxonomy
     * folds them into the creative bucket).
     *
     * @var array<string, string>
     */
    private const SLUG_PRESETS = [
        'musician' => 'music_audio',
        'music-teacher' => 'music_audio',
        'content-creator' => 'video_creator',
        'videographer' => 'video_creator',
    ];

    /** The preset for this user, general when no sector says otherwise. */
    public static function forUser(?User $user): string
    {
        $slug = trim((string) $user?->sector);
        if ($slug === '') {
            return self::GENERAL;
        }

        if (isset(self::SLUG_PRESETS[$slug])) {
            return self::SLUG_PRESETS[$slug];
        }

        $bucket = SectorTaxonomy::bucketFor($slug);

        return $bucket === null ? self::GENERAL : (self::BUCKET_PRESETS[$bucket] ?? self::GENERAL);
    }

    /**
     * The arrangement a preset writes: pages (with capability gates) and
     * their sections (keys, rules, render modes) — §15's table, verbatim.
     * `key` doubles as preset lineage on both pages and sections.
     *
     * @return list<array{
     *     key: string, label: string, capability: ?string,
     *     sections: list<array<string, mixed>>,
     * }>
     */
    public static function pages(string $preset): array
    {
        $signature = match ($preset) {
            'hair_beauty' => [
                self::servicesPage('book'),
                self::photosPage(),
                self::reviewsPage(),
            ],
            'food_drink' => [
                self::menuPage(),
                self::photosPage(),
                self::reviewsPage(),
            ],
            'music_audio' => [
                self::listenPage(),
                self::eventsPage(),
                self::photosPage(),
            ],
            'video_creator' => [
                self::watchPage(),
                self::photosPage(),
                self::productsPage(),
            ],
            'fitness_wellbeing' => [
                self::servicesPage('book'),
                self::photosPage(),
                self::eventsPage(),
            ],
            'events_nightlife' => [
                self::eventsPage(),
                self::photosPage(),
                self::listenPage(),
            ],
            'trades_services' => [
                self::servicesPage('contact'),
                self::photosPage(),
                self::reviewsPage(),
            ],
            'retail_commerce' => [
                self::productsPage(),
                self::photosPage(),
            ],
            'education_coaching' => [
                self::servicesPage('book'),
                self::eventsPage(),
                self::documentsPage(),
            ],
            default => [
                self::photosPage(),
            ],
        };

        return [self::homePage(), ...$signature, self::contactPage(), self::linksPage()];
    }

    // ── The pages every preset shares ────────────────────────────────────────

    /** Home: identity renders from the document; the page owns the actions rail (§7). */
    private static function homePage(): array
    {
        return self::page('home', 'Home', sections: [
            self::section('home.actions', null, [
                'slot' => 'header_actions',
                'rule' => self::rule([['op' => 'has_facet', 'values' => ['f_action']]]),
                'render' => 'buttons',
                'order_by' => 'popularity',
                'limit_n' => 6,
                'min_items' => 0,
                'on_empty' => 'hide',
            ]),
        ]);
    }

    private static function contactPage(): array
    {
        return self::page('contact', 'Contact', sections: [
            self::section('contact.form', null, ['kind' => 'contact_form', 'rule' => self::rule([]), 'min_items' => 0, 'on_empty' => 'show_anyway']),
        ]);
    }

    private static function linksPage(): array
    {
        return self::page('links', 'Links', sections: [
            self::section('links.all', null, [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['link']]]),
                'render' => 'buttons',
            ]),
        ]);
    }

    // ── Signature pages ──────────────────────────────────────────────────────

    private static function servicesPage(string $intent): array
    {
        return self::page('services', 'Services', sections: [
            self::section('services.list', 'Services', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['service']]]),
                'render' => 'rows',
                'order_by' => 'alphabetical',
                // The intent flavours the row CTA at render; membership is the
                // same either way, so it rides as lineage in the key only.
                'key' => 'services.'.$intent,
            ]),
        ]);
    }

    private static function menuPage(): array
    {
        return self::page('menu', 'Menu', capability: 'menu', sections: [
            self::section('menu.items', 'Menu', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['menu_item']]]),
                'render' => 'rows',
                'group_by' => 'category',
                'order_by' => 'alphabetical',
                'price_mode' => 'show',
            ]),
        ]);
    }

    private static function photosPage(): array
    {
        return self::page('photos', 'Photos', sections: [
            self::section('photos.grid', 'Photos', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['media']]]),
                'render' => 'tiles',
            ]),
        ]);
    }

    private static function reviewsPage(): array
    {
        return self::page('reviews', 'Reviews', sections: [
            // Sample semantics (§4): the display set is the latest run's
            // records; stale_display show keeps yesterday's reviews up while
            // a fetch hiccups rather than blanking the page.
            self::section('reviews.latest', 'Reviews', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['review']]]),
                'render' => 'rows',
                'stale_display' => 'show',
            ]),
        ]);
    }

    private static function listenPage(): array
    {
        return self::page('listen', 'Listen', sections: [
            self::section('listen.catalog', 'Listen', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['track', 'release', 'episode', 'channel']]]),
                'render' => 'cards',
            ]),
            // The player rail (§7/§10): a carousel over everything embeddable.
            self::section('listen.players', 'Players', [
                'rule' => self::rule([['op' => 'has_facet', 'values' => ['f_embed']]]),
                'render' => 'carousel',
                'min_items' => 0,
            ]),
        ]);
    }

    private static function watchPage(): array
    {
        return self::page('watch', 'Watch', sections: [
            self::section('watch.videos', 'Watch', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['video']]]),
                'render' => 'cards',
            ]),
            self::section('watch.players', 'Players', [
                'rule' => self::rule([['op' => 'has_facet', 'values' => ['f_embed']]]),
                'render' => 'carousel',
                'min_items' => 0,
            ]),
        ]);
    }

    private static function productsPage(): array
    {
        return self::page('products', 'Products', sections: [
            self::section('products.catalog', 'Products', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['product']]]),
                'render' => 'cards',
                'price_mode' => 'show',
            ]),
        ]);
    }

    private static function eventsPage(): array
    {
        return self::page('events', 'Events', sections: [
            self::section('events.upcoming', 'Events', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['event']]]),
                'render' => 'rows',
            ]),
        ]);
    }

    private static function documentsPage(): array
    {
        return self::page('documents', 'Documents', sections: [
            self::section('documents.list', 'Documents', [
                'rule' => self::rule([['op' => 'kind_is', 'values' => ['document']]]),
                'render' => 'rows',
            ]),
        ]);
    }

    // ── Spec plumbing ────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $sections */
    private static function page(string $key, string $label, ?string $capability = null, array $sections = []): array
    {
        return ['key' => $key, 'label' => $label, 'capability' => $capability, 'sections' => $sections];
    }

    /** @param array<string, mixed> $overrides */
    private static function section(string $key, ?string $label, array $overrides): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'slot' => 'body',
            'kind' => 'collection',
            'mode' => 'automatic',
            'render' => 'cards',
            'order_by' => 'recency',
            'limit_n' => null,
            'group_by' => null,
            'price_mode' => null,
            'min_items' => 1,
            'on_empty' => 'hide',
            'stale_display' => 'inherit',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $predicates */
    private static function rule(array $predicates): array
    {
        return ['all' => $predicates];
    }
}
