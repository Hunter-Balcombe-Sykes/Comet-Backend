<?php

namespace App\Mail\Notifications;

// OV-H: generic email for any `critical` notification, and SendTransactionalNotificationEmailJob's fallback for critical notifications whose category has no mailable. Critical mail never carries unsubscribe affordances.
class CriticalNotificationMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'critical';
}
