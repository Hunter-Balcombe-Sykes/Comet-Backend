<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Dispatched after an enquiry is saved — keeps the public POST response off the hot path.
class DispatchEnquiryNotificationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    // 5-min lock expiry so a crashed worker can't hold the lock forever.
    public int $uniqueFor = 300;

    public function __construct(public readonly string $enquiryId)
    {
        // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
        $this->queue = 'notifications';
    }

    public function uniqueId(): string
    {
        return $this->enquiryId;
    }

    public function handle(EnquiryNotificationDispatcher $dispatcher): void
    {
        $enquiry = Enquiry::query()->find($this->enquiryId);
        if (! $enquiry) {
            return;
        }

        // Find the active contact block for this site — it holds the notification settings.
        $block = Block::query()
            ->where('site_id', $enquiry->site_id)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->active()
            ->first();

        if (! $block) {
            return;
        }

        // Idempotency guard — a retry after partial success must not re-send the notification.
        if (Cache::has('enquiry:notified:'.$this->enquiryId)) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);

        Cache::put('enquiry:notified:'.$this->enquiryId, true, now()->addDay());
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('Enquiry notification dispatch permanently failed', [
            'job' => static::class,
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
    }
}
