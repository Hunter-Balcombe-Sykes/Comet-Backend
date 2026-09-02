<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\SeedReelMirrorJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\ImagePixelBudget;
use App\Services\Media\InstagramMediaUrl;
use App\Services\Media\MediaDiskResolver;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\PreAccount\BuildProgress;
use App\Services\Profile\BioIntel;
use App\Services\Profile\SectorTaxonomy;
use finfo;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Extracted verbatim from InstagramConnectJob::handle() (lines 149-261) so
// PreAccount\Generators\InstagramSourceGenerator can reuse the EXACT same
// mirror + selection-build + auto-sync + row-update pipeline an authenticated
// user's connect() gets — no forked/duplicated logic between the two callers.
// InstagramConnectJob still owns the scrape (fetchProfile) and the async
// dispatch/failure semantics; this class owns everything after a profile is
// in hand.
class InstagramConnectionSeeder
{
    // Instagram CDN hosts we'll fetch media from. URLs come from the scraper, but
    // we still (1) restrict to known CDN hosts and (2) fetch with redirects
    // DISABLED (withoutRedirecting below) so an allow-listed CDN URL can't
    // 30x-redirect us to an internal address (SSRF guard).
    private const ALLOWED_HOSTS = ['cdninstagram.com', 'fbcdn.net'];

    // Per-image fetch timeout (IG CDN, usually fast).
    private const IMAGE_TIMEOUT_SECONDS = 10;

    // Reels are larger + slower than a still, so they get their own timeout.
    private const VIDEO_TIMEOUT_SECONDS = 30;

    // Hard cap on a mirrored reel (50 MB). Instagram reels are short, so this is
    // generous; it stops a pathological file filling the temp disk / R2, and an
    // oversized reel simply falls back to its poster (no autoplay).
    private const MAX_VIDEO_BYTES = 52428800;

    // Hard cap on a mirrored still image (15 MB). Instagram photos are tiny
    // (<1 MB in practice); 15 MB is deliberately generous to future-proof against
    // high-res uploads while still preventing a pathological response from
    // buffering/storing a huge payload.
    private const MAX_IMAGE_BYTES = 15728640;

