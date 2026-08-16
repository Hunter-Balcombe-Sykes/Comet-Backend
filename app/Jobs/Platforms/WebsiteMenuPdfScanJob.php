<?php

namespace App\Jobs\Platforms;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// OCR + structure one PDF menu found on a business's previous website — the
// PDF-document sibling of GoogleMenuPhotoScanJob's photo OCR, own job so a
// slow Mistral OCR call never blocks ScanPreviousWebsiteContentJob's own
// timeout budget (mirrors why the link-in-bio scan is its own job too).
// enrichOnly like the Google-photos scan: adds to what a platform scrape
// already knows, never overwrites it — tagged 'website-scan' (not 'scan'), so
// its categories land in their own `menu:website-scan:*` external_ref namespace
// and can never be folded into (or rebuilt away with) a scraped one. See
// MenuScanApplier::categoryRefFor().
class WebsiteMenuPdfScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SOURCE = 'website-scan';

    // AI spend: no automatic retries — a failed scan logs and waits for the
    // next enrichment rather than re-billing OCR on a flaky afternoon.
    // ($backoff is moot at one attempt; declared for the job-hygiene policy.)
    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [60];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userId,
        public readonly string $documentUrl,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':'.sha1($this->documentUrl);
    }

    public function handle(MenuAiExtractor $extractor, MenuScanApplier $applier): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }

        if (! AccountCapabilities::for($user)->can_use_menu) {
            return;
        }

        if (! $extractor->configured()) {
            Log::info('website_menu_pdf_scan.not_configured', ['user_id' => $this->userId]);

            return;
        }

        $text = $extractor->ocrDocumentUrl($this->documentUrl, $this->userId);
        if (! is_string($text) || trim($text) === '') {
            Log::info('website_menu_pdf_scan.no_text', ['user_id' => $this->userId, 'document_url' => $this->documentUrl]);

            return;
        }

        $items = $extractor->structure($text, $this->userId);
        if ($items === null || $items === []) {
            Log::info('website_menu_pdf_scan.no_items', [
                'user_id' => $this->userId,
                'document_url' => $this->documentUrl,
                'failed' => $items === null,
            ]);

            return;
        }

        $result = $applier->apply($user, $items, enrichOnly: true, source: self::SOURCE);

        Log::info('website_menu_pdf_scan.applied', [
            'user_id' => $this->userId,
            'document_url' => $this->documentUrl,
            'items' => count($items),
            'updated' => $result['updated'],
            'added' => $result['added'],
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('website_menu_pdf_scan.failed', [
            'user_id' => $this->userId,
            'document_url' => $this->documentUrl,
            'error' => $e->getMessage(),
        ]);
    }
}
