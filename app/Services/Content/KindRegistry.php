<?php

namespace App\Services\Content;

use App\Ingest\ConnectorRegistry;
use App\Ingest\Manifest\SourceProfile;

/**
 * The 13 closed item kinds and what the dashboard may do with each.
 *
 * This docblock used to call itself "the wire behind `GET /api/content/kinds`
 * (plan §6/§16)". **There is no such route** — verified 2026-08-14 against
 * routes/ and a live 404 on dev. The endpoint was planned and never built, and
 * the sentence outlived the plan. The registry's only caller in `app/` today is
 * `SectionRuleRules`, which uses {@see self::has()} to reject a section rule
 * naming an unknown kind; everything else here is declarative and read by
 * tests. Said plainly because a registry that believes it is a public contract
 * gets defended as one.
 *
 * THIS registry is the application-level truth, and it is deliberately
 * NARROWER than the DB. `content.items.kind` and `content.source_items.kind`
 * each carry a CHECK listing 14 values, and retiring a kind here does not
 * narrow them: that domain is a permissive backstop, not the source of truth.
 * The asymmetry is on purpose (convergence-log F9) — removing an unused value
 * from a CHECK buys nothing, while the migration plus the guard rewrite it
 * forces is disproportionate churn that risks leaving
 * ContentKindDomainParityTest reading as authoritative when it no longer is.
 * `article` was retired from here on 2026-08-14 with its producer (Substack)
 * and is the first value the two sides disagree on.
 *
 * The curation flags are DERIVED, not declared twice: each kind names the
 * SourceProfile that governs it, and {@see SourceProfile::isPinnable()} /
 * {@see SourceProfile::isEditable()} answer the questions. That is what keeps
 * the review-editing hole closed in one place — reviews arrive on Sample
 * streams, a Sample is never editable, so no per-kind boolean can drift away
 * from that rule and quietly re-open it.
 *
 * Connector manifests refine the declaration: if any REGISTERED connector
 * projects a kind from a Sample stream, that kind is demoted to Sample here
 * too. The registry is deliberately the floor rather than the only input —
 * most connectors are not registered yet, so deriving purely from manifests
 * would leave most kinds undeclared.
 */
final class KindRegistry
{
    /**
     * kind => [label, plural, governing profile, facets it typically carries].
     *
     * @var array<string, array{label: string, plural: string, profile: SourceProfile, facets: list<string>}>
     */
    private const KINDS = [
        'video' => ['label' => 'Video', 'plural' => 'Videos', 'profile' => SourceProfile::Feed,
            'facets' => ['f_text', 'f_link', 'f_duration', 'f_published', 'f_embed', 'item_media']],
        'track' => ['label' => 'Track', 'plural' => 'Tracks', 'profile' => SourceProfile::Catalogue,
            'facets' => ['f_text', 'f_link', 'f_duration', 'f_published', 'f_embed', 'f_playable', 'f_authored', 'f_catalog', 'item_media']],
        'release' => ['label' => 'Release', 'plural' => 'Releases', 'profile' => SourceProfile::Catalogue,
            'facets' => ['f_text', 'f_link', 'f_published', 'f_embed', 'f_authored', 'f_catalog', 'item_media', 'offers']],
        'episode' => ['label' => 'Episode', 'plural' => 'Episodes', 'profile' => SourceProfile::Feed,
            'facets' => ['f_text', 'f_link', 'f_duration', 'f_published', 'f_embed', 'f_playable', 'item_media']],
        'channel' => ['label' => 'Channel', 'plural' => 'Channels', 'profile' => SourceProfile::Identity,
            'facets' => ['f_text', 'f_link', 'f_embed', 'f_channel', 'item_media']],
        'service' => ['label' => 'Service', 'plural' => 'Services', 'profile' => SourceProfile::Catalogue,
            'facets' => ['f_text', 'f_link', 'f_duration', 'offers', 'f_action', 'item_media']],
        'menu_item' => ['label' => 'Menu item', 'plural' => 'Menu', 'profile' => SourceProfile::Catalogue,
            'facets' => ['f_text', 'offers', 'item_variants', 'item_tags', 'item_media']],
        'product' => ['label' => 'Product', 'plural' => 'Products', 'profile' => SourceProfile::Catalogue,
            'facets' => ['f_text', 'f_link', 'f_catalog', 'offers', 'item_variants', 'item_media']],
        'event' => ['label' => 'Event', 'plural' => 'Events', 'profile' => SourceProfile::Calendar,
            'facets' => ['f_text', 'f_link', 'f_occurrence', 'f_place', 'offers', 'f_action', 'item_media']],
        'link' => ['label' => 'Link', 'plural' => 'Links', 'profile' => SourceProfile::Mirror,
            'facets' => ['f_text', 'f_link', 'f_action', 'item_media']],
        'media' => ['label' => 'Photo', 'plural' => 'Photos', 'profile' => SourceProfile::Feed,
            'facets' => ['f_text', 'f_link', 'f_published', 'item_media']],
        // Third-party statements. Sample governs: show or hide them, never
        // rewrite them.
        'review' => ['label' => 'Review', 'plural' => 'Reviews', 'profile' => SourceProfile::Sample,
            'facets' => ['f_review', 'f_rated', 'f_published']],
        'document' => ['label' => 'Document', 'plural' => 'Documents', 'profile' => SourceProfile::Mirror,
            'facets' => ['f_text', 'f_link', 'f_file', 'item_media']],
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    /**
     * The full wire shape for one kind.
     *
     * @return array{kind: string, label: string, plural: string, profile: string, facets: list<string>, columns: list<array{facet: string, column: string, type: string}>, pinnable: bool, editable: bool, orderable: bool, mayDelete: bool, staleDisplayDefault: string}
     */
    public static function describe(string $kind): array
    {
        if (! self::has($kind)) {
            throw new \InvalidArgumentException("Unknown content kind: {$kind}");
        }

        $declared = self::KINDS[$kind];
        $profile = self::governingProfile($kind, $declared['profile']);
        $pinnable = $profile->isPinnable();

        return [
            'kind' => $kind,
            'label' => $declared['label'],
            'plural' => $declared['plural'],
            'profile' => $profile->value,
            'facets' => $declared['facets'],
            // Only editable kinds advertise editable columns: handing the UI a
            // column list for reviews would invite an edit form the API refuses.
            'columns' => $profile->isEditable()
                ? FacetRegistry::columnsForFacets($declared['facets'])
                : [],
            'pinnable' => $pinnable,
            'editable' => $profile->isEditable(),
            // Ordering is implemented BY pinning (pins carry the sort key), so
            // a kind that cannot be pinned cannot be hand-ordered either.
            'orderable' => $pinnable,
            'mayDelete' => $profile->mayDelete(),
            'staleDisplayDefault' => $profile->staleDisplayDefault(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return array_map(self::describe(...), self::kinds());
    }

    /**
     * The declared profile, demoted to Sample when a registered connector
     * projects this kind from a Sample stream. Demotion only ever removes
     * permissions, so an unregistered connector can never silently grant them.
     */
    private static function governingProfile(string $kind, SourceProfile $declared): SourceProfile
    {
        foreach (ConnectorRegistry::all() as $sourceKey => $_class) {
            foreach (ConnectorRegistry::manifestFor($sourceKey)->streams as $stream) {
                if ($stream->target === $kind && $stream->profile === SourceProfile::Sample) {
                    return SourceProfile::Sample;
                }
            }
        }

        return $declared;
    }
}
