<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\Workplace;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Cache\SiteCacheService;
use Database\Factories\Core\User\PreAccountBuildFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @property string $id
 * @property string|null $user_id 1:1 FK to core.users.id (UNIQUE, ON DELETE CASCADE) — NULL until the scrape verifies the source (Item 1a, scrape-first: the row exists user-less and materializeIdentity() binds the user). Not fillable — set via ->user()->associate().
 * @property string|null $account_type 'partna'|'business', captured at request time (9h) — nullable on pre-9h rows.
 * @property string|null $source_name Optional display-name hint captured with the build request (ManyChat/staff lanes).
 * @property string $source_type One of 'instagram'|'google_business' (source_type CHECK) — the pairing map key in config('partna.pre_account.*').
 * @property string $source_ref The raw source reference as typed/looked up (handle, place id, etc.).
 * @property string $source_ref_lc Lowercased $source_ref — the dedupe key (pre_account_builds_live_source_unique).
 * @property string $built_via One of VIA_* ('signup'|'staff'|'early_access') (built_via CHECK).
 * @property string|null $built_by_staff_id FK to core.partna_staff.id, ON DELETE SET NULL. NULL for signup-originated builds. Not fillable — set via ->builtByStaff()->associate().
 * @property string $build_state One of STATE_* — text NOT NULL DEFAULT 'pending' with a matching CHECK constraint (supabase/migrations/20260718200000_pre_account_sites.sql).
 * @property string|null $failure_code One of FAILURE_* (e.g. FAILURE_SOURCE_NOT_FOUND, FAILURE_SCRAPE_FAILED) when build_state is 'failed' — not DB-CHECK-enforced, app-level vocabulary only.
 * @property Carbon|null $thin_scrape_at Stamped when the source scrape returned no post timeline. INDEPENDENT of build_state — a thin build stays 'ready' because the site still renders. Not fillable (state column, SEC-4).
 * @property Carbon|null $content_filled_at 9h tier marker: first poll observation of visible content (READY gallery/content media, or a fetched menu). Lazily stamped by observeTierMarkers(); not fillable.
 * @property Carbon|null $enriched_at 9h tier marker: first poll observation of a landed workplace. Lazily stamped by observeTierMarkers(); not fillable.
 * @property string|null $created_ip_hash HMAC-SHA256 of CF-Connecting-IP under config('partna.pre_account.ip_hash_key') — see hashIp(); NULL for staff-built rows (no visitor IP to hash).
 * @property Carbon|null $expires_at Drives builds:prune-expired; irrelevant once claimed. NULL = never-expire (early-access builds, until staff approval).
 * @property string|null $contact_email Notify address + email-gate value; NULL = first-come claim.
 * @property Carbon|null $invited_at When the claim invite was sent (ClaimNotifier stamps it after queueing the mail). NULL = not yet invited — the idempotency guard.
 * @property string|null $claim_token_hash SHA-256 of the claim token (spec §4). Plaintext NEVER stored. NULL = no live token. Not fillable — minted via ClaimTokenIssuer.
 * @property Carbon|null $claim_token_issued_at When the current token was minted. Not fillable.
 * @property string|null $claim_idempotency_key Caller key from the ManyChat webhook; a retry matching it re-mints (spec §5.4). Not fillable.
 * @property bool $auto_invite false = publish the site but DEFER the claim invite for manual review + POST /builds/{build}/invite. Default true = auto-send on publish (unchanged).
 * @property Carbon|null $claimed_at NULL while the build is live (scopeLive); set once the visitor claims the site.
 * @property bool $published_by_claim T28: true while a claim's publish flip is outstanding — claim() sets it when IT published the site; release() unpublishes on it and clears it. Not fillable (forceFill, same SEC-4 posture as claimed_at).
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read PartnaStaff|null $builtByStaff
 */
// Permanent origin record for a pre-account (site-first) build. 1:1 with the
// provisional user; survives claim. NOT a ledger of ongoing source interactions —
// post-claim refreshes belong to platform_connections.
class PreAccountBuild extends BaseModel
{
    use HasFactory, HasUuids;

    public const STATE_PENDING = 'pending';

    public const STATE_BUILDING = 'building';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public const FAILURE_SOURCE_NOT_FOUND = 'source_not_found';

    public const FAILURE_SCRAPE_FAILED = 'scrape_failed';

