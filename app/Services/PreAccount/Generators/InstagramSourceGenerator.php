<?php

namespace App\Services\PreAccount\Generators;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

// Builds a provisional user's site from a typed Instagram handle by reusing the
// EXACT connect machinery an authenticated user gets: a pending IntegrationConnection
// seeded by InstagramConnectionSeeder (scrape → mirror to R2 → payload write). The
// unclaimed site therefore renders identically to a connected user's site, and the
// IntegrationConnectionObserver flips content_instagram_auto_enabled on create.
class InstagramSourceGenerator implements SiteSourceGenerator
{
    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramConnectionSeeder $seeder,
    ) {}

    public function normalizeRef(string $raw): string
    {
        $ref = mb_strtolower(ltrim(trim($raw), '@'));
        if ($ref === '' || ! preg_match('/^[a-z0-9._]{1,30}$/', $ref)) {
            throw new \InvalidArgumentException('That does not look like an Instagram handle.');
        }

        return $ref;
    }

    public function dedupeKey(string $normalizedRef): string
    {
        return $normalizedRef; // already lowercase
    }

    public function handleSeed(string $normalizedRef, ?string $sourceName): string
    {
        return $normalizedRef;
    }

    public function generate(User $user, Site $site, string $sourceRef): void
    {
        // Only a handle the actor positively reports as nonexistent is the
        // prospect's problem. Every other failure is ours breaking upstream, and
        // calling it "source not found" tells someone their own Instagram account
        // doesn't exist — while also inviting a retry that buys the same answer
        // for another paid scrape.
        $result = $this->scraper->fetchProfileResult($sourceRef, $user->id);
        if ($result->profile === null) {
            throw $result->failure === ProfileFetchFailure::ProfileNotFound
                ? SourceGenerationException::sourceNotFound()
                : SourceGenerationException::scrapeFailed($result->failure->value);
        }
        $profile = $result->profile;

        // Pending placeholder mirroring InstagramController::connect — payload []
        // (NOT null: platform_connections.payload is NOT NULL on live Postgres).
        // resource_id matches ManagesIntegrationConnection::defaultResourceId()
        // (= platform()), which InstagramController also uses: 'instagram'.
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => Platform::Instagram->value,
                'resource_id' => Platform::Instagram->value,
            ],
            [
                'payload' => [],
                'is_active' => false,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        try {
            $this->seeder->seed($connection, $sourceRef, $user->id, $profile);
        } catch (\Throwable $e) {
            throw SourceGenerationException::scrapeFailed($e->getMessage());
        }

        // PRIV-2: bioLinks/syncFindings/unmatched are internal auto-sync bookkeeping
        // — never in PublicIntegrationConnectionResource::ALLOWLIST['instagram'] — so
        // dropping them from the PROVISIONAL (pre-claim) payload is pure data
        // minimisation with zero render impact. Deliberately narrow: images/
        // videoUrl/videoPoster/followersCount/postsCount/businessCategory are the
        // WYSIWYG preview and stay untouched. saveQuietly — seed()'s own ->update()
        // above already fired the connect-time observer side effects (cache purge,
        // content-instagram-auto flag) on the full payload; this is a same-write
        // trim, not a second meaningful change (payload's `_folder` key, the only
        // thing the `updated` observer inspects, is untouched here).
        $connection->forceFill([
            'payload' => Arr::except($connection->payload, ['bioLinks', 'syncFindings', 'unmatched']),
        ])->saveQuietly();

        // Scraped identity onto the user row (spec §4): placeholder → real values.
        // Read BOTH field shapes, as InstagramConnector and InstagramConnectionSeeder
        // already do: actors drift between camelCase and Instagram's raw GraphQL
        // snake_case, and reading only one silently leaves display_name as the
        // placeholder handle (live from ~2026-07-21 until 2026-08-10).
        $fullName = trim((string) (data_get($profile, 'fullName') ?? data_get($profile, 'full_name')));
        if ($fullName !== '') {
            $user->display_name = $fullName;
            $user->first_name = Str::before($fullName, ' ') ?: $fullName;
            $user->save();
        }
    }
}