    // R1: a reel's CDN URL is short-lived and signed — a bad status or a
    // dropped connection on the first attempt is often a momentary blip, not
    // a real absence of video. One retry, not unbounded: a genuinely
    // oversized or wrong-content-type response would just fail identically
    // again.
    private const VIDEO_MIRROR_MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramAutoSync $autoSync,
        private readonly InstagramIdentitySync $identitySync,
        private readonly CustomLinkSeeder $linkSeeder,
        // WebsiteLinkHarvester dropped 2026-07-25: autoSaveUnmatchedLinks() no
        // longer re-classifies to decide probe-vs-custom — LinkRouter does that.
    ) {}

    /**
     * Mirror the profile's latest media to R2, auto-sync its bio links, and
     * persist the selection onto $connection. Returns the persisted selection.
     *
     * @return array<string, mixed>
     */
    // $autoConnectBooking: TRUE only on a staff/ManyChat build. Defaults FALSE so
    // the dashboard connect and refresh call sites below stay byte-identical and
    // keep showing the account holder a picker.
    // $intel: the bio-intelligence result the pre-account generator ALREADY paid
    // for on this build, threaded through to InstagramIdentitySync so the same
    // handle/fullName/biography is not analysed (and billed) a second time. Null
    // on the dashboard connect and refresh paths, which analysed nothing first.
    /**
     * The R2 prefix every mirrored asset for this connection lands under.
     *
     * Keyed on the CONNECTION, not on a wall-clock second. It used to be
     * `'platforms/instagram/'.$connection->created_at->timestamp` — a bare unix
     * second with no account component — so any two Instagram connections created
     * inside the same second shared a prefix. Two harms rode on that:
     *
     *   1. The second connection to mirror OVERWROTE the first's profile.jpg, so
     *      one account published another person's face. Found live 2026-09-01 on
     *      two pairs — aerial-studio/mr-bap under folder 1787835720, and
     *      melbourne-acupuncture/the-cobblers-last under 1788085840 — each pair
     *      serving a byte-identical profilePicUrl.
     *   2. DeleteMirroredMediaJob is dispatched with the payload's folder
     *      (IntegrationConnectionObserver:551 and :638), so disconnecting one
     *      account deleted the other account's mirrored media.
     *
     * A batch build is exactly the condition that produced it: a fleet run creates
     * several connections per second, so the collision rate ROSE with throughput —
     * the opposite of what an identity path should do.
     *
     * A method rather than an inline expression so the rule can be named and pinned:
     * the defect was invisible partly because it was one anonymous string concat.
     */
    public static function mirrorFolder(IntegrationConnection $connection): string
    {
        return 'platforms/instagram/'.$connection->getKey();
    }

    public function seed(IntegrationConnection $connection, string $username, string $userId, array $profile, bool $autoConnectBooking = false, ?BioIntel $intel = null): array
    {
        $folder = self::mirrorFolder($connection);

        // The most-recent photo AND the most-recent reel, picked independently. The
        // photo mirrors as the image; the reel mirrors its mp4 plus its own poster.
        // An oversized / failed video mirror leaves videoUrl null so a skeleton falls
        // back to the photo.
        $media = $this->scraper->latestMedia($profile, $userId);

        // R3 (2026-08-27): oe= pre-flight + refresh-by-shortcode before every
        // hero-media fetch — the actor's cached crawls can hand DEAD urls
        // (the 02:42:31 hero-reel success next to five dead post urls was
        // luck, not design). Also closes the old handoff's wrinkle: the oe
        // is logged at seed time.
        $images = [];
        if ($media['photo'] && $media['photo']['thumbnailUrl']) {
            $photoSrc = $this->freshOrOriginal($media['photo']['thumbnailUrl'], $media['photo']['shortCode'] ?? null, 'image');
            $photo = $photoSrc === null ? null : $this->mirrorOne($photoSrc, "{$folder}/photo.jpg");
            if ($photo) {
                $images = [$photo];
            }
        }

        // 9d: the reel mp4 was the largest single chunk of the ready path
        // (10-40s of CDN streaming). It leaves the critical path: the row is
        // written video-less (skeletons fall back to images[0]), and
        // SeedReelMirrorJob mirrors mp4 + poster on the media-mirror lane,
        // merging them into the payload — the observer's wasChanged('payload')
        // purge then swaps the reel onto the live page. Dispatched at the END
        // of seed() (after the authoritative row write) so the merge can never
        // be overwritten by this run's own full-selection save.
        $videoUrl = null;
        $videoPoster = null;
        $pendingReel = (bool) ($media['video'] && $media['video']['videoUrl']);

        $picSrc = $this->scraper->profilePicUrl($profile);
        $profilePic = $picSrc ? $this->mirrorOne($picSrc, "{$folder}/profile.jpg") : null;

        // BE2: bio links — externalUrl + externalUrls[].url + URLs regexed out of
        // biography, defensively (Apify actor field names vary by version; today's
        // actor returns none of these at all, so bioLinks() safely returns []).
        $bioLinks = $this->scraper->bioLinks($profile);
        $website = data_get($profile, 'externalUrl');
        $website = is_string($website) && preg_match('~^https?://~i', trim($website)) ? trim($website) : null;

        // Reclaim stale mirrors of a media type no longer present this run (e.g. a
        // prior reel + its cover when the account now leads with a photo, or a
        // removed profile pic). The folder is stable per connection
        // (mirrorFolder() keys on the connection uuid, which a refresh does not
        // move) and the filenames are fixed, so a reconnect overwrites the live
        // files in place — only the *complement* below (the
        // fixed names NOT re-written this run) can linger. Deleting them here,
        // in-job and AFTER the writes, is race-free: unlike a separately-queued
        // delete it can never run after a fresh re-mirror and wipe it. $images is
        // non-empty only when photo.jpg was written; Storage::delete on an absent
        // key is a safe no-op.
        $written = array_filter([
            $images ? "{$folder}/photo.jpg" : null,
            // A pending async reel counts as written: SeedReelMirrorJob is about
            // to overwrite both fixed names, so reclaiming them here would race
            // it. If the job ultimately drops the mp4, a PRIOR run's files can
            // linger unreferenced (payload holds no videoUrl) — storage
            // garbage, never a user-visible stale reel.
            $pendingReel ? "{$folder}/reel.mp4" : null,
            $pendingReel ? "{$folder}/reel-cover.jpg" : null,
            $profilePic ? "{$folder}/profile.jpg" : null,
        ]);
        $stale = array_values(array_diff([
            "{$folder}/photo.jpg",
            "{$folder}/reel.mp4",
            "{$folder}/reel-cover.jpg",
            "{$folder}/profile.jpg",
        ], $written));
        // Best-effort cleanup — must never abort a connection that otherwise
        // succeeded. "Delete on an absent key is a safe no-op" holds for S3's
        // own API in general, but isn't guaranteed for every backend/config, and
        // a first-ever connect (nothing stale yet) is exactly the case with
        // nothing to actually delete — matches mirrorOne()/mirrorVideo()'s own
        // try/catch + report() convention elsewhere in this file.
        if ($stale) {
            try {
                $this->mediaDisk()->delete($stale);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $selection = [
            'username' => $username,
            // Field-name drift across actor versions: the figue actor returns raw
            // Instagram GraphQL snake_case, older shapes used camelCase. Read both,
            // legacy camelCase first (matches InstagramScraper::profilePicUrl()'s
            // established precedent for this same actor swap).
            'fullName' => data_get($profile, 'fullName') ?? data_get($profile, 'full_name'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => $this->categoryOrNull(
                data_get($profile, 'businessCategoryName'),
                data_get($profile, 'business_category_name'),
                // The figue actor NULLs both keys above and puts the value here.
                // PARTNA_INSTAGRAM_ACTOR is a no-deploy env rollback, so without
                // this third candidate the stored payload blanks on rollback —
                // F5 in docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md.
                data_get($profile, 'category_name'),
            ),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            // T13/T16 (2026-08-27): the scrape has ALWAYS carried the
            // biography (bioLinks() above regexes URLs out of it) — now the
            // text itself persists, as the source for the auto-About, the
            // name pipeline and the bio-mention chains. Same defensive
            // multi-key reads as fullName (actor field names drift).
            'biography' => data_get($profile, 'biography') ?? data_get($profile, 'bio'),
            'publicEmail' => data_get($profile, 'business_email')
                ?? data_get($profile, 'businessEmail')
                ?? data_get($profile, 'public_email')
                ?? data_get($profile, 'publicEmail'),
            'publicPhone' => data_get($profile, 'business_phone_number')
                ?? data_get($profile, 'businessPhoneNumber')
                ?? data_get($profile, 'public_phone_number'),
            'mode' => 'automatic',
            // Single element: the most-recent photo, mirrored. Kept as a list so
            // existing consumers (flow-skeleton fallback, dashboard) read images[0].
            'images' => $images,
            // The most-recent reel: its mp4 + poster, both on R2. null when the
            // account has no reel (or the mp4 mirror was dropped). Skeletons go
            // video-first, falling back to images[0].
            'videoUrl' => $videoUrl,
            'videoPoster' => $videoPoster,
            // Internal: R2 prefix these mirrored files live under, so the observer
            // can reclaim them on disconnect/overwrite (CONS-21). Stripped from the
            // public endpoint by PublicIntegrationConnectionResource.
            '_folder' => $folder,
            // Internal (R1): latestMedia()'s diagnostics trail — what Apify actually
            // returned this run (post/video counts, per-video mp4 presence) — so a
            // "reel didn't mirror" report is diagnosable from stored data. Never
            // added to InstagramPayload/InstagramConnectionResource, so it can't
            // leak to any wire response (same leading-underscore convention as _folder).
            // data_get, not ?? — the concrete scraper always returns this key (so
            // PHPStan reads `['diagnostics'] ?? null` as dead code), but test
            // doubles of latestMedia() legitimately omit it.
            '_mediaDiagnostics' => data_get($media, 'diagnostics'),
            // BE2: the profile's own "website" field + every bio link found (both
            // internal — never emitted by InstagramConnectionResource).
            'website' => $website,
            'bioLinks' => $bioLinks,
        ];

        // Setup progress (2026-09-02): the media row the feed shows, with the
        // seed artwork as its thumbnails (the pool projection follows).
        BuildProgress::noteForUser(
            $userId,
            PreAccountBuildEvent::STAGE_MEDIA,
            PreAccountBuildEvent::STATUS_LANDED,
            'Grabbing your latest photos and reels',
            [
                // The seed artwork first, then the latest posts' own covers —
                // the signup card shows these as they land (item 8).
                'thumbnails' => array_values(array_unique(array_filter([
                    ...$images,
                    $videoPoster,
                    ...array_slice(array_values(array_filter(array_map(
                        static fn ($post) => is_array($post)
                            ? (data_get($post, 'displayUrl') ?? data_get($post, 'display_url') ?? data_get($post, 'images.0'))
                            : null,
                        is_array(data_get($profile, 'latestPosts')) ? data_get($profile, 'latestPosts') : [],
                    ), static fn ($u) => is_string($u) && $u !== '')), 0, 8),
                ]))),
                'avatar' => is_string($profilePic ?? null) && $profilePic !== '' ? $profilePic : null,
            ],
        );

        // Preserve the google-business origin tag across a re-scrape (it drives the
        // /synced "Change to" flow). Read it typed; the scrape WRITE below stays literal.
        if (($source = InstagramPayload::fromArray($connection->payload)->source) !== null) {
            $selection['source'] = $source;
        }

        // BE2: harvest the bio links into social/booking connections the same way
        // Google Business connect harvests its own found links — auto-add what's
        // missing, flag conflicts, surface leftovers for "add as custom link".
        // Best-effort: InstagramAutoSync isolates each link in its own try/catch,
        // so a bad link can't fail this job. Findings persist alongside the profile
        // in ONE write (not a follow-up save) so /synced never sees a half-written row.
        // ONE context for BOTH passes. A bio scrape routes twice — the auto-sync
        // loop over what classify() recognised, then autoSaveUnmatchedLinks over
        // what it did not — and those are two halves of one run, not two runs.
        // Held here because this method is the only thing that spans both.
        $ctx = new RouteContext(autoConnectBooking: $autoConnectBooking);

        $sync = $this->autoSync->seed($userId, $bioLinks, $autoConnectBooking, $ctx);
        $selection['syncFindings'] = $sync['findings'];
        $selection['unmatched'] = $sync['unmatched'];

        // Fold Instagram's own identity fields (industry/name/handle/contact)
        // into the user's real records, fill-if-empty. $userId is only a
        // string in this scope — resolve the model explicitly.
        $user = User::find($userId);
        if ($user !== null) {
            $this->identitySync->applyIdentity($user, $profile, $intel);
            $this->autoSaveUnmatchedLinks($user, $sync['unmatched'], $ctx);
        }

        // The run card for the WHOLE scrape. probes_denied is the only place a
        // starved link survives: it becomes an ordinary card, indistinguishable
        // from one we examined and rejected, so a scrape that ran out of budget
        // reads as a scrape that finished. Logged here rather than in either
        // pass because only this method sees both.
        //
        // Gated on there being links at all: the dominant case today is an
        // actor run with no bio fields, and LOG_LEVEL is debug on both envs, so
        // an ungated line would put a zero-valued run card on every connect and
        // every scheduled refresh.
        if ($bioLinks !== []) {
            Log::info('platforms.instagram.bio_links_routed', [
                'user_id' => $userId,
                'links_seen' => count($bioLinks),
                'findings' => count($sync['findings']),
                'unmatched' => count($sync['unmatched']),
                ...$ctx->summary(),
            ]);
            // Setup progress (2026-09-02): the same fact as the run card
            // above, said to the person signing up.
            $platforms = array_values(array_unique(array_filter(array_map(
                fn (array $finding) => (string) ($finding['platform'] ?? ''),
                $sync['findings'],
            ))));
            BuildProgress::noteForUser(
                $userId,
                PreAccountBuildEvent::STAGE_PLATFORMS,
                $platforms === [] ? PreAccountBuildEvent::STATUS_SKIPPED : PreAccountBuildEvent::STATUS_LANDED,
                $platforms === []
                    ? 'Checked '.BuildProgress::count(count($bioLinks), 'link', 'links').' in your bio — nothing to connect yet'
                    : 'Connected '.BuildProgress::count(count($platforms), 'platform', 'platforms').' from your bio links',
                ['platforms' => $platforms],
            );
        } else {
            // The feed's platforms row must always get an answer — the
            // progress reader waits on it for Instagram builds.
            BuildProgress::noteForUser(
                $userId,
                PreAccountBuildEvent::STAGE_PLATFORMS,
                PreAccountBuildEvent::STATUS_SKIPPED,
                'No links in your bio to connect — add platforms from the dashboard',
            );
        }

        // PRIV-2: bioLinks/syncFindings/unmatched are internal auto-sync bookkeeping
        // — never in PublicIntegrationConnectionResource::ALLOWLIST — so a pre-claim
        // owner's row does not carry them. Deliberately narrow: images/videoUrl/
        // videoPoster/followersCount/postsCount/businessCategory are the WYSIWYG
        // preview and stay, as does `source` (it drives the /synced "Change to" flow).
        //
        // This lives HERE, at the writer, not in InstagramSourceGenerator where it
        // started: the strip was per-GENERATOR while the write is per-WRITER, so every
        // other caller of seed() slipped past it. GoogleBusinessAutoSync::
        // dispatchInstagram() -> InstagramConnectJob -> here is the live case — an
        // Instagram connection seeded by a GOOGLE build, which never touches the
        // Instagram generator. Any future caller is now covered by construction.
        //
        // $sync['unmatched'] is still consumed in full by autoSaveUnmatchedLinks()
        // above — this drops what is STORED, not what this run acts on.
        //
        // Unlike GoogleBusinessFetch's PRIV-1 strip this does NOT self-heal on claim:
        // Instagram has no manifest FetchStrategy (so no refresh cron) and
        // ClaimSiteService re-enriches google-business only. A user whose site was
        // built from Google therefore loses the IG findings cards and the unmatched
        // onboarding prefills permanently, recoverable only by a manual dashboard
        // refresh — which re-runs seed() with this guard false.
        if ($connection->ownerIsUnclaimed()) {
            $selection = Arr::except($selection, ['bioLinks', 'syncFindings', 'unmatched']);
        }

        // PWL-7 (job/seeder half): the media mirroring + auto-sync + identity-sync
        // above are all vendor I/O / heavy work — they stay OUTSIDE the lock, same
        // discipline as ConnectFetchJob::handle(). Only the authoritative row write
        // below is contended (a scheduled refresh can
        // race it via the SAME platformConnectionLock key), so only it is locked.
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, (string) $connection->user_id);
        try {
            Cache::lock($key, 10)->block(5, function () use ($connection, $selection) {
                $connection->update([
                    'payload' => $selection,
                    'is_active' => true,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ]);
            });
        } catch (LockTimeoutException) {
            // SYNC-DRIVER CAVEAT: QUEUE_CONNECTION=sync means release()/retry never
            // happens (InstagramConnectJob's caller has no queue to catch a throw
            // and re-run). A swallowed timeout here would leave the row 'pending'
            // forever, so a terminal 'unavailable' write is mandatory — mirrors
            // ConnectFetchJob::handle()'s LockTimeoutException branch.
            Log::warning('instagram.connect_seeder.lock_timeout', [
                'connection_id' => $connection->id,
                'user_id' => (string) $connection->user_id,
            ]);
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'last_refresh_error' => "We couldn't save your Instagram connection just then — please try again.",
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        }

        // 9d: the reel leaves this method's wall clock. AFTER the row write on
        // purpose — the job merges into the payload under the same lock, and
        // dispatching earlier would let a fast worker's merge be clobbered by
        // this run's own full-selection save above. afterCommit covers the
        // pre-account build path (seed() runs inside the build transaction);
        // outside a transaction it dispatches immediately, which is fine here
        // because the row write has already happened.
        if ($pendingReel) {
            SeedReelMirrorJob::dispatch((string) $connection->id, $media['video'], $folder)->afterCommit();
        }

        return $selection;
    }

    /**
     * The async half of the 9d reel handoff: mirror the mp4 (then its poster —
     * still useless without the video) and merge both into the connection
     * payload under the same platformConnectionLock every writer of this row
     * takes. The observer's wasChanged('payload') purge propagates the swap to
     * the live page. A dropped mirror leaves the payload untouched — the photo
     * fallback simply stands.
     *
     * @param  array{videoUrl: ?string, thumbnailUrl: ?string, shortCode: ?string}  $video
     */
    public function mirrorReelAndSwap(IntegrationConnection $connection, array $video, string $folder): void
    {
        $videoSrc = $this->freshOrOriginal($video['videoUrl'] ?? null, $video['shortCode'] ?? null, 'video');
        $videoUrl = $videoSrc === null ? null : $this->mirrorVideo($videoSrc, "{$folder}/reel.mp4");
        if ($videoUrl === null) {
            return;
        }

        $videoPoster = null;
        if (! empty($video['thumbnailUrl'])) {
            $posterSrc = $this->freshOrOriginal($video['thumbnailUrl'], $video['shortCode'] ?? null, 'image');
            $videoPoster = $posterSrc === null ? null : $this->mirrorOne($posterSrc, "{$folder}/reel-cover.jpg");
        }

        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, (string) $connection->user_id);
        Cache::lock($key, 10)->block(5, function () use ($connection, $videoUrl, $videoPoster) {
            $connection->refresh();
            $payload = (array) $connection->payload;
            $payload['videoUrl'] = $videoUrl;
            if ($videoPoster !== null) {
                $payload['videoPoster'] = $videoPoster;
            }
            $connection->update(['payload' => $payload]);
        });

        Log::info('instagram.seed_reel.swapped', [
            'connection_id' => (string) $connection->id,
            'poster' => $videoPoster !== null,
        ]);
    }

    /**
     * Pass 2 of the bio routing: sweep what classify() could not place.
     *
     * LinkRouter (inside CustomLinkSeeder::seed()) handles classification,
     * routing, commerce probes, and custom-link fallback. The re-classify +
     * probe dispatch this method used to do is now inside the router.
     *
     * $ctx is the CALLER's — the same one the auto-sync pass used — and that is
     * load-bearing twice over. Its seen-platforms map stops this pass
     * re-deciding a platform pass 1 already settled (a second link to one
     * platform gets its card here instead of being turned back into a conflict
     * nobody keeps). And its probe budget is now one budget for the scrape
     * rather than one per pass: with a context each, a bio could spend 6 probes
     * here on top of 6 there, twice the documented cap.
     *
     * It also carries the caller's origin, so these links auto-connect exactly
     * when the links they arrived with did — a dashboard connect still gets its
     * picker.
     *
     * @param  list<array<string,mixed>>  $unmatched
     */
    private function autoSaveUnmatchedLinks(User $user, array $unmatched, RouteContext $ctx): void
    {
        foreach ($unmatched as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            if (! is_string($url)) {
                continue;
            }

            // Per-link fault isolation, matching pass 1's own loop
            // (InstagramAutoSync::seed) and LinkInBioScanJob's. Without it a
            // single bad link throws out of seed() BEFORE the payload write and
            // leaves the connection stuck 'pending' — and the comment at this
            // method's call site already promised a bad link cannot fail the
            // job. EnrichLinkCardJob running inline under QUEUE_CONNECTION=sync
            // is the live way that happens.
            try {
                $this->linkSeeder->seed($user, $url, $ctx);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    // Mirror a single image (cover or profile pic) to R2, STREAMED via a temp
    // file — same pattern as mirrorVideo below — so a large response never
    // buffers fully in worker memory (SCALE-102: the old body()/strlen() path
    // held the whole image in a PHP string before the size check even ran).
    //
    // SSRF guard (layered):
    //   1. CDN host allowlist — only cdninstagram.com / fbcdn.net subdomains pass.
    //   2. IP-resolution check — even an allow-listed hostname must resolve to a
    //      public address (defence-in-depth; the allowlist alone can't catch a
    //      compromised CDN hostname). Routes through SafeUrlFetcher::assertSafe(),
    //      the same guard used by every other outbound fetch in the subsystem.
    //   3. Redirects refused — a 3xx response is dropped, not followed (withoutRedirecting).
    //   4. Content-type enforced — only image/* is stored, AND the bytes that
    //      landed are byte-sniffed against ImagePixelBudget's format allowlist
    //      (#W2-SEC-14). The header is the CDN's claim about the file; finfo is
    //      the file. They disagree whenever the claim is wrong, which is the
    //      only case that matters.
    //   5. Byte cap — rejects before store if Content-Length signals an oversized
    //      file, with a check on the sunk temp file's actual on-disk size as a
    //      backstop for absent/inaccurate headers (replaces the old strlen check
    //      — same guarantee, now against a file instead of an in-memory string).
    /**
     * The URL itself when its signature is still live (oe logged either way),
     * a freshly-signed replacement when it is expired and the post's
     * shortcode allows a refresh, or null when the media is provably dead
     * with no way back — the caller's null-tolerant flow degrades exactly as
     * a failed mirror always has. The profile pic has no shortcode and rides
     * pre-flight only.
     *
     * Transport stays this class's hardened pattern-C fetch on purpose: it
     * is STRICTER than SafeUrlFetcher (two-host allowlist + no redirects),
     * so "unify the transport" would loosen it. The shared piece is the
     * expiry/refresh brain (InstagramMediaUrl), not the socket.
     */
    private function freshOrOriginal(?string $url, ?string $shortCode, string $kind): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $urls = app(InstagramMediaUrl::class);
        Log::info('instagram.seed_media_oe', [
            'kind' => $kind,
            'expires_at' => $urls->expiresAt($url),
            'expired' => $urls->isExpired($url),
        ]);
        if (! $urls->isExpired($url)) {
            return $url;
        }
        if (! is_string($shortCode) || $shortCode === '') {
            return null;
        }

        return $urls->freshUrl($shortCode, $kind === 'video' ? 'video' : 'image');
    }

    private function mirrorOne(string $url, string $path): ?string
    {
        if (! $this->isAllowedHost($url)) {
            return null;
        }

        // IP-resolution guard: cdninstagram.com / fbcdn.net always resolve to
        // public IPs, so a SafeUrlException here is anomalous and worth surfacing.
        try {
            app(SafeUrlFetcher::class)->assertSafe($url);
        } catch (SafeUrlException $e) {
            report($e);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'igimg');
        if ($tmp === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::IMAGE_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/*'])
                ->sink($tmp)
                ->get($url);

            // Drop non-2xx, including 3xx redirects we refuse to follow (SSRF guard).
            if ($response->status() >= 300) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }

            // Fast rejection when the server declares the size upfront.
            $contentLength = $response->header('Content-Length');
            if ($contentLength !== null && (int) $contentLength > self::MAX_IMAGE_BYTES) {
                return null;
            }

            // Hard cap enforced on what actually landed on disk — covers absent
            // or inaccurate Content-Length headers. Nothing over the limit
            // reaches R2.
            if ((int) filesize($tmp) > self::MAX_IMAGE_BYTES) {
                return null;
            }

            // #W2-SEC-14: the Content-Type check above is the CDN's LABEL. This
            // is the file. MediaMirror has run the same pair over its own
            // Instagram bytes since #SEC-1; this path stored and publicly served
            // them on the header alone. Path lane, not the string lane, so the
            // streaming this method exists for survives the check.
            //
            // The size caps run FIRST on purpose: they are the cheap ones, and
            // an oversized file should be refused for being oversized rather
            // than have its header parsed first.
            if (! ImagePixelBudget::safeToDecodeFile($tmp)) {
                Log::info('instagram.mirror_image.dropped', [
                    'reason' => 'bad_sniffed_type',
                    'host' => parse_url($url, PHP_URL_HOST),
                    'declared' => $contentType,
                ]);

                return null;
            }

            // Stream temp file → R2 (no in-memory copy of the image body).
            $stream = fopen($tmp, 'r');
            if ($stream === false) {
                return null;
            }
            $this->mediaDisk()->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->mediaDisk()->url($path);
        } catch (Throwable $e) {
            // Report so a systemic mirror/R2 failure surfaces in Nightwatch (OBS-7).
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // Mirror a reel's mp4 to R2, retrying once on a transient failure (R1).
    // Every drop reason a genuine miss is logged so a "reel didn't mirror"
    // report is diagnosable from stored data instead of a guess.
    private function mirrorVideo(string $url, string $path): ?string
    {
        for ($attempt = 1; $attempt <= self::VIDEO_MIRROR_MAX_ATTEMPTS; $attempt++) {
            $transient = false;
            $result = $this->attemptMirrorVideo($url, $path, $transient);

            if ($result !== null || ! $transient) {
                return $result;
            }
            if ($attempt < self::VIDEO_MIRROR_MAX_ATTEMPTS) {
                usleep(300_000); // 300ms — long enough for a momentary CDN blip to clear
            }
        }
        $this->logVideoMirrorDrop('retries_exhausted', $url);

        return null;
    }

    /**
     * Every mirrorVideo() drop reason must be observable — a silent null
     * return here is indistinguishable from "no reel existed" after the
     * fact, which is exactly the ambiguity that made the broken-oven
     * investigation unable to confirm root cause. Host only, never the full
     * URL (SEC — avoid logging a CDN URL that could carry a signed/expiring
     * token).
     */
    private function logVideoMirrorDrop(string $reason, string $url): void
    {
        Log::info('instagram.mirror_video.dropped', [
            'reason' => $reason,
            'host' => parse_url($url, PHP_URL_HOST),
        ]);
    }

    // Single mirror attempt, STREAMED via a temp file so a large video never
    // buffers fully in worker memory, and size-capped so a pathological file
    // can't fill disk/R2. Same layered SSRF guard as mirrorOne (host
    // allowlist + IP-resolution check + redirects refused). Returns null on
    // any miss → the caller falls back to the poster. $transient is set true
    // only for failure classes that plausibly self-resolve on a bare retry
    // (a bad HTTP status, or a connection-level exception) — never for a
    // disallowed host, a SafeUrlException, a content-type mismatch, or
    // either oversize check, none of which would ever succeed on a retry.
    private function attemptMirrorVideo(string $url, string $path, bool &$transient): ?string
    {
        if (! $this->isAllowedHost($url)) {
            $this->logVideoMirrorDrop('host_not_allowed', $url);

            return null;
        }

        // IP-resolution guard matching mirrorOne — defence-in-depth. Already
        // report()-ed (Nightwatch); this is an anomalous case for an
        // allow-listed CDN host, not a routine drop reason.
        try {
            app(SafeUrlFetcher::class)->assertSafe($url);
        } catch (SafeUrlException $e) {
            report($e);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'igreel');
        if ($tmp === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::VIDEO_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'video/*'])
                ->sink($tmp)
                ->get($url);

            // Drop non-2xx, including 3xx redirects we refuse to follow (SSRF guard).
            if ($response->status() >= 300) {
                $transient = true;
                $this->logVideoMirrorDrop('bad_status_'.$response->status(), $url);

                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'video/')) {
                $this->logVideoMirrorDrop('bad_content_type', $url);

                return null;
            }

            // Fast rejection when the server declares the size upfront (mirrors
            // mirrorOne — an oversized reel shouldn't finish streaming to disk
            // before we notice).
            $contentLength = $response->header('Content-Length');
            if ((int) $contentLength > self::MAX_VIDEO_BYTES) {
                $this->logVideoMirrorDrop('oversize_header', $url);

                return null;
            }

            // Oversized reel → drop the video (the poster still renders). Covers
            // absent or inaccurate Content-Length headers.
            if ((int) filesize($tmp) > self::MAX_VIDEO_BYTES) {
                $this->logVideoMirrorDrop('oversize_actual', $url);

                return null;
            }

            // #W2-SEC-14: byte-sniff what landed, not what the CDN called it.
            // A `video/` PREFIX, not a two-item brand allowlist (owner decision,
            // review round 2). libmagic maps the ftyp brands Instagram actually
            // serves across several types — isom/mp42/mp41/iso2/iso4/iso5/iso6/
            // dash/avc1/mmp4 -> video/mp4, qt -> video/quicktime, M4V ->
            // video/x-m4v, 3gp4 -> video/3gpp — and does not know msnv or cmfc
            // at all (application/octet-stream). Naming brands would make this
            // check drift with the libmagic version between macOS and the Cloud
            // image, silently stopping reel mirroring; the prefix still rejects
            // what the finding was about (HTML/GIF/script under a .mp4 name) and
            // still rejects application/octet-stream, which is what an unknown
            // brand and a hostile payload both sniff as.
            //
            // NOT transient — a file that is not a video will not become one on
            // a retry, so this joins the other permanent drop classes rather
            // than burning the retry budget. Logged with its own reason so a
            // "reels stopped mirroring" report separates a sniff mismatch from
            // a dead URL without a code read.
            $sniffed = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp));
            if (! str_starts_with($sniffed, 'video/')) {
                $this->logVideoMirrorDrop('bad_sniffed_type', $url);

                return null;
            }

            // Stream temp file → R2 (no second in-memory copy of the video).
            $stream = fopen($tmp, 'r');
            if ($stream === false) {
                return null;
            }
            $this->mediaDisk()->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->mediaDisk()->url($path);
        } catch (ConnectionException) {
            $transient = true;
            $this->logVideoMirrorDrop('connection_exception', $url);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // The media disk MUST resolve through MediaDiskResolver, never the literal
    // 'media' disk name: on Laravel Cloud the 'media' disk's config-cached
    // credentials go stale (platform R2 creds are injected at runtime, after
    // config:cache), so every hardcoded disk('media') write/delete came back
    // Unauthorized — the 2026-07-23 root cause of profile pics, post photos and
    // reels silently never mirroring (and of the UnableToDeleteFile noise).
    // Resolved per call, not memoized: the resolver's own superglobal probe is
    // the source of truth and is cheap.
    private function mediaDisk(): FilesystemAdapter
    {
        return Storage::disk(MediaDiskResolver::resolve());
    }

    private function isAllowedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * First candidate that ISN'T a placeholder — deliberately NOT the first that
     * is non-null. Mirrors InstagramIdentitySync::applySector's "first that
     * maps" rule, for the same reason: Instagram returns the literal string
     * "None" for an account with no category (crucibletattooco, 2026-08-10), so
     * a plain `??` chain would take "None" and then blank it, discarding a
     * usable sibling key that a different actor populated.
     *
     * The filter matters because businessCategory is on the public wire
     * (PublicIntegrationConnectionResource::ALLOWLIST) — unfiltered, the word
     * "None" renders as the professional's business category. Same list
     * SectorTaxonomy::classify() refuses to classify.
     */
    private function categoryOrNull(mixed ...$candidates): ?string
    {
        foreach ($candidates as $value) {
            if (! is_string($value)) {
                continue;
            }

            $cleaned = $this->stripPlaceholderSegments($value);
            if ($cleaned !== null) {
                return $cleaned;
            }
        }

        return null;
    }

    /**
     * Drop placeholder SEGMENTS, not just a wholly-placeholder string.
     * Instagram comma-joins categories and emits "None" as a real segment —
     * hungryjacksau returns "None,Fast food restaurant" on a fully successful
     * scrape — so a whole-string check published the junk prefix as the
     * professional's business category.
     *
     * Returns the ORIGINAL trimmed string when nothing was dropped: a genuine
     * category name can itself contain a comma ("Beauty, Cosmetic & Personal
     * Care") and must survive byte-identical rather than being re-joined.
     */
    private function stripPlaceholderSegments(string $value): ?string
    {
        $trimmed = trim($value);
        $kept = SectorTaxonomy::categorySegments($trimmed);
        if ($kept === []) {
            return null;
        }

        return count($kept) === count(explode(',', $trimmed))
            ? $trimmed
            : implode(', ', $kept);
    }
}
