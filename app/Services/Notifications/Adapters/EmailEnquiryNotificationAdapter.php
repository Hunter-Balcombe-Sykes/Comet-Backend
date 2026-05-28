<?php

namespace App\Services\Notifications\Adapters;

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Facades\RateLimiter;

// Owns the per-professional hourly rate limit for enquiry email notifications.
// Silently no-ops when the limit is exceeded so the HTTP response is unaffected.
class EmailEnquiryNotificationAdapter implements EnquiryNotificationAdapter
{
    public function channel(): string
    {
        return 'email';
    }

    public function dispatch(Enquiry $enquiry, Block $block): void
    {
        $email = (string) data_get($block->settings, 'notification_email', '');
        if (trim($email) === '') {
            return;
        }

        $key = "enquiry_notify:{$enquiry->user_id}";
        $limit = (int) config('partna.throttle.enquiry_notification_per_hour', 10);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return;
        }
        RateLimiter::hit($key, 3600);

        SendEnquiryNotificationJob::dispatch((string) $enquiry->id, (string) $block->id);
    }
}
