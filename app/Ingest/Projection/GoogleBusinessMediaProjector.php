<?php

namespace App\Ingest\Projection;

/**
 * Places photo → the `media` item kind. The Places photo resource name is a
 * stable REFERENCE, not a fetchable URL (turning it into bytes needs a keyed
 * media call) — so the asset row carries the ref as its fingerprint and no
 * source_url, and the byte-mirroring pipeline resolves it later. Emitting a
 * keyed URL here would leak the API key into content rows.
 */
class GoogleBusinessMediaProjector implements Projector
{
    public static function version(): int
    {
        return 1;
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
            'media' => [[
                'role' => 'gallery',
                'ref' => $ref,
                'width' => $view->int('width_px'),
                'height' => $view->int('height_px'),
            ]],
        ];
    }
}
