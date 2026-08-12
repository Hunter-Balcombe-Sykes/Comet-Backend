<?php

namespace App\Ingest\Projection;

/**
 * Places photo → the `media` item kind.
 *
 * Slice 1b D1/D2: the photo is BORROWED, not owned. Google's terms grant photos
 * no caching exemption, the resolved lh3 url dies at ~30 days, and the photo
 * `ref` is reissued on EVERY Details fetch — so this asset is displayed live
 * and re-resolved inside that window, never mirrored and never pinned. The url
 * is resolved in the SAME billed fetch as the ref (PlacesDetailsDriver),
 * because a ref and a url are only consistent within one fetch; there is no
 * join key between a ref stored yesterday and a url resolved today.
 *
 * The url emitted here is the UNKEYED lh3 location the photo redirect resolves
 * to, never the keyed Places media endpoint — an api key must not reach a
 * content row.
 *
 * The headline stays null by contract (D7): a photo does not need one.
 */
class GoogleBusinessMediaProjector implements Projector
{
    public static function version(): int
    {
        // Bumped for slice 1b: the media entry gained url + attribution.
        return 2;
    }

    public static function kind(): string
    {
        return 'media';
    }

    public function project(RecordView $view): ?array
    {
        $ref = $view->string('ref');
        if ($ref === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => [],
            // array_filter so an unresolved photo carries neither a null url
            // nor an empty credit block: PoolResolver omits a frame it cannot
            // resolve, and an empty attribution object would render as a credit
            // with no name in it.
            'media' => [array_filter([
                'role' => 'gallery',
                'ref' => $ref,
                'url' => $view->string('url'),
                'width' => $view->int('width_px'),
                'height' => $view->int('height_px'),
                'attribution' => $view->map('attribution'),
            ], static fn ($v) => $v !== null && $v !== [])],
        ];
    }
}
