<?php

namespace App\Services\Notifications\Adapters;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Str;

class InAppEnquiryNotificationAdapter implements EnquiryNotificationAdapter
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    public function channel(): string
    {
        return 'in_app';
    }

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $notification = $this->publisher->publish(
            userId: (string) $enquiry->user_id,
            frontendType: 'enquiry.received',
            category: 'inbox',
            title: 'New enquiry',                                    // PII-free
            body: Str::limit((string) $enquiry->message, 140),
            dedupeKey: "enquiry:{$enquiry->id}",
            ctaUrl: "/dashboard/enquiries/{$enquiry->id}",
        );

        if ($notification !== null) {
            $enquiry->notification_id = $notification->id;
            $enquiry->saveQuietly();
        }
    }
}
