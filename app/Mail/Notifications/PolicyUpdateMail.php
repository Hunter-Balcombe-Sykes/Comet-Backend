<?php

namespace App\Mail\Notifications;

// Policy update notices — a mandatory category, so no unsubscribe affordance.
class PolicyUpdateMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'policy_update';
}
