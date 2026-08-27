<?php

namespace App\Services\PreAccount\Generators;

use App\Jobs\PreAccount\BioMentionChainsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchFailure;
use App\Services\Platforms\Registry\Platform;
use App\Services\PreAccount\SourceGenerationException;
use App\Services\Profile\BioIntelligence;
use App\Services\Profile\PersonNameParser;
use Illuminate\Support\Facades\Log;

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
        private readonly BioIntelligence $bioIntelligence,
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

    public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false): void
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
        //
        // #R4, KNOWN GAP (owner ruling 2026-08-18, routing lane only). This is
        // the legacy singleton marker: 'instagram' names the PLATFORM, not the
        // account. App\Routing\ConnectionIdentity now translates it for the
        // routing lane, so a bio-page link back to this same handle folds into
        // this row instead of minting a second connection. The REVERSE ordering
        // is still open: if a routed row for the real handle already exists
        // (a website import ran first), this updateOrCreate() stacks a marker
        // row on top of it and the duplicate is back, from the other side.
        // Closing it means teaching this call and
        // ManagesIntegrationConnection::writeConnection() to consult
        // ConnectionIdentity first.
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

        // Scraped identity onto the user row (spec §4): placeholder → real values.
        // Read BOTH field shapes, as InstagramConnector and InstagramConnectionSeeder
        // already do: actors drift between camelCase and Instagram's raw GraphQL
        // snake_case, and reading only one silently leaves display_name as the
        // placeholder handle (live from ~2026-07-21 until 2026-08-10).
        //
        // ORDERING IS LOAD-BEARING: this must run BEFORE seed(), because seed()
        // routes the bio links (InstagramAutoSync → LinkRouter) and dispatches the
        // Fresha auto-connect, and FreshaStaffMatcher reads first_name/last_name off
        // this row. Folded after seed(), the matcher reads nulls and every account
        // silently falls through to the storewide menu — the feature would look
        // implemented and do nothing. Under QUEUE_CONNECTION=sync that is not a race,
        // it is deterministic.
        $fullName = trim((string) (data_get($profile, 'fullName') ?? data_get($profile, 'full_name')));
        $biography = data_get($profile, 'biography') ?? data_get($profile, 'bio');
        $biography = is_string($biography) ? trim($biography) : null;

        // T5/T13/T16 (2026-08-27, D6/D8): one bio-intelligence pass — clean
        // names (handle-first, their-words-gated), the stitched About, any
        // literal contact details, and the classified @mentions (stored on
        // the connection payload below for the T14 chains). The deterministic
        // parser remains the floor: every AI field is optional, gated, and
        // falls back to the parse (names) or to nothing (About/contact) —
        // no-About beats a bad About.
        $intel = $this->bioIntelligence->analyse($sourceRef, $fullName ?: null, $biography, data_get($profile, 'businessCategoryName') ?? data_get($profile, 'business_category_name'));

        $parsed = $fullName !== '' ? PersonNameParser::parse($fullName) : null;
        $displayName = $intel['displayName'] ?? $parsed['displayName'] ?? null;
        if ($displayName !== null) {
            $user->display_name = $displayName;
            $user->first_name = $intel['firstName'] ?? $parsed['firstName'] ?? $user->first_name;
            $user->last_name = $intel['firstName'] !== null ? $intel['lastName'] : ($parsed['lastName'] ?? null);
        }
        if (trim((string) $user->bio) === '' && $intel['about'] !== null) {
            $user->bio = $intel['about'];
        }
        // Structured actor business fields outrank AI-extracted bio text.
        $email = data_get($profile, 'business_email') ?? data_get($profile, 'businessEmail')
            ?? data_get($profile, 'public_email') ?? $intel['email'];
        if (trim((string) $user->public_contact_email) === '' && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user->public_contact_email = $email;
        }
        $phone = data_get($profile, 'business_phone_number') ?? data_get($profile, 'businessPhoneNumber') ?? $intel['phone'];
        if (trim((string) $user->public_contact_number) === '' && is_string($phone) && $phone !== '') {
            $user->public_contact_number = $phone;
        }
        $user->save();
        Log::info('pre_account.bio_intelligence', [
            'user_id' => $user->id,
            'ai_used' => $intel['aiUsed'],
            'display_name' => $user->display_name,
            'about_set' => $intel['about'] !== null,
            'email_set' => trim((string) $user->public_contact_email) !== '',
            'phone_set' => trim((string) $user->public_contact_number) !== '',
            'mentions' => count($intel['mentions']),
        ]);

        try {
            $this->seeder->seed($connection, $sourceRef, $user->id, $profile, $autoConnectBooking);
        } catch (\Throwable $e) {
            throw SourceGenerationException::scrapeFailed($e->getMessage());
        }

        // T14 (2026-08-27): the classified bio @mentions ride the connection
        // payload for the workplace/brand chains — data, not action; the
        // chains run (and gate) separately.
        if ($intel['mentions'] !== []) {
            $connection->refresh();
            $connection->update(['payload' => array_merge(
                (array) $connection->payload,
                ['bioMentions' => $intel['mentions']],
            )]);
            // Delayed so the Fresha → workplace path keeps precedence: the
            // chain only fills a workplace that is STILL empty when it runs.
            BioMentionChainsJob::dispatch((string) $user->id, $intel['mentions'])
                ->delay(BioMentionChainsJob::DISPATCH_DELAY_SECONDS);
        }

        // Flag, don't fail: the site still renders off what DID come back, and a
        // genuinely sparse account must never be told its build failed. Unlike the
        // refresh path there is nothing to protect here — an unbuilt site has no
        // prior payload — so a degraded profile is better than none.
        //
        // Scoped to this user's LIVE Instagram build; pre_account_builds_live_source_unique
        // guarantees at most one. A direct update, matching the SEC-4 convention that
        // state columns are not mass-assignable. Nothing observes this column, so
        // there is no cache to invalidate.
        if ($result->thin) {
            PreAccountBuild::query()
                ->where('user_id', $user->id)
                ->where('source_type', 'instagram')
                ->whereNull('claimed_at')
                ->update(['thin_scrape_at' => now()]);
        }

        // PRIV-2 lives in InstagramConnectionSeeder::seed() — per-writer, so it covers
        // every caller. A post-seed trim here missed the ones that skip this generator.
    }
}
