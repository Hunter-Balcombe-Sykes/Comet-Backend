<?php

namespace App\Services\Platforms;

use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Support\Facades\Log;
use Throwable;

// Seeds Reservations / Online-ordering / Social connections from a Google
// Business Apify enrichment — only into slots the user hasn't filled, each
// tagged source:'google-business' so the connect modal's "Automatically Synced
// Integrations" step can list them with an undo.
//
// seed() RETURNS the per-connect findings (one per platform Google had a link
// for) so the modal can show only what THIS scrape produced, with a live status:
//   - outcome 'seeded'   → we wrote the row (the /synced endpoint re-derives
//                          synced vs syncing from its last_refresh_status);
//   - outcome 'conflict' → the slot was already filled by a different connection,
//                          so we didn't touch it — the finding carries an `apply`
//                          recipe so a "Change to" swap can remove the existing
//                          and install Google's (applyFinding()).
// Best-effort: every seed is isolated in its own try/catch so one failure never
// blocks the rest.
class GoogleBusinessAutoSync
{
    use BuildsAutoSyncFindings;

    private const MAX_ORDERING = 10;

    /**
     * The ordering FAMILY key — the seed lock's key, and nothing else. Ordering
     * rows themselves moved to per-brand surfaces in convergence Phase 6, but the
     * read-then-write span this lock protects still covers the whole family, so
     * the key must stay family-wide (and byte-identical to what
     * the retired ordering controller took (now only this class's), or writers stop excluding
     * each other).
     */
    private const ORDERING_FAMILY = 'online-ordering';

    // Real reservation brands only — the 'reservations' pseudo-platform
    // case left the enum 2026-08-19 with the pseudo-platform retirement.
    private const RESERVATION_PLATFORMS = [
        Platform::OpenTable->value, Platform::Resdiary->value, Platform::Nowbookit->value,
    ];

    private const BOOKING_PLATFORMS = [Platform::Fresha->value, Platform::Square->value];

    public function __construct(
        private readonly OpenTableService $openTable,
        private readonly ResDiaryService $resDiary,
        private readonly NowBookitService $nowBookit,
        private readonly ProviderDetector $detector,
        private readonly ApifyBudget $apifyBudget,
        private readonly FacebookNormalizer $facebookNormalizer,
        private readonly LinkRouter $linkRouter,
        private readonly IriCanonicalizer $canonicalizer,
        private readonly LinkProjector $projector,
    ) {}

