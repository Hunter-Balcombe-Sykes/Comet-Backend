<?php

namespace App\Services\Notifications;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Adapters\EnquiryNotificationAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

class EnquiryNotificationDispatcher
{
    /**
     * Fan enquiry notifications out to all configured channel adapters.
     * The block's notification_channels setting controls which adapters fire;
     * default is ['in_app'] so the bell always rings even if settings are missing.
     *
     * @param  array<int, EnquiryNotificationAdapter>  $adapters
     */
    public function __construct(private readonly array $adapters) {}

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $channels = (array) data_get($block->settings, 'notification_channels', ['in_app']);
        if ($channels === []) {
            $channels = ['in_app'];  // never fully silent — at minimum the bell rings
        }

        foreach ($this->adapters as $adapter) {
            if (! in_array($adapter->channel(), $channels, true)) {
                continue;
            }

            try {
                $adapter->dispatch($enquiry, $block);
            } catch (Throwable $e) {
                // Adapter failure must not break other channels OR the public response.
                report($e);
                Log::warning('EnquiryNotificationDispatcher: adapter failed', [
                    'adapter' => $adapter::class,
                    'enquiry_id' => (string) $enquiry->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
