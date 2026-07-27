<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Catalog\CatalogIntegrityCheck;
use App\Catalog\CompiledCatalog;
use App\Catalog\LegacyPlatformMap;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's read of the compiled catalog — the successor to the
 * hand-maintained FE mirror (lib/social/platforms.ts). Serves surfaces the
 * picker may show: active or sunset lifecycle, connectable, and servable
 * (every named capability present in this build — CatalogIntegrityCheck).
 * The artefact digest doubles as a strong ETag: the payload can only change
 * when a new artefact deploys.
 */
class CatalogSurfacesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $digest = CompiledCatalog::digest();
        $etag = '"'.substr(str_replace('sha256:', '', $digest), 0, 32).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag]);
        }

        $brands = [];
        foreach (CompiledCatalog::brands() as $key => $brand) {
            $brands[$key] = [
                'key' => $key,
                'displayName' => $brand['display_name'],
                'homepage' => $brand['homepage'],
                'lifecycle' => $brand['lifecycle'],
                'successorKey' => $brand['successor_key'],
            ];
        }

        $surfaces = [];
        foreach (CompiledCatalog::surfaces() as $key => $surface) {
            if (! in_array($surface['lifecycle'], ['active', 'sunset'], true)) {
                continue;
            }
            if (! CatalogIntegrityCheck::isServable($key)) {
                continue;
            }
            $surfaces[] = [
                'key' => $key,
                'brandKey' => $surface['brand_key'],
                'displayName' => $surface['display_name'],
                'routingClass' => $surface['routing_class'],
                'shelf' => $surface['shelf'],
                'identifierKind' => $surface['identifier_kind'],
                'isConnectable' => $surface['is_connectable'],
                'maxAccounts' => $surface['max_accounts'],
                'canonicalUrlTemplate' => $surface['canonical_url_template'],
                'hasEmbed' => $surface['embed'] !== null,
                'legacyPlatform' => LegacyPlatformMap::legacyFor($key),
            ];
        }

        return response()
            ->json(['digest' => $digest, 'brands' => $brands, 'surfaces' => $surfaces])
            ->withHeaders(['ETag' => $etag, 'Cache-Control' => 'private, max-age=300']);
    }
}
