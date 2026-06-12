<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddCustomLinkRequest;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\SmartLinks\MetadataParser;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// The 'custom' integration — arbitrary user-attached URLs rendered as a
// Links section on the sitepage. Each link is one connection row
// (resource_id 'link-<hash>'): the page is fetched once at add time and its
// favicon, logo (og:image), name, and description are snapshotted into the
// payload. Distinct from Smart Links — no tracking, no commerce metadata,
// no refresh loop; just a titled, branded outbound link.
class CustomLinksController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_LINKS = 20;

    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    protected function platform(): string
    {
        return 'custom';
    }

    // GET /api/platforms/custom/links — every attached link, ordered.
    public function links(Request $request): JsonResponse
    {
        return $this->success(['links' => $this->linksData($this->currentUser($request))]);
    }

    // POST /api/platforms/custom/links — attach a URL. Fetches the page once
    // and snapshots favicon / logo / name / description from its metadata.
    public function addLink(AddCustomLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        // Fetch + parse outside the lock (slow external HTTP).
        $payload = $this->fetchLinkMetadata($url);
        if ($payload === null) {
            return $this->error('Could not load that page. Check the link and try again.', 422);
        }

        $rid = 'link-'.substr(sha1(strtolower($url)), 0, 16);

        return $this->withConnectionLock($user, function () use ($user, $payload, $rid) {
            $existing = $this->linkRows($user)->firstWhere('resource_id', $rid);
            if (! $existing && $this->linkRows($user)->count() >= self::MAX_LINKS) {
                return $this->error('You can add up to '.self::MAX_LINKS.' links.', 422);
            }

            $this->writeConnection($user, $payload, $rid);

            return $this->success(['links' => $this->linksData($user)]);
        });
    }

    // DELETE /api/platforms/custom/links/{id} — remove one link.
    public function removeLink(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);
        if (! $this->linkRows($user)->firstWhere('resource_id', $id)) {
            return $this->error('Link not found.', 404);
        }
        $this->forgetConnection($user, $id);

        return $this->success(['links' => $this->linksData($user)]);
    }

    // DELETE /api/platforms/custom — remove every link.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetAllConnections($this->currentUser($request));

        return $this->success(['links' => []]);
    }

    // ── internals ────────────────────────────────────────────────

    /** Require an absolutable http(s) URL; default a bare domain to https. */
    private function normalizeUrl(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if (! is_array($parts) || ! isset($parts['host']) || ! str_contains($parts['host'], '.')) {
            return null;
        }

        return $input;
    }

    /**
     * Fetch the page and snapshot its public identity. Null when the page
     * can't be fetched (SafeUrlFetcher also rejects SSRF-y targets).
     *
     * @return array{kind:string, url:string, name:?string, description:?string, favicon:?string, logo:?string}|null
     */
    private function fetchLinkMetadata(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200 || ! is_string($res['body'] ?? null)) {
            return null;
        }

        $finalUrl = is_string($res['finalUrl'] ?? null) && $res['finalUrl'] !== '' ? $res['finalUrl'] : $url;
        $meta = $this->parser->parse($res['body'], $finalUrl);

        $name = $meta->og('og:title')
            ?? ($meta->title !== null && trim($meta->title) !== '' ? trim($meta->title) : null)
            ?? (parse_url($finalUrl, PHP_URL_HOST) ?: null);
        $description = $meta->og('og:description') ?? $this->metaName($meta->meta, 'description');
        $favicon = $meta->faviconUrl
            ?? $meta->appleTouchIconUrl
            ?? $this->parser->absolutize('/favicon.ico', $finalUrl);
        $logo = $this->parser->absolutize($meta->og('og:image'), $finalUrl) ?? $meta->headerLogoUrl;

        return [
            'kind' => 'link',
            'url' => $finalUrl,
            'name' => $name,
            'description' => $description,
            'favicon' => $favicon,
            'logo' => $logo,
        ];
    }

    /** @param array<string,string> $meta */
    private function metaName(array $meta, string $key): ?string
    {
        $v = $meta[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * Link rows ('link-*'), ordered.
     *
     * @return Collection<int, IntegrationConnection>
     */
    private function linkRows(User $user)
    {
        return $this->connectionsFor($user)->filter(
            fn (IntegrationConnection $row) => str_starts_with($row->resource_id, 'link-'),
        )->values();
    }

    /** @return list<array<string,mixed>> */
    private function linksData(User $user): array
    {
        return $this->linkRows($user)->map(function (IntegrationConnection $row): array {
            $payload = is_array($row->payload) ? $row->payload : [];

            return [
                'id' => $row->resource_id,
                'url' => $payload['url'] ?? null,
                'name' => $payload['name'] ?? null,
                'description' => $payload['description'] ?? null,
                'favicon' => $payload['favicon'] ?? null,
                'logo' => $payload['logo'] ?? null,
            ];
        })->values()->all();
    }
}
