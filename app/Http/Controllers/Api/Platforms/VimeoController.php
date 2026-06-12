<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectVimeoRequest;
use App\Http\Requests\Platforms\SaveVimeoHighlightsRequest;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Services\Platforms\VimeoApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Vimeo — connect by profile/channel URL; the keyless Simple API provides
// the display name, avatar, and latest uploads (each with a public
// player.vimeo.com embed). YouTube-style curation on top: a recent-uploads
// picker feeds up to 5 user-chosen "highlight" videos on the selection.
class VimeoController extends SingleSelectionPlatformController
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly VimeoApi $vimeo) {}

    protected function platform(): string
    {
        return 'vimeo';
    }

    protected function resourceClass(): string
    {
        return VimeoConnectionResource::class;
    }

    // Watch platform — multiple profile accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // apiPath is the canonical profile identity (urls vary per input form).
    protected function accountKeyOf(array $selection): ?string
    {
        return is_string($selection['apiPath'] ?? null) ? $selection['apiPath'] : parent::accountKeyOf($selection);
    }

    // POST /api/platforms/vimeo/connect
    public function connect(ConnectVimeoRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $source = $this->vimeo->parseSource($request->validated()['url']);
        if (! $source) {
            return $this->error('Enter your Vimeo profile or channel URL (vimeo.com/yourname).', 422);
        }

        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return $this->error('Could not find that Vimeo profile.', 404);
        }

        // Re-adding an already-connected profile keeps that account's chosen
        // highlights; a new profile starts with none.
        $existing = $this->matchAccountRow($user, 'apiPath', $source['apiPath'])?->payload;
        $highlights = data_get($existing, 'highlights', []);

        return $this->connected($user, [
            'url' => $source['link'],
            'apiPath' => $source['apiPath'],
            'name' => $profile['name'] ?? null,
            'thumbnail' => $profile['thumbnail'] ?? ($videos[0]['thumbnail'] ?? null),
            'link' => $profile['link'] ?? $source['link'],
            'latest' => $videos[0] ?? null,
            'items' => array_slice($videos, 0, self::MAX_ITEMS),
            'highlights' => $highlights,
        ]);
    }

    // GET /api/platforms/vimeo/recent?account={id} — the latest uploads for the
    // highlights picker (the keyless API caps a page at 20). Defaults to the
    // first account when no account id is given.
    public function recent(Request $request): JsonResponse
    {
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $apiPath = data_get($row?->payload, 'apiPath');
        if (! $apiPath) {
            return $this->error('Connect a Vimeo profile first.', 404);
        }

        $videos = $this->vimeo->fetchVideos((string) $apiPath);
        if ($videos === []) {
            return $this->error('Could not load recent videos for that profile.', 422);
        }

        return $this->success(['videos' => $videos]);
    }

    // POST /api/platforms/vimeo/highlights?account={id} — snapshot up to 5
    // chosen uploads (by itemId, from the recent list) onto that account.
    // An empty list clears them.
    public function highlights(SaveVimeoHighlightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
                return $this->error('Connect a Vimeo profile first.', 404);
            }

            $videos = $this->vimeo->fetchVideos((string) data_get($selection, 'apiPath'));
            if ($videos === []) {
                return $this->error('Could not load recent videos for that profile.', 422);
            }

            // Keep the auto-latest tile + items grid fresh alongside the picks.
            // Profile name/avatar stay as connected — they aren't video fields.
            $selection['latest'] = $videos[0];
            $selection['items'] = array_slice($videos, 0, self::MAX_ITEMS);

            // Snapshot the chosen videos in the order the user posted them.
            $byId = collect($videos)->keyBy('itemId');
            $selection['highlights'] = collect($validated['itemIds'])
                ->map(fn (string $id) => $byId->get($id))
                ->filter()
                ->take(self::MAX_HIGHLIGHTS)
                ->values()
                ->all();

            $this->writeConnection($user, $selection, $row->resource_id);

            return $this->success(['id' => $row->resource_id, ...(new VimeoConnectionResource($selection))->resolve()]);
        });
    }
}
