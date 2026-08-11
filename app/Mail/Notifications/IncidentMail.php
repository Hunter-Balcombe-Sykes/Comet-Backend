<?php

namespace App\Mail\Notifications;

// Incident broadcast emails (outages / service disruption).
class IncidentMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'incident';
}
