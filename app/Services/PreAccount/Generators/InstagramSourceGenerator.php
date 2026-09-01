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
use App\Services\PreAccount\SourcePrefetch;
use App\Services\Profile\BioIntel;
use App\Services\Profile\BioSource;
use App\Services\Profile\NameShapeGate;
use App\Services\Profile\PersonNameParser;
use App\Services\Profile\ProfileEnricher;
use App\Support\StyledUnicodeText;
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
        private readonly ProfileEnricher $enricher,
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

    /**
     * Item 1a, phase one: scrape + verify + NAME, before any identity exists.
     * The scrape is the same fetchProfileResult (vendor-first, cached 900s) —
     * so generate()'s legacy self-fetch path below reads the cache, never a
     * second paid run. The AI pass runs HERE because the cleaned person name
     * now seeds the handle; analyse() is user-free, and the resulting intel
     * rides the bundle so generate() applies it without a second model call.
     */
    public function prefetch(string $sourceRef, ?string $sourceName, ?string $userId = null): SourcePrefetch
    {
        // Only a handle the actor positively reports as nonexistent is the
        // prospect's problem. Every other failure is ours breaking upstream, and
        // calling it "source not found" tells someone their own Instagram account
        // doesn't exist — while also inviting a retry that buys the same answer
        // for another paid scrape.
        $result = $this->scraper->fetchProfileResult($sourceRef, $userId);
        if ($result->profile === null) {
            throw $result->failure === ProfileFetchFailure::ProfileNotFound
                ? SourceGenerationException::sourceNotFound()
                : SourceGenerationException::scrapeFailed($result->failure->value);
        }
        $profile = $result->profile;

        [$intel, $gated, $chosen] = $this->resolveNames($profile, $sourceRef);

        return new SourcePrefetch(
            payload: $profile,
            // The cleaned person name seeds the handle (Item 1c); when the
            // gates produced nothing usable the normalized IG ref remains the
            // honest seed, exactly as before Item 1a.
            displayName: $gated['displayName'] ?? null,
            untrimmedName: null,
            extra: ['intel' => $intel, 'gated' => $gated, 'chosen' => $chosen, 'thin' => $result->thin],
        );
    }

    /**
     * One gated naming pass, shared by prefetch() and the legacy self-fetch
     * path of generate(). Returns the BioIntel and the shape-gated name trio.
     *
     * @return array{0: BioIntel, 1: array{displayName: ?string, firstName: ?string, lastName: ?string}, 2: array{displayName: ?string, firstName: ?string, lastName: ?string}}
     */
    private function resolveNames(array $profile, string $sourceRef): array
    {
        $fullName = trim(StyledUnicodeText::fold((string) (data_get($profile, 'fullName') ?? data_get($profile, 'full_name'))) ?? '');
        $biography = data_get($profile, 'biography') ?? data_get($profile, 'bio');
        $biography = is_string($biography) ? trim(StyledUnicodeText::fold($biography) ?? '') : null;

        $source = new BioSource(
            handle: $sourceRef,
            fullName: $fullName ?: null,
            biography: $biography ?: null,
            businessCategory: data_get($profile, 'businessCategoryName') ?? data_get($profile, 'business_category_name'),
        );
        $intel = $this->enricher->analyse($source);

        $parsed = $fullName !== '' ? PersonNameParser::parse($fullName) : null;
        $chosen = $intel->displayName !== null
            ? ['displayName' => $intel->displayName, 'firstName' => $intel->firstName, 'lastName' => $intel->lastName]
            : ['displayName' => $parsed['displayName'] ?? null, 'firstName' => $parsed['firstName'] ?? null, 'lastName' => $parsed['lastName'] ?? null];

        return [$intel, NameShapeGate::apply($chosen, $sourceRef, $fullName), $chosen];
    }

    public function generate(User $user, Site $site, string $sourceRef, bool $autoConnectBooking = false, ?SourcePrefetch $prefetch = null): void
    {
        if ($prefetch !== null) {
            $profile = $prefetch->payload;
            $thin = (bool) ($prefetch->extra['thin'] ?? false);
        } else {
            // Legacy/re-run path (fleet rebuilds, early-access re-generation):
            // fetch for ourselves exactly as before Item 1a. The 900s profile
            // cache collapses this with a same-build prefetch.
            $result = $this->scraper->fetchProfileResult($sourceRef, $user->id);
            if ($result->profile === null) {
                throw $result->failure === ProfileFetchFailure::ProfileNotFound
                    ? SourceGenerationException::sourceNotFound()
                    : SourceGenerationException::scrapeFailed($result->failure->value);
            }
            $profile = $result->profile;
            $thin = $result->thin;
        }

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
        // Fold Instagram's "fancy font" characters before ANYTHING reads the
        // name. Those are Mathematical Alphanumeric Symbols, not styling —
        // "𝐓𝐡𝐞 𝐁𝐥𝐨𝐨𝐦 𝐑𝐨𝐨𝐦" is a run of MATHEMATICAL BOLD letters — so a screen
        // reader announces them codepoint by codepoint, a font stack falls back
        // mid-word, and PersonNameParser sees characters that are not letters.
        // Found live 2026-08-30 on thebloomroommalvern, rendering raw math-bold
        // as the largest text on its page.
        //
        // Applied HERE, at the read, because it is the only point both branches
        // share: the AI path takes $fullName through BioSource and the
        // deterministic path takes it through PersonNameParser, so normalising
        // in either one alone would leave the other reading the styled text.
        $fullName = trim(StyledUnicodeText::fold((string) (data_get($profile, 'fullName') ?? data_get($profile, 'full_name'))) ?? '');
        $biography = data_get($profile, 'biography') ?? data_get($profile, 'bio');
        $biography = is_string($biography) ? trim(StyledUnicodeText::fold($biography) ?? '') : null;

        // Structured actor business fields outrank AI-extracted bio text, so they
        // are applied FIRST and the enricher's fill-if-empty then leaves them
        // alone — precedence by ordering rather than by a special case.
        $email = data_get($profile, 'business_email') ?? data_get($profile, 'businessEmail')
            ?? data_get($profile, 'public_email');
        if (trim((string) $user->public_contact_email) === '' && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user->public_contact_email = $email;
        }
        $phone = data_get($profile, 'business_phone_number') ?? data_get($profile, 'businessPhoneNumber');
        if (trim((string) $user->public_contact_number) === '' && is_string($phone) && $phone !== '') {
            $user->public_contact_number = $phone;
        }

        // T5/T13/T16 (2026-08-27, D6/D8): one bio-intelligence pass — clean
        // names (handle-first, their-words-gated), the stitched About, any
        // literal contact details, and the classified @mentions (stored on
        // the connection payload below for the T14 chains). The deterministic
        // parser remains the floor: every AI field is optional, gated, and
        // falls back to the parse (names) or to nothing (About/contact) —
        // no-About beats a bad About.
        //
        // Since Item 1a the pass itself runs in prefetch() (the cleaned name
        // seeds the handle, so it must exist pre-identity); here the ALREADY
        // PAID result is applied via applyIntel — one model call per build,
        // same rule the seeder's identity-sync leg has always followed. The
        // legacy self-fetch path pays for its own pass via resolveNames().
        if ($prefetch !== null && ($prefetch->extra['intel'] ?? null) instanceof BioIntel) {
            $intel = $prefetch->extra['intel'];
            $gated = (array) ($prefetch->extra['gated'] ?? ['displayName' => null, 'firstName' => null, 'lastName' => null]);
            $chosen = (array) ($prefetch->extra['chosen'] ?? $gated);
        } else {
            [$intel, $gated, $chosen] = $this->resolveNames($profile, $sourceRef);
        }
        $this->enricher->applyIntel($user, $intel);

        if ($gated['displayName'] !== null) {
            $user->display_name = $gated['displayName'];
            // core.users.first_name is NOT NULL; '' is the schema's honest
            // "no usable name" — the review/staff matchers treat it as
            // unusable and fail closed, which is the point of the gate.
            $user->first_name = $gated['firstName'] ?? '';
            $user->last_name = $gated['lastName'];
        }
        $user->save();
        Log::info('pre_account.bio_intelligence', [
            'user_id' => $user->id,
            'ai_used' => $intel->aiUsed,
            'display_name' => $user->display_name,
            'name_gated' => $gated['displayName'] !== ($chosen['displayName'] ?? null) || $gated['firstName'] !== ($chosen['firstName'] ?? null) || $gated['lastName'] !== ($chosen['lastName'] ?? null),
            'about_set' => $intel->about !== null,
            'email_set' => trim((string) $user->public_contact_email) !== '',
            'phone_set' => trim((string) $user->public_contact_number) !== '',
            'mentions' => count($intel->mentions),
        ]);

        try {
            // The pass above is threaded through so InstagramIdentitySync (reached
            // inside seed()) applies it instead of paying for a second identical
            // analyse() — the duplicate was the NORMAL case, since Instagram never
            // discloses email/phone to a logged-out scrape and those blanks are
            // exactly what re-triggered it.
            $this->seeder->seed($connection, $sourceRef, $user->id, $profile, $autoConnectBooking, $intel);
        } catch (\Throwable $e) {
            throw SourceGenerationException::scrapeFailed($e->getMessage());
        }

        // T14 (2026-08-27): the classified bio @mentions ride the connection
        // payload for the workplace/brand chains — data, not action; the
        // chains run (and gate) separately.
        if ($intel->mentions !== []) {
            $connection->refresh();
            $connection->update(['payload' => array_merge(
                (array) $connection->payload,
                ['bioMentions' => $intel->mentions],
            )]);
            // Item 9a (2026-09-01): dispatched AT ready — the flat 600s delay
            // is gone. Fresha precedence moved from clock to STATE: the job
            // itself re-queues in 30s steps while the auto connect's
            // connectMode=auto marker is still in flight (see the job's
            // FRESHA_RECHECK_SECONDS doc). Workplace lands ~1-2 min after
            // build instead of 10-12.
            BioMentionChainsJob::dispatch((string) $user->id, $intel->mentions)
                ->afterCommit();
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
        if ($thin) {
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
