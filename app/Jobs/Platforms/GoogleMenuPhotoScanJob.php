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
     * T6 (2026-08-27): the post-enrichment dispatch, with the delay decided by
     * what the delay is FOR. The 5-minute hold exists purely so a same-connect
     * MenuFetchJob settles before the scan reads the menu — a user with no
     * ordering platform connected has no MenuFetchJob to wait for, and the
     * scan is their ONLY menu source, so holding it just delays their menu.
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

        $job = self::dispatch($userId, $placeId);
        if ($hasOrderingPlatform) {
            $job->delay(now()->addMinutes(5));
        }
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

        // Menu is a capability-gated feature (business + food) — same gate as
        // every menu surface. Never spend AI on an account that can't show one.
        if (! AccountCapabilities::for($user)->can_use_menu) {
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
