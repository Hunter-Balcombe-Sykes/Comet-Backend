<?php

namespace App\Mail\Notifications;

// Celebration mail for AchievementNotifier milestones (first enquiry, visit
// milestones). Registered against the existing `achievement` category, so it
// inherits its dedupe, preference toggle and one-click unsubscribe.
class AchievementMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'achievement';
}
