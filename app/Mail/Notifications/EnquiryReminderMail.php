<?php

namespace App\Mail\Notifications;

// Unread-enquiry nudge. All content lives on the Notification row published by
// partna:notify-unanswered-enquiries; the shared category view renders it.
class EnquiryReminderMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'enquiry_reminder';
}