    /**
     * Contract: $userId MUST be server-derived — the job payload the current
     * caller (GoogleBusinessEnrichJob::handle(), line ~137) was dispatched
     * with, never raw request input. There is no ownership check inside this
     * method (it writes IntegrationConnection rows keyed on the given id
     * unconditionally); a future controller-invoked caller must
     * authorizeForUser($user, 'update', ...) at the call site before
     * reaching here, the same way every other mutating controller path does.
     *
     * @param  array<string,mixed>  $enrichment  the scraper map() output (menu / reservation / order / booking / socials)
     * @param  array<string,mixed>|null  $gbPayload  the Google Business connection payload (Place Details: category / website / editorialSummary) for the workplace seed
     * @return list<array<string,mixed>> the per-connect findings (see class doc)
     */
    // $autoConnectBooking: TRUE only on a staff/ManyChat build, where nobody is
    // present to answer "whose menu is this?". Defaults FALSE so the dashboard
    // Google-Business connect and the public site-first signup keep showing the
    // account holder a picker.
    public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null, bool $autoConnectBooking = false): array
    {
        $findings = [];

        // Fresh lookup (not a caller-supplied instance) so this always reads the
        // CURRENT row — specifically, the sector IdentitySync just wrote earlier
        // in this same connect (IntegrationConnectionObserver::syncIdentityFromGoogle
        // runs synchronously off the connection save, before GoogleBusinessEnrichJob
        // is even dispatched; see GoogleBusinessController::connect). Capabilities
        // below are therefore evaluated AT SEED TIME, post-identity-sync, never off
        // a stale pre-connect snapshot.
        $user = User::find($userId);
        if ($user === null) {
            return $findings;
        }
        $capabilities = AccountCapabilities::for($user);

        // Booking (only-if-empty): synced for every account type EXCEPT a food
        // business, which books via Reservations instead (2026-07-15 sector
        // gating — replaces the old unconditional "everyone books" seed).
        // Independent of the Business-Partna block below — partna keeps this.
        if ($capabilities->can_use_booking) {
            $findings = [...$findings, ...$this->seedBooking($userId, $enrichment, $businessName, $autoConnectBooking)];
        }

        // Workplace (previous website / category / description) + socials are
        // seeded for EVERY account type (owner ruling R14, overnight
        // 2026-08-18): an individual who connects their Google listing gets the
        // website scan, logo, socials and booking too. Reservations + ordering
        // stay capability-gated (food business only — see can_use_* above),
        // which is what google_business_full_sync now means.
        $this->seedWorkplace($userId, $gbPayload ?? []);

        // M-12 (B6 DOH live): when the listing's "website" IS a platform page
        // (DOH lists their Instagram profile as the website), the Apify
        // contacts add-on crawled THAT page — so its socials are the
        // platform's own chrome links (developers.facebook.com/docs/… seeded
        // facebook username "docs"), not the business's accounts. The divert
        // in seedWorkplace() already routes the website itself through the
        // engine; chrome socials are dropped wholesale.
        $website = $this->safeUrl(data_get($gbPayload ?? [], 'website'));
        if ($website !== null && app(PreviousWebsiteGate::class)->isPlatformUrl($website)) {
            Log::info('google_business.socials_skipped_platform_website', [
                'user_id' => $userId,
                'host' => parse_url($website, PHP_URL_HOST),
            ]);
        } else {
            $findings = [...$findings, ...$this->seedSocials($userId, $enrichment, $autoConnectBooking)];
        }

        if (! $capabilities->google_business_full_sync) {
            return $findings;
        }

        if ($capabilities->can_use_reservations) {
            $findings = [...$findings, ...$this->seedReservation($userId, $enrichment, $businessName)];
        }
        if ($capabilities->can_use_online_ordering) {
            $findings = [...$findings, ...$this->seedOrdering($userId, $enrichment)];
        }

        return $findings;
    }

    /**
     * The Instagram half of applyFinding()'s hook (BuildsAutoSyncFindings):
     * when the recipe carries `apply.instagram`, re-dispatch the scrape
     * instead of falling through to the generic `write` branch — GB never
     * writes a raw Instagram card, it always re-runs the budgeted scrape (see
     * dispatchInstagram()). Claiming the finding here also preserves the
     * original early-return: an Instagram dispatch never ALSO runs the write.
     *
     * @param  array<string,mixed>  $apply
     */
    protected function applyFindingHandled(string $userId, array $apply): bool
    {
        if (is_array($apply['instagram'] ?? null) && is_string($apply['instagram']['username'] ?? null)) {
            $this->dispatchInstagram($userId, $apply['instagram']['username']);

            return true;
        }

        return false;
    }

    // ── reservation ──────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedReservation(string $userId, array $enrichment, ?string $businessName): array
    {
        try {
            // Pure — no DB — so it stays outside the lock below.
            $write = $this->resolveReservationWrite($userId, $enrichment, $businessName);
            if ($write === null) {
                return [];
            }

            $label = $write['payload']['name'] ?? 'Reservations';

            // PWL-9: hasAnyReservation()-then-write() spans opentable/resdiary/
            // nowbookit/reservations — a cross-platform single-slot check
            // structurally identical to seedBooking's XOR, so it needs the
            // shared reservations-XOR lock (not a per-platform lock — that
            // would be the wrong-key bug PWL-14/15 fixed for the controller
            // side). The has-check MUST re-run INSIDE the closure — it's the
            // read half of check-then-write.
            return $this->withReservationsXorLock($userId, function () use ($userId, $write, $label) {
                if ($this->hasAnyReservation($userId)) {
                    return [$this->conflictFinding($write['platform'], $write['resourceId'], 'reservations', is_string($label) ? $label : 'Reservations', $this->urlOf($write), [
                        'remove' => self::RESERVATION_PLATFORMS,
                        'write' => $write,
                    ])];
                }

                $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

                return [$this->seededFinding($write['platform'], $write['resourceId'], 'reservations', is_string($label) ? $label : 'Reservations', $this->urlOf($write))];
            }, []);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Resolve the single reservation row Google's data would write — a keyless
     * provider (OpenTable / ResDiary / NowBookit) or a branded custom card — or
     * null when there's nothing usable. Pure: no DB writes, so it can be reused to
     * build a conflict's "Change to" recipe.
     *
     * @return array{platform:string, resourceId:string, payload:array<string,mixed>}|null
     */
    private function resolveReservationWrite(string $userId, array $enrichment, ?string $businessName): ?array
    {
        $reservation = data_get($enrichment, 'reservation');
        if (! is_array($reservation)) {
            return null;
        }

        $candidates = array_values(array_filter([
            data_get($reservation, 'url'),
            ...array_map(fn ($l) => data_get($l, 'url'), (array) data_get($reservation, 'links', [])),
        ], fn ($u) => is_string($u) && $u !== ''));

        foreach ($candidates as $url) {
            if ($this->openTable->isOpenTableUrl($url) && ($rid = $this->openTable->parseRid($url)) !== null) {
                return ['platform' => Platform::OpenTable->value, 'resourceId' => Platform::OpenTable->value, 'payload' => [
                    'url' => $url, 'rid' => $rid, 'name' => $businessName,
                    'embedUrl' => $this->openTable->embedUrl($rid, $this->openTable->hostOf($url)),
                    'source' => 'google-business',
                ]];
            }
            if ($this->resDiary->isResDiaryUrl($url) && ($embed = $this->resDiary->embedUrl($url)) !== null) {
                return ['platform' => Platform::Resdiary->value, 'resourceId' => Platform::Resdiary->value, 'payload' => [
                    'url' => $url, 'microsite' => $this->resDiary->parseMicrosite($url),
                    'name' => $this->resDiary->nameFromUrl($url) ?? $businessName,
                    'embedUrl' => $embed, 'source' => 'google-business',
                ]];
            }
            if ($this->nowBookit->isNowBookitUrl($url) && ($ids = $this->nowBookit->parseIds($url)) !== null) {
                return ['platform' => Platform::Nowbookit->value, 'resourceId' => Platform::Nowbookit->value, 'payload' => [
                    'url' => $url, 'accountId' => $ids['accountId'], 'venueId' => $ids['venueId'],
                    'name' => $this->nowBookit->nameFromUrl($url) ?? $businessName,
                    'embedUrl' => $this->nowBookit->embedUrl($ids['accountId'], $ids['venueId']),
                    'source' => 'google-business',
                ]];
            }
        }

        $url = $this->safeUrl(data_get($reservation, 'url'));
        if ($url === null) {
            return null;
        }

        // Convergence Phase 6: the shared 'reservations' pseudo-key is retired,
        // so the brand is resolved from the host. A link matching NO reservation
        // brand is DROPPED (owner, 2026-08-19 — ruling 2A retired): a background
        // harvest does not publish a public link card nobody asked for.
        // Returning null is how this says "no connection for this one".
        $surface = $this->brandSurfaceFor($url, 'reservations');
        if ($surface === null) {
            Log::info('platforms.google_business.reservation_unroutable', [
                'user_id' => $userId, 'host' => parse_url($url, PHP_URL_HOST),
            ]);

            return null;
        }

        return ['platform' => $surface, 'resourceId' => LegacyPlatformMap::legacyFor($surface), 'payload' => [
            'provider' => 'custom', 'url' => $url,
            'name' => $this->clean(data_get($reservation, 'provider')) ?? $businessName,
            'favicon' => null, 'logo' => null, 'source' => 'google-business',
        ]];
    }

    private function hasAnyReservation(string $userId): bool
    {
        foreach (self::RESERVATION_PLATFORMS as $platform) {
            if ($this->has($userId, $platform)) {
                return true;
            }
        }

        return false;
    }

    // ── booking ──────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedBooking(string $userId, array $enrichment, ?string $businessName, bool $autoConnectBooking = false): array
    {
        try {
            // Pure — no DB — so it stays outside the lock below.
            $write = $this->resolveBookingWrite($userId, $enrichment, $businessName);
            if ($write === null) {
                return [];
            }

            $label = match ($write['platform']) {
                Platform::Fresha->value => 'Fresha', Platform::Square->value => 'Square', default => $write['payload']['name'] ?? 'Booking',
            };

            // LIFE-105: the has()-then-write() check spans fresha/square/booking —
            // a per-platform lock can't serialize that, so the whole check+write
            // runs under the shared booking-XOR lock (BuildsAutoSyncFindings::
            // withBookingXorLock). The has() check MUST re-run INSIDE the closure
            // (it's the read half of check-then-write) — moving only the write
            // in would still let two concurrent seeds both pass a stale check.
            // On lock contention the default is "do nothing" (empty findings) —
            // this is a best-effort auto-sync, not a user-initiated write, so a
            // dropped seed under contention is safe (the outer try/catch below
            // still applies to whatever the closure itself throws).
            $findings = $this->withBookingXorLock($userId, function () use ($userId, $write, $label) {
                if (collect(self::BOOKING_PLATFORMS)->contains(fn ($p) => $this->has($userId, $p))) {
                    return [$this->conflictFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write), [
                        'remove' => self::BOOKING_PLATFORMS,
                        'write' => $write,
                    ])];
                }

                $this->write($userId, $write['platform'], $write['resourceId'], $write['payload']);

                return [$this->seededFinding($write['platform'], $write['resourceId'], 'booking', is_string($label) ? $label : 'Booking', $this->urlOf($write))];
            }, []);

            // Auto-connect the menu, on the same terms as the Instagram side:
            // only a real seed (a conflict or lock contention wrote nothing to
            // fetch for), only Fresha, only a marked origin, only with the kill
            // switch on.
            if ($autoConnectBooking
                && $write['platform'] === Platform::Fresha->value
                && ($findings[0]['outcome'] ?? null) === 'seeded'
                && (bool) config('partna.connect.auto_booking.enabled', true)
            ) {
                $this->dispatchAutoBookingConnect($userId);
            }

            return $findings;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The single booking row Google's data would write: a PENDING Fresha (url
     * only, "Finish setup"), a complete Square "Book now", or a branded custom
     * card. Null when Google has no booking link. Pure (no DB writes).
     *
     * @return array{platform:string, resourceId:string, payload:array<string,mixed>}|null
     */
    private function resolveBookingWrite(string $userId, array $enrichment, ?string $businessName): ?array
    {
        $links = data_get($enrichment, 'booking');
        $url = is_array($links) ? $this->safeUrl($links[0] ?? null) : null;
        if ($url === null) {
            return null;
        }

        $provider = $this->detector->detectFor('booking', $url);
        if ($provider === Platform::Fresha->value) {
            // Canonicalise to /a/<slug>, exactly as resolveWrite() does for the
            // Instagram side. Google listings carry the same share-URL shape
            // (/book-now/<slug>/all-offer), and slugFromUrl() only understands
            // /a/<slug> — stored raw, the auto-selection's employee leg is
            // impossible and every match silently degrades to storewide.
            return ['platform' => Platform::Fresha->value, 'resourceId' => Platform::Fresha->value, 'payload' => [
                'url' => app(FreshaScraper::class)->canonicalUrl($url), 'selection' => null, 'source' => 'google-business',
            ]];
        }
        if ($provider === Platform::Square->value) {
            return ['platform' => Platform::Square->value, 'resourceId' => Platform::Square->value, 'payload' => [
                'url' => $url, 'source' => 'google-business',
            ]];
        }

        // Convergence Phase 6 + owner ruling 2026-08-16: a booking link no brand
        // claims falls to `direct.book` rather than being dropped. Google's
        // "Book online" is usually the merchant's OWN domain, which matches no
        // brand by construction, and it had a working Book button before this
        // phase — see DirectBooking's docblock.
        $surface = $this->brandSurfaceFor($url, 'booking') ?? 'direct.book';

        return ['platform' => $surface, 'resourceId' => LegacyPlatformMap::legacyFor($surface), 'payload' => [
            'provider' => 'custom', 'url' => $url, 'name' => $businessName,
            'favicon' => null, 'logo' => null, 'source' => 'google-business',
        ]];
    }

    /**
     * The catalog surface a harvested URL belongs to within one routing
     * category, or null when nothing recognises it. Identical in shape to
     * BookingController::bookingSurfaceFor / ReservationsController::
     * reservationSurfaceFor — the same question, asked from the harvest side.
     */
    private function brandSurfaceFor(string $url, string $category): ?string
    {
        $classified = app(WebsiteLinkHarvester::class)->classify($url);
        if ($classified === null || $classified['category'] !== $category) {
            return null;
        }

        $platform = $classified['platform'];

        return LegacyPlatformMap::surfaceFor($platform) ?? $platform;
    }

    // ── workplace ─────────────────────────────────────────────────

    // Fill the workplace card's previous-website / category / description from
    // the Google Business Place Details — per field, only when the user hasn't
    // set it. Never seeds the identity fields (name/address). Writes to
    // site.workplaces (FOUND-4 — promoted from settings JSONB).
    //
    // @param array<string,mixed> $gbPayload
    private function seedWorkplace(string $userId, array $gbPayload): void
    {
        try {
            // A listing whose "website" is a platform page (a Fresha booking
            // link, an Instagram profile, an ordering storefront) is a
            // platform candidate, not a previous website (owner, 2026-08-19 —
            // the Fresha-favicon-as-logo incident): it goes to the router,
            // and previous_website stays untouched.
            $website = $this->safeUrl(data_get($gbPayload, 'website'));
            if ($website !== null) {
                $gate = app(PreviousWebsiteGate::class);
                if ($gate->isPlatformUrl($website)) {
                    $owner = User::query()->find($userId);
                    if ($owner !== null) {
                        // Origin 'google_business', NOT a bespoke string: the
                        // routing.* CHECK constraints only accept the
                        // RoutingContext::ORIGINS set, and the bespoke
                        // 'google_business_website' rolled back the applied
                        // Instagram connect it was diverting (M-12, B6 live).
                        $gate->divert($owner, $website, 'google_business');
                    }
                    $website = null;
                }
            }
            $fields = array_filter([
                'previous_website' => $website,
                'category' => $this->truncate($this->clean(data_get($gbPayload, 'category')), 120),
                'description' => $this->truncate(
                    $this->clean(data_get($gbPayload, 'editorialSummary'))
                        ?? $this->clean(data_get($gbPayload, 'reviewSummary')),
                    1000,
                ),
            ], fn ($v) => $v !== null);
            if ($fields === []) {
                return;
            }

            $site = Site::query()->where('user_id', $userId)->first();
            if ($site === null) {
                return;
            }

            // Load existing row (or a fresh unsaved model) to preserve fields the user already set.
            $workplace = Workplace::query()->firstOrNew(['site_id' => (string) $site->id]);
            $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
            $stamp = now()->toIso8601String();

            $changed = false;
            foreach ($fields as $key => $value) {
                // Only fill if the column is currently blank — never overwrite user data.
                if ($this->blank($workplace->{$key} ?? null)) {
                    $workplace->{$key} = $value;
                    // Same field_sources shape IdentitySync stamps, so a future
                    // "synced from Google" badge works for every source, not
                    // just IdentitySync's own writes.
                    $sources[$key] = ['source' => 'google-business', 'at' => $stamp];
                    $changed = true;
                }
            }

            if ($changed) {
                $workplace->field_sources = $sources;
                $workplace->save();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ── ordering ─────────────────────────────────────────────────

    /**
     * Seed ordering links from the Google order panel, ONE consolidated row per
     * store. Google lists each provider×mode as a separate action (Uber Eats
     * pickup, Uber Eats delivery, DoorDash pickup, …); we group those by store
     * (host + path, query stripped) and write a single row carrying both the
     * pickup and delivery URLs under `data`, mirroring OnlineOrderingController's
     * merge-on-add — so the same store never appears twice. Only-if-empty PER
     * STORE: a store the user already has (manual add or a prior harvest) is left
     * untouched.
     *
     * @return list<array<string,mixed>>
     */
    private function seedOrdering(string $userId, array $enrichment): array
    {
        try {
            // Pure grouping — no DB — so it stays outside the lock below.
            $providers = data_get($enrichment, 'order.providers');
            if (! is_array($providers)) {
                return [];
            }

            // Group providers by store, in first-seen order.
            $stores = [];
            foreach ($providers as $p) {
                $url = $this->safeUrl(data_get($p, 'url'));
                if ($url === null) {
                    continue;
                }
                $key = $this->storeKey($url) ?? $url;
                $stores[$key] ??= [];
                $stores[$key][] = $p;
            }

            // PWL-9: the eager-load + per-store only-if-empty write loop is a
            // classic read-then-write span against this user's online-ordering
            // rows, so it needs the same lock a concurrent dashboard write to
            // one of those rows would take. $ran tracks whether the closure
            // actually executed (vs. skipped on lock contention) — the
            // MenuFetchJob dispatch below must NOT fire on a dropped seed
            // (nothing changed), but MUST fire whenever the seed ran, even if
            // every store in this batch was a dupe (mirrors the pre-lock
            // unconditional dispatch for that case).
            $ran = false;
            $findings = $this->withPlatformSeedLock($userId, self::ORDERING_FAMILY, function () use ($userId, $stores, &$ran) {
                $ran = true;
                $findings = [];

                // Eager-load all existing ordering rows once. Without this, hasStoreKey
                // and count() both query the table on every iteration of $stores, turning
                // an N-store enrichment into 2N+1 round-trips.
                //
                // Convergence Phase 6: scoped on routing_class, because these rows
                // now sit on per-brand surfaces (uber_eats.order, doordash.order,
                // …). Left on the retired 'online-ordering' slug this read would
                // return nothing, the only-if-empty guard below would never fire,
                // and every Google enrichment would re-seed stores the user
                // already has.
                $existingOrdering = IntegrationConnection::query()
                    ->where('user_id', $userId)
                    ->where('routing_class', 'ordering')
                    ->get();
                $existingCount = $existingOrdering->count();
                // Key by storeKey for O(1) duplicate detection.
                $existingStoreKeys = $existingOrdering->mapWithKeys(function (IntegrationConnection $row) {
                    $key = $this->storeKey(CardPayload::fromArray($row->payload)->url()) ?? '';

                    return [$key => true];
                })->all();

                foreach ($stores as $storeKey => $group) {
                    if ($existingCount >= self::MAX_ORDERING) {
                        break;
                    }
                    if ($existingStoreKeys[$storeKey] ?? false) {
                        continue;   // only-if-empty per store — never clobber an existing one
                    }

                    // Representative row identity: prefer a delivery-typed provider
                    // (the common ordering intent), else the first in the group.
                    $primary = $this->preferredProvider($group);
                    $repUrl = $this->safeUrl(data_get($primary, 'url'));
                    if ($repUrl === null) {
                        continue;
                    }
                    $name = $this->clean(data_get($primary, 'name'));
                    $rid = 'order-'.substr(sha1(strtolower($repUrl)), 0, 16);

                    // Gather the pickup + delivery URLs across the store's providers.
                    $pickupUrl = $this->modeUrl($group, 'pickup');
                    $deliveryUrl = $this->modeUrl($group, 'delivery');

                    // Convergence Phase 6: LinkRouter decides the brand surface
                    // and refuses a second store for a brand this user already
                    // has (owner ruling 1). It writes its own auto-seeded card
                    // first; the Google card below replaces it, because only this
                    // path knows the fees/time/pickup/delivery metadata Google
                    // gave us — none of which the router can see.
                    $user = User::find($userId);
                    $routed = $user === null
                        ? null
                        : $this->linkRouter->routeOrdering($user, $repUrl);

                    if ($routed === null || $routed->outcome !== 'seeded') {
                        // Owner ruling 2A is RETIRED here (owner, 2026-08-19).
                        // Neither branch below publishes a links-pool card: a
                        // background harvest putting an unasked-for public link
                        // on someone's site was never a decision they made, and
                        // it left them no way to say "use that one instead".
                        //
                        //  · handled  — the brand's one slot is filled by a
                        //    different store. LinkRouter has already recorded a
                        //    cap-blocked intent naming the incumbent, which the
                        //    suggestions inbox renders as **Swap**.
                        //  · unhandled — no brand home at all. Logged and
                        //    dropped: an ordering link we cannot type is nearly
                        //    always a marketplace redirector rather than the
                        //    merchant's own order page, so there is no card
                        //    shape to give it and nothing to suggest.
                        $existingStoreKeys[$storeKey] = true;

                        Log::info($routed?->handled
                            ? 'platforms.google_business.ordering_slot_taken'
                            : 'platforms.google_business.ordering_unroutable', [
                                'user_id' => $userId,
                                'host' => parse_url($repUrl, PHP_URL_HOST),
                            ]);

                        continue;
                    }

                    $surface = $routed->platform;
                    $rid = $routed->resourceId;

                    $this->write($userId, $surface, $rid, [
                        'id' => $rid,
                        'provider' => 'custom',
                        'url' => $repUrl,
                        'name' => $name ?? 'Order online',
                        'favicon' => null,
                        'logo' => null,
                        'source' => 'google-business',
                        'data' => array_filter([
                            'type' => $this->clean(data_get($primary, 'type')),
                            'fees' => $this->clean(data_get($primary, 'fees')),
                            'time' => $this->clean(data_get($primary, 'time')),
                            'sourcePlatform' => $name,
                            'pickupUrl' => $pickupUrl,
                            'deliveryUrl' => $deliveryUrl,
                        ], fn ($v) => $v !== null),
                    ]);
                    // Track the newly written store in-memory so the cap and dupe
                    // checks stay accurate without re-querying on each iteration.
                    $existingCount++;
                    $existingStoreKeys[$storeKey] = true;
                    // Online-ordering is multi-entry — every new store is just added (no
                    // conflict concept), so each is a 'seeded' finding. The finding's
                    // `platform` is the BRAND surface now; its category stays
                    // 'online-ordering', which is what the modal keys its copy on.
                    $findings[] = $this->seededFinding($surface, $rid, 'online-ordering', $name ?? 'Order online', $repUrl);
                }

                return $findings;
            }, []);

            // Ordering links changed → (re)derive the shared menu from them. Kept
            // OUTSIDE the lock (dispatch, not DB work) — MenuFetchJob runs INLINE
            // under the sync queue driver, so holding the lock across it would
            // needlessly extend the contention window. Suppressed on a lock
            // timeout ($ran stays false): nothing changed, so nothing to re-derive.
            if ($ran) {
                MenuFetchJob::dispatch($userId);
            }

            return $findings;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The provider that should own a store's consolidated row — the first
     * delivery-typed one, else the first provider.
     *
     * @param  list<array<string,mixed>>  $group
     * @return array<string,mixed>
     */
    private function preferredProvider(array $group): array
    {
        foreach ($group as $p) {
            if ($this->clean(data_get($p, 'type')) === 'delivery') {
                return $p;
            }
        }

        return $group[0];
    }

    /**
     * The first URL in a store's providers carrying the given mode (pickup /
     * delivery), or null.
     *
     * @param  list<array<string,mixed>>  $group
     */
    private function modeUrl(array $group, string $mode): ?string
    {
        foreach ($group as $p) {
            if ($this->clean(data_get($p, 'type')) === $mode) {
                return $this->safeUrl(data_get($p, 'url'));
            }
        }

        return null;
    }

    /**
     * A store grouping key — "<host>|<path>", query + fragment + trailing slash
     * stripped (so Uber Eats ?diningMode / DoorDash ?pickup variants of one store
     * collapse). Null for a non-URL input. Mirrors OnlineOrderingController.
     */
    private function storeKey(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.'|'.$path;
    }

    // ── socials ──────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function seedSocials(string $userId, array $enrichment, bool $autoConnectBooking = false): array
    {
        $socials = data_get($enrichment, 'socials');
        if (! is_array($socials)) {
            return [];
        }

        $findings = [];
        $linkOnly = ['facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'X', 'linkedin' => 'LinkedIn'];
        $platformOf = ['facebook' => 'facebook', 'tiktok' => 'tiktok', 'twitter' => 'x', 'linkedin' => 'linkedin'];
        $surfaceOf = ['facebook' => 'facebook.profile', 'tiktok' => 'tiktok.profile', 'twitter' => 'x.profile', 'linkedin' => 'linkedin.profile'];
        foreach ($linkOnly as $socialKey => $label) {
            try {
                $url = $this->safeUrl(data_get($socials, $socialKey));
                if ($url === null) {
                    continue;
                }
                $platform = $platformOf[$socialKey];

                // G4-4, extended to every link-only social (M-12, B6 live):
                // only a URL the catalog projects as this platform's PROFILE
                // surface seeds a connection — the projector rejects foreign
                // subdomains (developers.facebook.com/docs/… scraped off an
                // Instagram-as-website page seeded facebook username "docs")
                // and reserved segments. Facebook keeps a normalizer fallback
                // for the legacy shapes the catalog doesn't model
                // (/pages/<Name>/<id>, profile.php?id=), but only on
                // facebook.com / fb.com hosts proper.
                $projection = $this->projector->project($this->canonicalizer->canonicalize($url));
                $username = null;
                if ($projection->surfaceKey === $surfaceOf[$socialKey]
                    && is_string($projection->identifier) && $projection->identifier !== '') {
                    $username = $projection->identifier;
                } elseif ($socialKey === 'facebook' && $this->isCanonicalFacebookHost($url)) {
                    $username = $this->socialUsername('facebook', $url);
                }
                if ($username === null || $username === '') {
                    Log::info('google_business.social_rejected', [
                        'user_id' => $userId,
                        'platform' => $platform,
                        'host' => parse_url($url, PHP_URL_HOST),
                    ]);

                    continue;
                }

                $payload = ['username' => $username, 'url' => $url, 'source' => 'google-business'];
                $write = ['platform' => $platform, 'resourceId' => $platform, 'payload' => $payload];

                if ($this->has($userId, $platform)) {
                    $findings[] = $this->conflictFinding($platform, $platform, 'social', $label, $url, [
                        'remove' => [$platform], 'write' => $write,
                    ]);

                    continue;
                }
                $this->write($userId, $platform, $platform, $payload);
                $findings[] = $this->seededFinding($platform, $platform, 'social', $label, $url);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $igUrl = $this->safeUrl(data_get($socials, 'instagram'));
            $igFinding = $this->seedInstagram($userId, $igUrl, $autoConnectBooking);
            if ($igFinding !== null) {
                $findings[] = $igFinding;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $findings;
    }

    /** @return array<string,mixed>|null */
    private function seedInstagram(string $userId, ?string $url, bool $autoConnectBooking = false): ?array
    {
        if ($url === null) {
            return null;
        }

        // Same lesson as socialUsername()'s facebook arm (G4-4), learned again
        // live 2026-08-20: the standalone regex here matched the reserved
        // segment of an instagram.com/reel/<shortcode> link on a business's
        // listing and Apify-scraped literal username "reel" — a 9M-follower
        // stranger — into an auto-connected account. Delegate to the catalog
        // projection, which only yields an identifier for a real profile path.
        $projection = $this->projectInstagramProfile($url);
        if ($projection->surfaceKey !== 'instagram.profile' || ! is_string($projection->identifier) || $projection->identifier === '') {
            return null;
        }
        $username = $projection->identifier;

        $existing = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::Instagram->value)
            ->first();

        if ($existing !== null) {
            // M-2 (matrix run 2, live): a retried enrich (attempt 1 killed
            // mid-flight between the placeholder write and the scrape
            // dispatch / its run) used to see its OWN half-finished
            // placeholder here and file a conflict — stranding a "pending"
            // connection with no username forever, because nothing ever
            // re-dispatches a lost InstagramConnectJob. A pending placeholder
            // that THIS seeder created (payload source google-business) is an
            // unfinished obligation, not a conflict: re-dispatch the scrape
            // (idempotent — uniqueId is connectionId:username, so a live
            // duplicate coalesces). A real user connection, or an enriched
            // seed, still conflicts exactly as before.
            $payload = CardPayload::fromArray((array) $existing->payload);
            // STALE only (suite catch on the first cut): a fresh pending
            // placeholder means the scrape is IN FLIGHT — re-dispatching there
            // re-claims an Apify budget slot per repeat scan (the #JOB-1 money
            // hole ScanPreviousWebsiteContentJobTest pins). 16 minutes clears
            // InstagramConnectJob's 15-minute retryUntil window, so a pending
            // row older than that has no live job left and IS stranded.
            $stranded = $existing->last_refresh_status === 'pending'
                && $payload->source() === 'google-business'
                && $existing->updated_at !== null
                && $existing->updated_at->lt(now()->subMinutes(16));
            if ($stranded) {
                if (! $this->dispatchInstagram($userId, $username, $autoConnectBooking)) {
                    return null;
                }

                return $this->seededFinding(Platform::Instagram->value, Platform::Instagram->value, 'social', 'Instagram', $url);
            }

            return $this->conflictFinding(Platform::Instagram->value, Platform::Instagram->value, 'social', 'Instagram', $url, [
                'remove' => [Platform::Instagram->value], 'instagram' => ['username' => $username],
            ]);
        }

        if (! $this->dispatchInstagram($userId, $username, $autoConnectBooking)) {
            return null;   // no Apify token / budget exhausted — nothing seeded, no card
        }

        return $this->seededFinding(Platform::Instagram->value, Platform::Instagram->value, 'social', 'Instagram', $url);
    }

    /**
     * Write the pending Instagram placeholder + dispatch the budgeted scrape.
     * Returns false when there's no token, the daily budget is spent, or the
     * platform seed lock timed out (skip: no card, no dispatch).
     */
    /**
     * Catalog projection with one retry for profile SUB-TAB share links
     * (/<handle>/reels/, /<handle>/tagged/ — Instagram's own "share this
     * tab" URLs): the profile detector matches the bare profile path only,
     * so those project to nothing on the first pass (critic catch,
     * 2026-08-20 retest). The retry cuts the path to its first segment —
     * the catalog still rejects reserved segments (reel/p/stories/explore),
     * so this cannot resurrect the junk the projection was brought in to
     * stop. Strictly better than the old regex, which extracted "stories"
     * from /stories/<handle>/<id> as a username.
     */
    private function projectInstagramProfile(string $url): \App\Routing\Projection
    {
        $projection = $this->projector->project($this->canonicalizer->canonicalize($url));
        if ($projection->surfaceKey === 'instagram.profile') {
            return $projection;
        }

        $parts = parse_url($url);
        $first = explode('/', trim((string) ($parts['path'] ?? ''), '/'))[0] ?? '';
        if ($first === '' || ! isset($parts['host'])) {
            return $projection;
        }

        return $this->projector->project($this->canonicalizer->canonicalize(
            ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/'.$first
        ));
    }

    private function dispatchInstagram(string $userId, string $username, bool $autoConnectBooking = false): bool
    {
        if (! config('services.apify.token') || ! $this->apifyBudget->tryClaim('instagram')) {
            return false;
        }

        // PWL-9: only the placeholder write is locked. InstagramConnectJob —
        // dispatched below, OUTSIDE the lock — takes the SAME platformConnectionLock
        // key (InstagramConnectionSeeder::seed) and runs INLINE under the sync
        // queue driver; dispatching it while still holding this lock would
        // self-deadlock (or time itself out against its own holder).
        $connection = $this->withPlatformSeedLock($userId, Platform::Instagram->value, function () use ($userId) {
            // Pending placeholder tagged source so the synced step + undo can find it;
            // InstagramConnectJob preserves that tag when it writes the scrape result.
            return IntegrationConnection::updateOrCreate(
                ['user_id' => $userId, 'platform' => Platform::Instagram->value, 'resource_id' => Platform::Instagram->value],
                [
                    'payload' => ['source' => 'google-business'],
                    'is_active' => false,
                    'last_refreshed_at' => null,
                    'last_refresh_status' => 'pending',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ],
            );
        }, null);

        if ($connection === null) {
            return false;   // lock timeout — skip: no card, no dispatch
        }

        InstagramConnectJob::dispatch($userId, $username, $connection->id, autoConnectBooking: $autoConnectBooking);

        return true;
    }

    // ── findings ──────────────────────────────────────────────────
    // seededFinding() / conflictFinding() / write() / applyFinding() moved to
    // BuildsAutoSyncFindings (SLOP-101) — was byte-identical with InstagramAutoSync.

    /** @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write */
    private function urlOf(array $write): ?string
    {
        $url = $write['payload']['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    // ── helpers ──────────────────────────────────────────────────

    private function has(string $userId, string $platform, ?string $resourceId = null): bool
    {
        $q = IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform);
        if ($resourceId !== null) {
            $q->where('resource_id', $resourceId);
        }

        return $q->exists();
    }

    /**
     * The hosts FacebookNormalizer's legacy-shape fallback may run against.
     * The normalizer's regex matches ANY *.facebook.com substring, which is
     * how developers.facebook.com/docs/instagram parsed to username "docs"
     * (M-12) — so the fallback is host-gated here instead of trusting it.
     */
    private function isCanonicalFacebookHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^(www\.|m\.)/', '', $host);

        return in_array($host, ['facebook.com', 'fb.com', 'l.facebook.com', 'lm.facebook.com'], true);
    }

    /** Best-effort handle from a canonical social profile URL ('' when none). */
    private function socialUsername(string $platform, string $url): string
    {
        if ($platform === 'facebook') {
            // Delegate to the same parser the manual connect form uses (G4-4).
            // This used to be a standalone regex duplicating that logic, which
            // is exactly how it silently drifted out of sync and kept storing
            // the literal reserved segment ("pages") as the username for a
            // legacy facebook.com/pages/<Name>/<id> link discovered on a
            // business's own website.
            $parsed = ($this->facebookNormalizer)($url);

            return $parsed['username'] ?? '';
        }

        $patterns = [
            'tiktok' => '~tiktok\.com/@?([A-Za-z0-9._]+)~i',
            'x' => '~(?:twitter|x)\.com/([A-Za-z0-9_]+)~i',
            'linkedin' => '~linkedin\.com/(?:in|company)/([A-Za-z0-9-]+)~i',
        ];
        if (isset($patterns[$platform]) && preg_match($patterns[$platform], $url, $m)) {
            return strtolower($m[1]) === 'profile.php' ? '' : $m[1];
        }

        return '';
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }

    private function truncate(?string $value, int $max): ?string
    {
        return $value !== null ? mb_substr($value, 0, $max) : null;
    }
}