    // LIFE-4: stamped by builds:reconcile-stuck when a build sat in
    // pending/building past partna.pre_account.stuck_build_sla_minutes —
    // app-level vocabulary only (failure_code has no DB CHECK constraint).
    public const FAILURE_STUCK_TIMEOUT = 'stuck_timeout';

    public const VIA_SIGNUP = 'signup';

    public const VIA_STAFF = 'staff';

    public const VIA_EARLY_ACCESS = 'early_access';

    /**
     * Whether this user's NEWEST build is the self-serve sign-up lane (A.7).
     * The discriminator several fill lanes gate on: a sign-up build offers
     * (setup dialog) where a staff/outreach demo build auto-fills.
     */
    public static function latestIsSignup(string $userId): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->value('built_via') === self::VIA_SIGNUP;
    }

    protected $table = 'core.pre_account_builds';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id / built_by_staff_id deliberately NOT fillable — set via associate().
    // SEC-4: build_state/claimed_at/failure_code drive the state machine and are
    // also excluded — writers use forceFill()/direct assignment (a silently
    // dropped write here strands a build in the wrong state with zero error).
    // thin_scrape_at is a state column on the same grounds.
    protected $fillable = [
        'source_type', 'source_ref', 'source_ref_lc', 'built_via',
        'created_ip_hash', 'expires_at', 'contact_email', 'auto_invite',
        // Item 1a: request-time facts the job consumes at (post-scrape)
        // identity-materialization time.
        'account_type', 'source_name',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'invited_at' => 'datetime',
        'thin_scrape_at' => 'datetime',
        'claim_token_issued_at' => 'datetime',
        'auto_invite' => 'boolean',
        'content_filled_at' => 'datetime',
        'enriched_at' => 'datetime',
    ];

    /**
     * 9h: lazily stamp the post-ready tiers the first time the public poll
     * observes them — content_filled (a projected pool item with media, a
     * READY content-pool site_media row, or the menu fetched) and enriched
     * (a workplace landed). One indexed query per still-null tier per poll
     * while settling; each stamp emits the per-tier timing telemetry line
     * the timeline campaign measures from.
     *
     * The pool-item arm (2026-09-02, campaign re-run): since Wave 3 the
     * Instagram/TikTok/… pool projects into content.items + item_media and
     * the page serves it progressively from ready — only website grabs
     * still mint site_media content rows. Watching site_media alone made
     * the marker fire on the WebsiteGalleryScan lane (+95–105s) while the
     * pool had been on the page since ready+3s. Any item with a media row
     * counts: this is telemetry about content having landed, not the
     * rendering read (PoolResolver's LiveSourceScope is the visibility
     * authority and needs the whole source plane to answer).
     * "Observed at", not "happened at" — precise enough for tiers whose
     * producers span many jobs, and centralised here instead of hooked into
     * every one of them. Best-effort by contract: a failure must never break
     * the poll.
     */
    public function observeTierMarkers(): void
    {
        if ($this->build_state !== self::STATE_READY || $this->user_id === null) {
            return;
        }
        if ($this->content_filled_at !== null && $this->enriched_at !== null) {
            return;
        }

        try {
            $siteId = $this->user?->site?->id;
            $stamps = [];

            if ($this->content_filled_at === null && $siteId !== null) {
                $hasContent = SiteMedia::query()
                    ->where('site_id', $siteId)
                    ->whereIn('pool', SiteMedia::GALLERY_POOLS)
                    ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                    ->exists()
                    || Menu::query()
                        ->where('user_id', $this->user_id)
                        ->where('fetch_status', 'ok')
                        ->exists()
                    || DB::connection('pgsql')->table('content.items')
                        ->where('content.items.user_id', $this->user_id)
                        ->whereExists(function ($media) {
                            $media->from('content.item_media')
                                ->whereColumn('content.item_media.item_id', 'content.items.id');
                        })
                        ->exists();
                if ($hasContent) {
                    $stamps['content_filled_at'] = now();
                }
            }

            if ($this->enriched_at === null && $siteId !== null) {
                $hasWorkplace = Workplace::query()
                    ->where('site_id', $siteId)
                    ->exists();
                if ($hasWorkplace) {
                    $stamps['enriched_at'] = now();
                }
            }

            if ($stamps !== []) {
                $this->forceFill($stamps)->saveQuietly();
                // The public payload cache keys off site.updated_at, which
                // content landing never touches (2026-09-02): rotate it here,
                // at the one moment we know the pools just filled, so the
                // sign-up card's wire read and the sitepage's reload see the
                // content instead of the pre-ingest empty payload for up to
                // the TTL and its stale window.
                try {
                    $site = $this->user?->site;
                    if ($site !== null) {
                        app(SiteCacheService::class)->invalidateSitePayload($site);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
                foreach ($stamps as $column => $at) {
                    Log::info('pre_account.tier', [
                        'build_id' => $this->id,
                        'tier' => str_replace('_at', '', $column),
                        'seconds_since_created' => (int) $this->created_at->diffInSeconds($at),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function builtByStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'built_by_staff_id');
    }

    /**
     * Was this build made FOR someone, rather than BY them?
     *
     * An outreach build carries a real business's name, photos and hours,
     * scraped before that business has ever heard of Partna — so it must only
     * ever be claimed by an invited address, never first-come.
     *
     * Keyed on WHO CREATED THE ROW, not on `built_via`. `built_via` is derived
     * from the caller (PreAccountBuildService: `$builtVia ?? ($staff ?
     * VIA_STAFF : VIA_SIGNUP)`), and the public unauthenticated build endpoint
     * passes neither — so every row it creates reads `signup`, and anything
     * exempting `signup` would exempt an attacker's scrape of a real business.
     * `built_by_staff_id` cannot be set from a public request.
     *
     * VIA_EARLY_ACCESS is trustworthy here only because the public
     * early-access form no longer builds synchronously (see
     * EarlyAccessService): the sole remaining writer of an early-access build
     * is ApproveEarlyAccessBuildJob, behind staff approval. If that ever
     * changes, this arm must go — an anonymous caller able to set `built_via`
     * would use it to exempt themselves.
     *
     * VIA_STAFF is checked in addition to `built_by_staff_id` (not instead of
     * it) because `built_by_staff_id` is ON DELETE SET NULL: deleting the
     * staff row that created a build silently un-gates it. Both come from the
     * same `$staff ? VIA_STAFF : VIA_SIGNUP` expression at creation time, so
     * `built_via` is exactly as trustworthy and survives the staff row going
     * away.
     *
     * UPDATE 2026-08-25: VIA_STAFF now ALSO originates from the ManyChat
     * webhook (a static shared secret, not staff auth) — see
     * ManyChatBuildController. The classification still fails SAFE (more
     * outreach, never less), so #SEM-2's conclusion holds, but its premise
     * "can only originate from an actual staff-authenticated write" no longer
     * does. Do not reason from that sentence.
     */
    public function isOutreach(): bool
    {
        return $this->built_by_staff_id !== null
            || $this->built_via === self::VIA_STAFF
            || $this->built_via === self::VIA_EARLY_ACCESS;
    }

    /**
     * PRIV-3: pseudonymise a visitor IP for created_ip_hash.
     *
     * NOT a bare sha256. A digest is only a pseudonym against someone who
     * cannot enumerate the input space, and the entire IPv4 space is 4.3B
     * candidates — so an unsalted sha256 stored beside a site is a
     * de-anonymisation primitive for anyone who reads the table, not a
     * privacy measure. HMAC under a secret the reader does not have is.
     *
     * The pepper is config('partna.pre_account.ip_hash_key'), which defaults
     * to APP_KEY. APP_KEY is stored 'base64:'-prefixed, so it is decoded
     * first: hashing the literal "base64:..." string would work, but would
     * silently disagree with any other consumer that decodes properly.
     *
     * Returns NULL for an absent/blank IP so callers keep the "no visitor IP
     * to hash" contract instead of storing a constant digest of "".
     */
    public static function hashIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, self::ipHashKey());
    }

    private static function ipHashKey(): string
    {
        $key = (string) config('partna.pre_account.ip_hash_key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        return $key;
    }

    /** Live = not yet claimed (the partial-unique-index predicate). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('claimed_at');
    }

    // Namespaced model — Laravel's default factory resolution can't find it
    // without this override (mirrors PartnaStaff::newFactory(), Site::newFactory()).
    protected static function newFactory(): PreAccountBuildFactory
    {
        return PreAccountBuildFactory::new();
    }
}
