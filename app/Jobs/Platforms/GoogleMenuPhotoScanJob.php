<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Content\ManualMenuItems;
use App\Services\Platforms\GoogleMenuImagesScraper;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Automatic menu scan from a connected Google Business listing's photos —
// dispatched after EVERY GBP enrichment (owner 2026-07-17: "always try"),
// delayed a few minutes so a same-connect MenuFetchJob scrape settles first.
// The scan ENRICHES rather than replaces: MenuScanApplier's matching adds
// longer descriptions + dietary badges to items a platform scrape already
// created, and only genuinely new dishes become scan-owned rows.
//
// Photo sourcing, cheapest first:
//   1. FREE — the Place-Details photos already stored on the connection
//      payload (GoogleBusinessService resolves ≤10 servable URLs weekly).
//   2. PAID — one budget-gated Apify photo-stream sweep
//      (GoogleMenuImagesScraper) when the free tier read nothing menu-like.
// Menu detection is OCR text DENSITY, not vision classification: a menu
// board OCRs to hundreds of characters, a dish photo to ~nothing — so the
// filter is a byte-length threshold on Mistral's own output.
//
// The extracted items also persist on menus.scan_items so MenuFetchJob's
// wholesale rebuilds can re-apply the enrichment without re-billing OCR.
class GoogleMenuPhotoScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * T6/D5 (2026-08-27): an ordering platform supplying at least this many
     * menu items makes the OCR scan ENRICH-ONLY (no new scan-owned items).
     */
    public const PLATFORM_MENU_SUFFICIENT = 8;

    /** OCR text shorter than this is not a menu photo (density filter). */
    private const MENU_TEXT_MIN_CHARS = 150;

    /** Hard cap on billed OCR calls per scan, across both photo tiers. */
    private const MAX_OCR_CALLS = 30;

    /** Stop OCR-ing once this many menu-dense photos are in hand. */
    private const ENOUGH_DENSE_PAGES = 4;

    private const COMBINED_TEXT_CAP = 60000;

    public int $timeout = 280;

    // AI spend: no automatic retries — a failed scan logs and waits for the
    // next enrichment rather than re-billing OCR on a flaky afternoon.
    // ($backoff is moot at one attempt; declared for the job-hygiene policy.)
    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [60];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userId,
        public readonly string $placeId,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->placeId;
    }

    /**
     * T6 (2026-08-27) → 9e (2026-09-01): the post-enrichment dispatch. The
     * old 5-minute hold existed purely so a same-connect MenuFetchJob settled
     * before the scan read the menu — a blind timer. Now the wait IS the
     * event: with an ordering platform whose fetch hasn't settled, this
     * defers entirely and MenuFetchJob's own completion (every terminal
     * path, including failed()) chains the scan via chainAfterMenuSettled().
     * A user with no ordering platform — or one whose fetch already settled —
     * gets the scan immediately; the scan is often their ONLY menu source.
     */
    public static function dispatchAfterEnrich(string $userId, string $placeId): void
    {
        // routing_class is the catalog's own vocabulary for "this connection
        // orders food" — env-proof (the legacy `platform` column is GENERATED
        // on Postgres and absent in sqlite tests) and covers every ordering
        // platform without re-listing them.
        $hasOrderingPlatform = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('routing_class', 'ordering')
            ->exists();

        $fetchSettled = ! $hasOrderingPlatform || Menu::query()
            ->where('user_id', $userId)
            ->whereIn('fetch_status', ['ok', 'unavailable'])
            ->exists();

        if ($fetchSettled) {
            self::dispatch($userId, $placeId);

            return;
        }

        Log::info('menu_photo_scan.deferred_to_menu_fetch', ['user_id' => $userId]);
    }

    /**
     * 9e: the completion half of the deferral above — called from every
     * terminal path of MenuFetchJob. Dispatches the photo scan the moment the
     * ordering fetch settles (success OR terminal failure) instead of the old
     * fixed 5-minute head start. placeId is read off the user's own GBP
     * connection; no GBP connection → nothing to scan from → no-op. The
     * ShouldBeUnique lock (uniqueFor 3600) coalesces a re-fetch's re-chain
     * with any scan already in flight.
     */
    public static function chainAfterMenuSettled(string $userId): void
    {
        // first()?->payload, not value('payload'): value() bypasses the model's
        // array cast and would hand fromArray() a raw JSON string.
        $payload = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first()?->payload;

        $placeId = is_array($payload) ? GoogleBusinessPayload::fromArray($payload)->placeId() : null;
        if ($placeId === null || $placeId === '') {
            return;
        }

        self::dispatch($userId, $placeId);
    }

    /**
     * Whether an ordering platform already supplies a sufficient menu: a
     * successful platform fetch on record AND ≥ PLATFORM_MENU_SUFFICIENT
     * items in the menu lane. (Item provenance is flattened today — issue
     * "menu provenance" in the 2026-08-27 plan — so the fetch stamp carries
     * the platform half of the signal and the count carries the volume half.)
     */
    public static function platformMenuSufficient(string $userId): bool
    {
        $fetched = Menu::query()
            ->where('user_id', $userId)
            ->whereNotNull('last_successful_fetch_at')
            ->exists();
        if (! $fetched) {
            return false;
        }

        return app(ManualMenuItems::class)
            ->rows($userId)
            ->count() >= self::PLATFORM_MENU_SUFFICIENT;
    }

    public function handle(
        MenuAiExtractor $extractor,
        GoogleMenuImagesScraper $imagesScraper,
        MenuScanApplier $applier,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        // Menu is a capability-gated feature (food sector) — same gate as every
        // menu surface. Never spend AI on an account that can't show one.
        //
        // It logs now. The bare return meant an unclassified food business got
        // no platform menu, no scan, and no evidence anywhere that a scan had
        // been declined: this check sat above the first Log::info, so the job
        // returned in 14.35ms saying nothing at all. pret-a-manger is the proof
        // — Google category "Sandwich Shop", no keyword to land on, sector
        // null, and isFood(null) is false by design, so a sandwich chain was
        // served the BOOKING capability set and shipped 0 menu items.
        //
        // sector_missing is the field worth alerting on. A non-food sector is a
        // deliberate no; a NULL one is a classification miss, and the next
        // unmapped Google category should surface as this log line rather than
        // as another silently menu-less site.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            Log::info('google_menu_scan.capability_denied', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'sector' => $user->sector,
                'sector_missing' => $user->sector === null,
            ]);

            return;
        }

        if (! $extractor->configured()) {
            Log::info('google_menu_scan.not_configured', ['user_id' => $this->userId]);

            return;
        }

        // Tier 1 (free): the connection payload's stored Place photos.
        $storedUrls = $this->storedPhotoUrls();
        $ocrCalls = 0;
        $dense = $this->collectMenuTexts($extractor, $storedUrls, $ocrCalls);

        // Tier 2 (paid): one Apify photo-stream sweep, only when the free
        // tier read nothing menu-like and OCR budget remains.
        if ($dense === [] && $ocrCalls < self::MAX_OCR_CALLS) {
            $sweep = $imagesScraper->fetch($this->placeId, $this->userId) ?? [];
            $fresh = array_values(array_diff($sweep, $storedUrls));
            $dense = $this->collectMenuTexts($extractor, $fresh, $ocrCalls);
        }

        if ($dense === []) {
            Log::info('google_menu_scan.no_menu_photos', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'ocr_calls' => $ocrCalls,
            ]);

            return;
        }

        $items = $extractor->structure(
            mb_substr(implode("\n\n", $dense), 0, self::COMBINED_TEXT_CAP),
            $this->userId,
        );
        if ($items === null || $items === []) {
            Log::info('google_menu_scan.no_items', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'dense_pages' => count($dense),
                'failed' => $items === null,
            ]);

            return;
        }

        // T6/D1: with a sufficient platform menu, enrich matched dishes but
        // never add scan-owned rows; scan-only accounts keep additions.
        $allowNew = ! self::platformMenuSufficient($this->userId);
        $result = $applier->apply($user, $items, enrichOnly: true, allowNew: $allowNew);

        // Persist for MenuFetchJob's post-rebuild re-apply (no re-billing).
        Menu::query()->where('user_id', $user->id)->first()?->forceFill([
            'scan_items' => [
                'items' => $items,
                'source' => 'google-photos',
                'scannedAt' => now()->toIso8601String(),
            ],
        ])->save();

        Log::info('google_menu_scan.applied', [
            'user_id' => $this->userId,
            'place_id' => $this->placeId,
            'items' => count($items),
            'updated' => $result['updated'],
            'added' => $result['added'],
            'skipped' => $result['skipped'],
            'allow_new' => $allowNew,
            'ocr_calls' => $ocrCalls,
        ]);
    }

    /**
     * OCR a list of photo URLs, keeping only menu-dense texts. Shared budget
     * across tiers via $ocrCalls (by reference); early-exits once enough
     * dense pages are in hand.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function collectMenuTexts(MenuAiExtractor $extractor, array $urls, int &$ocrCalls): array
    {
        $dense = [];
        foreach ($urls as $url) {
            if ($ocrCalls >= self::MAX_OCR_CALLS || count($dense) >= self::ENOUGH_DENSE_PAGES) {
                break;
            }
            $ocrCalls++;
            $text = $extractor->ocrImageUrl($url, $this->userId);
            if (is_string($text) && mb_strlen(trim($text)) >= self::MENU_TEXT_MIN_CHARS) {
                $dense[] = trim($text);
            }
        }

        return $dense;
    }

    /** @return list<string> */
    private function storedPhotoUrls(): array
    {
        $connection = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first();
        if (! $connection) {
            return [];
        }

        $urls = [];
        foreach (GoogleBusinessPayload::fromArray($connection->payload)->photos() as $photo) {
            $url = $photo['photoPicUrl'];
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('google_menu_scan.failed', [
            'user_id' => $this->userId,
            'place_id' => $this->placeId,
            'error' => $e->getMessage(),
        ]);
    }
}
