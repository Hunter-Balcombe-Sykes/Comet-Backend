<?php

namespace App\Mail\Notifications;

// Feature announcements — the marketing-shaped category, most exposed to Gmail/Yahoo bulk rules; always carries one-click unsubscribe.
class FeatureAnnouncementMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'feature_announcement';
}
