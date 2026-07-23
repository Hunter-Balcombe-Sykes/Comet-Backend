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

// AI-structure a previous website's own on-page HTML menu text — the
// HTML-menu sibling of WebsiteMenuPdfScanJob's PDF OCR path. Reuses
// MenuAiExtractor::structure() UNCHANGED (the same text-in/items-out call
// already used for PDF OCR text and Google-photo OCR text — no OCR step
// needed here since the caller (ScanPreviousWebsiteContentJob) already
// extracted plain visible text via VisibleTextExtractor and pre-filtered it
// for menu-density before ever dispatching this job). Own job for the same
// reason the PDF path is its own job: the AI call's own timeout (90s) is
// longer than ScanPreviousWebsiteContentJob's own budget (60s).
class WebsiteMenuHtmlScanJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const SOURCE = 'website-scan';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userId,
        public readonly string $text,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function uniqueId(): string
    {
        return $this->userId.':html:'.sha1($this->text);
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
            Log::info('website_menu_html_scan.not_configured', ['user_id' => $this->userId]);

            return;
        }

        $items = $extractor->structure($this->text, $this->userId);
        if ($items === null || $items === []) {
            Log::info('website_menu_html_scan.no_items', [
                'user_id' => $this->userId,
                'failed' => $items === null,
            ]);

            return;
        }

        $result = $applier->apply($user, $items, enrichOnly: true, source: self::SOURCE);

        Log::info('website_menu_html_scan.applied', [
            'user_id' => $this->userId,
            'items' => count($items),
            'updated' => $result['updated'],
            'added' => $result['added'],
        ]);
    }

    // $text (the raw scraped page body) is deliberately omitted — matches
    // every other log site in this file.
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('website_menu_html_scan.failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
