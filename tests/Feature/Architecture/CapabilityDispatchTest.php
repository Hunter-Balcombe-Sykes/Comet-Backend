<?php

// §51 architecture test — every notification dispatcher job in
// `app/Jobs/Notifications/` either checks AccountCapabilities (defence-in-depth
// gate per §28.10) or appears in the EXEMPT list with a written justification.
//
// Why this is load-bearing (§28.10 / §28.11): the dispatcher layer is the last
// chance to drop a notification for a professional whose account_type no longer
// allows that category (e.g. a former partner becoming individual). The /me/
// notification-email-preferences endpoint also filters by capabilities, but
// without the dispatcher-layer gate a transition that arrives mid-job could
// still send mail the recipient is no longer entitled to.
//
// Adding a new job in app/Jobs/Notifications/ requires either:
//   (a) referencing `AccountCapabilities` in the source, OR
//   (b) explicit entry in CAPABILITY_GATE_EXEMPT below with a justification
//       (operates on Customer not Professional, broadcast to all staff, etc.).

it('every notification dispatcher job consults AccountCapabilities or is documented as exempt', function () {
    // Each entry: relative path → justification.
    $exempt = [
        // Operates on Customer (not a Professional notification preference) — the
        // sync is about cache hydration, not outbound mail.
        'app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php' => 'No capability gate: operates on Customer, not Professional',
        // Sweep job that revokes expired invites — not an outbound notification.
        'app/Jobs/Notifications/InviteExpirySweepJob.php' => 'Sweep-only job; revokes invite rows, does not dispatch user-facing notifications',
        // Staff broadcasts target the staff list — capability check happens at
        // composition time, not per-recipient.
        'app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php' => 'Staff broadcast composer; recipients are staff list, not capability-gated Professional categories',
        'app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php' => 'Staff broadcast per-recipient sender; capability gate evaluated by the parent composer job',
        // Enquiry notifications target the brand inbox configured by the brand —
        // not a per-professional capability category. Capability gating lives in
        // the brand-settings layer (whether enquiries are accepted at all).
        'app/Jobs/Notifications/SendEnquiryNotificationJob.php' => 'Brand-inbox dispatch; capability gating is at brand-settings level, not per-category',
        // Feedback notification job targets the internal team@ alias from env,
        // not a per-Professional notification category. The per-user capability
        // gate (can_submit_feedback) is enforced upstream in FeedbackService
        // before the job is ever dispatched.
        'app/Jobs/Notifications/SendFeedbackEmailJob.php' => 'Internal team@ dispatch; capability gate (can_submit_feedback) enforced upstream in FeedbackService::submit before dispatch',
        // Enquiry inbox fan-out (in_app + email adapters). Enquiry notifications
        // are universal for individual accounts (the only account type) — gated by
        // contact-block presence, not a per-category capability. The "inbox"
        // category is intentionally outside notification_categories. Email path
        // delegates to SendEnquiryNotificationJob (itself exempt as brand-inbox dispatch).
        'app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php' => 'Enquiry inbox dispatch; universal for individual accounts, gated by contact-block presence not a per-category capability',
        // Visitor-facing confirmation receipts (Tier-2 transactional). The recipient
        // is the public submitter (a non-account visitor), NOT a Professional with
        // notification-category preferences — so there is no per-category capability
        // to consult, exactly like SendEnquiryNotificationJob. Gated instead by a
        // per-block toggle + per-recipient rate limit inside the job.
        'app/Jobs/Notifications/SendEnquiryConfirmationJob.php' => 'Visitor confirmation receipt; recipient is a public submitter not a Professional, gated by per-block toggle + rate limit not a per-category capability',
        'app/Jobs/Notifications/SendSubscriptionConfirmationJob.php' => 'Visitor confirmation receipt; recipient is a public subscriber not a Professional, gated by per-block toggle + rate limit not a per-category capability',
    ];

    $dir = base_path('app/Jobs/Notifications');
    $violations = [];

    foreach (glob($dir.'/*.php') as $path) {
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        if (array_key_exists($relative, $exempt)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'AccountCapabilities')) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBeEmpty(
        'Notification dispatcher jobs without AccountCapabilities gate. Either add the gate or add the file to CAPABILITY_GATE_EXEMPT with a justification: '
        ."\n - ".implode("\n - ", $violations)
    );
});
