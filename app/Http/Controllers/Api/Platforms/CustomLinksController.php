<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\AddCustomLinkRequest;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// The 'custom' integration — arbitrary user-attached URLs rendered as a
// Links section on the sitepage. Each link is one connection row
// (resource_id 'link-<hash>'): the page is fetched once at add time and its
// favicon, logo (og:image), name, and description are snapshotted into the
// payload. Just a titled, branded outbound link — no tracking, no
// commerce metadata, no refresh loop.
class CustomLinksController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const MAX_LINKS = 20;

    public function __construct(private readonly LinkCardScraper $scraper) {}

    protected function platform(): string
    {
        return Platform::Custom->value;
    }

    // GET /api/platforms/custom/links — every attached link, ordered.
    public function links(Request $request): JsonResponse
    {
        return $this->success(['links' => $this->linksData($this->currentUser($request))]);
    }

    // POST /api/platforms/custom/links — attach a URL. Returns 202 immediately
    // with a minimal card derived from the URL; EnrichLinkCardJob upgrades
    // name/logo/description off-thread once the HTTP fetch completes (JOB-1).
    public function addLink(AddCustomLinkRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $url = $this->scraper->normalizeUrl($request->validated()['url']);
        if (! $url) {
            return $this->error('Enter a valid link (https://...).', 422);
        }

        $payload = ['kind' => 'link', ...$this->scraper->minimalCard($url)];
        $rid = 'link-'.substr(sha1(strtolower($url)), 0, 16);

        return $this->withConnectionLock($user, function () use ($user, $payload, $rid, $url) {
            $existing = $this->linkRows($user)->firstWhere('resource_id', $rid);
            if (! $existing && $this->linkRows($user)->count() >= self::MAX_LINKS) {
                return $this->error('You can add up to '.self::MAX_LINKS.' links.', 422);
            }

            $this->writePendingLinkCard($user, $payload, $rid, resourceKind: 'link');
            EnrichLinkCardJob::dispatch((string) $user->id, $this->platform(), $rid, $url)->afterCommit();

            return $this->success([
                'status' => 'pending',
                'link' => $this->cardData($rid, $payload),
                'statusUrl' => url("/api/platforms/custom/links/{$rid}/status"),
            ], 202);
        });
    }

    // GET /api/platforms/custom/links/{id}/status — poll link-card enrichment.
    public function linkStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->linkCardStatusResponse($user, $id, fn () => [
            'links' => $this->linksData($user),
        ]);
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

    /**
     * Single-card response shape for the 202 body — mirrors one entry of
     * linksData() so the dashboard can render the placeholder immediately.
     *
     * @return array<string,mixed>
     */
    private function cardData(string $rid, array $payload): array
    {
        $card = CardPayload::fromArray($payload);

        return [
            'id' => $rid,
            'url' => $card->url(),
            'name' => $card->name(),
            'description' => $card->description(),
            'favicon' => $card->favicon(),
            'logo' => $card->logo(),
        ];
    }

    /**
     * Link rows ('link-*'), ordered.
     *
     * @return Collection<int, IntegrationConnection>
     */
    private function linkRows(User $user)
    {
        return $this->connectionsFor($user)->filter(
            fn (IntegrationConnection $row) => $row->resource_kind === 'link',
        )->values();
    }

    /** @return list<array<string,mixed>> */
    private function linksData(User $user): array
    {
        return $this->linkRows($user)->map(function (IntegrationConnection $row): array {
            $card = CardPayload::fromArray($row->payload);

            return [
                'id' => $row->resource_id,
                'url' => $card->url(),
                'name' => $card->name(),
                'description' => $card->description(),
                'favicon' => $card->favicon(),
                'logo' => $card->logo(),
            ];
        })->values()->all();
    }
}
