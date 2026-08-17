<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// What a link WOULD become, before it exists (2026-08-17). The dashboard's
// two-step "Add link" pastes a URL, shows what we read off the page (title,
// description, share image, favicon) for the owner to check and change, and
// only THEN creates the item — through PoolItemCreateController, carrying the
// checked values. Without this the flow had to create first and enrich later.
//
// LinkCardScraper is the same one-shot snapshot the connect lane and the
// enrichment job use, behind SafeUrlFetcher (SSRF-guarded), so this reads
// nothing the backend does not already read. Writes nothing. Its own throttle
// — like /routing/preview — because it fires per paste, not per save.
class LinkPreviewController extends ApiController
{
    use ResolveCurrentUser;

    public function __construct(private readonly LinkCardScraper $scraper) {}

    /** POST /api/content/links/preview  { url } */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'min:3', 'max:2048'],
        ]);

        $url = $this->scraper->normalizeUrl($data['url']);
        if ($url === null) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        // snapshotOrMinimal, never a 404: a bot-blocked or JS-only page still
        // yields a usable card (host as the name, a service favicon), which is
        // exactly what the create endpoint would have stored anyway.
        $card = $this->scraper->snapshotOrMinimal($url);

        return $this->success([
            'url' => $card['url'],
            'name' => $card['name'],
            'description' => $card['description'],
            'favicon' => $card['favicon'],
            'logo' => $card['logo'],
            // The dashboard shows a quieter "we couldn't read the page" when
            // the snapshot itself failed and only the minimal card came back.
            'fetched' => $card['description'] !== null || $card['logo'] !== null || $card['name'] !== (parse_url($url, PHP_URL_HOST) ?: $url),
        ]);
    }
}
