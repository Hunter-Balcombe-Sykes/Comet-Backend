<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Dispatched after an enquiry is saved — keeps the public POST response off the hot path.
class DispatchEnquiryNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 90, 180];

    public int $timeout = 30;

    public function __construct(public readonly string $enquiryId) {}

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

        $dispatcher->dispatch($enquiry, $block);
    }
}
