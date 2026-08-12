<?php

namespace App\Mail\Notifications;

// Profile task nudges. NOTE: currently orphaned — no emit site since the account-type strip (see MailableCategoryCoverageTest).
class ProfileTaskMail extends CategoryNotificationMail
{
    protected const CATEGORY = 'profile_tasks';
}
