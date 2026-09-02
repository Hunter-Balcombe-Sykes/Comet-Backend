<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
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

// A.10: the PAID tier-2 photo sweep that GoogleMenuPhotoScanJob defers on a
// sign-up build (most sign-up builds are abandoned before setup — the Apify
// spend waits for the person to actually show up). SetupController dispatches
// this when they leave the platforms passes on a food business with no
// ordering connection: at that point the platform lane has definitively not
// produced a menu, and the person is live in the dialog waiting for one.
//
// Tier 1 (the free stored-photo OCR) already ran during the build, so this
// job goes straight to the sweep. Thresholds are GoogleMenuPhotoScanJob's own
// consts — one definition of "menu-dense".
class MenuPhotoSweepJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    // AI spend: no automatic retries (same policy as the scan job).
    public int $tries = 1;

    /** One sweep per person per setup session — a Back/Continue bounce must not re-bill. */
    public int $uniqueFor = 86400;

    public function __construct(public readonly string $userId)
    {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId;
    }

    public function handle(
        MenuAiExtractor $extractor,
        GoogleMenuImagesScraper $imagesScraper,
        MenuScanApplier $applier,
    ): void {
        $user = User::query()->find($this->userId);
        if (! $user || ! AccountCapabilities::for($user)->can_use_menu || ! $extractor->configured()) {
            return;
        }

        // Re-checked at run time: an ordering platform accepted moments ago in
        // the dialog makes the sweep pointless — the platform menu is coming.
        $hasOrdering = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('routing_class', 'ordering')
            ->exists();
        if ($hasOrdering) {
            return;
        }

        $payload = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first()?->payload;
        $placeId = is_array($payload) ? GoogleBusinessPayload::fromArray($payload)->placeId() : null;
        if ($placeId === null || $placeId === '') {
            return;
        }

        $sweep = $imagesScraper->fetch($placeId, $this->userId) ?? [];
        $ocrCalls = 0;
        $dense = [];
        foreach ($sweep as $url) {
            if ($ocrCalls >= GoogleMenuPhotoScanJob::MAX_OCR_CALLS
                || count($dense) >= GoogleMenuPhotoScanJob::ENOUGH_DENSE_PAGES) {
                break;
            }
            $ocrCalls++;
            $text = $extractor->ocrImageUrl($url, $this->userId);
            if (is_string($text) && mb_strlen(trim($text)) >= GoogleMenuPhotoScanJob::MENU_TEXT_MIN_CHARS) {
                $dense[] = trim($text);
            }
        }

        if ($dense === []) {
            Log::info('menu_photo_sweep.no_menu_photos', [
                'user_id' => $this->userId,
                'place_id' => $placeId,
                'ocr_calls' => $ocrCalls,
            ]);

            return;
        }

        $items = $extractor->structure(
            mb_substr(implode("\n\n", $dense), 0, GoogleMenuPhotoScanJob::COMBINED_TEXT_CAP),
            $this->userId,
        );
        if ($items === null || $items === []) {
            Log::info('menu_photo_sweep.no_items', [
                'user_id' => $this->userId,
                'failed' => $items === null,
            ]);

            return;
        }

        $allowNew = ! GoogleMenuPhotoScanJob::platformMenuSufficient($this->userId);
        $result = $applier->apply($user, $items, enrichOnly: true, allowNew: $allowNew);

        Log::info('menu_photo_sweep.applied', [
            'user_id' => $this->userId,
            'items' => count($items),
            'updated' => $result['updated'],
            'added' => $result['added'],
            'skipped' => $result['skipped'],
            'ocr_calls' => $ocrCalls,
        ]);
    }
}
