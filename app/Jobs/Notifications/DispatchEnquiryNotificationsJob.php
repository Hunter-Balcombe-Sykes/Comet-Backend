<?php

namespace App\Jobs\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Dispatchers\AchievementNotifier;
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

    public function handle(EnquiryNotificationDispatcher $dispatcher, AchievementNotifier $achievements): void
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

        // Idempotency guard — claim the key BEFORE the side-effect. Cache::add is an atomic
        // SETNX (false if the key already exists), so a retry after a mid-flight crash sees
        // the claim and returns without re-dispatching. Deliberately at-most-once: we do NOT
        // release on failure — dispatch() swallows adapter errors and the achievement write
        // runs after it, so releasing would let a retry double-send the owner email.
        if (! Cache::add('enquiry:notified:'.$this->enquiryId, true, now()->addDay())) {
            return;
        }

        $dispatcher->dispatch($enquiry, $block);

        // Achievement: the user's first-ever enquiry. The enquiry is already persisted,
        // so a lifetime count of 1 means this is the first. The dispatcher is dedupe-keyed,
        // so the soft-delete edge (count returns to 1 after a delete) can't re-fire it.
        if (Enquiry::query()->where('user_id', $enquiry->user_id)->count() === 1) {
            $achievements->firstEnquiry((string) $enquiry->user_id);
        }
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
