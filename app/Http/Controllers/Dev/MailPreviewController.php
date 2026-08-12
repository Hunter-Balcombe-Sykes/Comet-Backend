<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Mail\Auth\EmailChangeMail;
use App\Mail\Auth\EmailConfirmMail;
use App\Mail\Auth\InviteMail;
use App\Mail\Auth\MagicLinkMail;
use App\Mail\Auth\PasswordResetMail;
use App\Mail\Auth\ReauthenticationMail;
use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\EarlyAccess\EarlyAccessInviteMail;
use App\Mail\EarlyAccess\EarlyAccessThankYouMail;
use App\Mail\EnquiryConfirmationMail;
use App\Mail\FeedbackSubmittedMail;
use App\Mail\Gdpr\UserDataExportMail;
use App\Mail\HandleAliasExpiringMail;
use App\Mail\Notifications\AccountDeletionCancelledMail;
use App\Mail\Notifications\AccountDeletionRequestedMail;
use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Mail\Notifications\CriticalNotificationMail;
use App\Mail\Notifications\FeatureAnnouncementMail;
use App\Mail\Notifications\IncidentMail;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Mail\Notifications\ProfileTaskMail;
use App\Mail\PreAccount\ClaimInviteMail;
use App\Mail\Security\TwoFactorRemovedMail;
use App\Mail\SiteEnquiryNotification;
use App\Mail\StaffBroadcastMail;
use App\Mail\SubscriptionConfirmationMail;
use App\Models\Core\Feedback;
use App\Models\Core\Notifications\Notification as NotificationModel;
use App\Models\Core\Site\Enquiry;
use App\Models\Moderation\Decision;
use App\Notifications\Moderation\AccountBannedNotification;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as LaravelNotification;

/**
 * Local-only gallery that renders every outbound email with fixture data so
 * template changes can be reviewed in a browser before anything ships.
 * Routed exclusively when app()->environment('local') — see routes/web.php.
 * Never wire this into any deployed environment.
 */
class MailPreviewController extends Controller
{
    public function index()
    {
        // SecureHeaders won't overwrite headers already present, so the strict
        // global CSP (default-src 'none') is relaxed here for this local-only
        // gallery: inline style/script for the shell, self-framed previews.
        return response(view('dev.mail-preview', ['groups' => $this->groups()]))
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; frame-src 'self'; base-uri 'self'; frame-ancestors 'none'")
            ->header('X-Frame-Options', 'DENY');
    }

    public function show(string $key)
    {
        $entry = collect($this->groups())->flatMap(fn ($g) => $g)->get($key);
        abort_unless($entry, 404);

        try {
            $html = $this->render($entry['make']());
        } catch (\Throwable $e) {
            $html = '<body style="font-family:monospace;padding:2rem;color:#b00020;">'
                .'<h2>Fixture failed to render</h2><pre>'.e($e->getMessage())."\n\n".e($e->getTraceAsString()).'</pre></body>';
        }

        // ?dark=1 simulates prefers-color-scheme: dark by making the dark-mode
        // media query unconditional — same CSS the client would apply.
        if (request()->boolean('dark')) {
            $html = preg_replace('/@media\s*\(prefers-color-scheme:\s*dark\)/', '@media all', $html);
        }

        return response($html)
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https:; base-uri 'self'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    private function render(object $renderable): string
    {
        if ($renderable instanceof Mailable) {
            return $renderable->render();
        }

        if ($renderable instanceof LaravelNotification) {
            $message = $renderable->toMail($this->fakeNotifiable());

            return $this->renderMailMessage($message);
        }

        throw new \RuntimeException('Unsupported preview type: '.get_class($renderable));
    }

    private function renderMailMessage(MailMessage $message): string
    {
        // Markdown::render registers the mail:: component namespace that
        // @component('mail::message') templates need outside a real send.
        if ($message->view) {
            $view = $message->view;
            if (is_array($view)) {
                // ['html' => ..., 'text' => ...] (BuildsPartnaMailMessage) or
                // [0 => html, 1 => text] — preview always shows the HTML part.
                $view = $view['html'] ?? $view[0];
            }

            return app(Markdown::class)->render($view, $message->viewData);
        }

        return app(Markdown::class)->render($message->markdown ?? 'mail::message', $message->data());
    }

    private function fakeNotifiable(): object
    {
        return new class
        {
            public string $email = 'sam@example.com';

            public function routeNotificationFor(string $channel): string
            {
                return $this->email;
            }
        };
    }

    /** @return array<string, array<string, array{label: string, make: \Closure}>> */
    private function groups(): array
    {
        return [
            'Auth' => [
                'email-confirm' => ['label' => 'Email confirm (OTP)', 'make' => fn () => new EmailConfirmMail('sam@example.com', 'Sam', '482913')],
                'password-reset' => ['label' => 'Password reset', 'make' => fn () => new PasswordResetMail('sam@example.com', 'Sam', 'https://app.partna.au/reset-password?token=preview')],
                'magic-link' => ['label' => 'Magic link', 'make' => fn () => new MagicLinkMail('sam@example.com', 'Sam', 'https://app.partna.au/auth/callback?token=preview')],
                'email-change' => ['label' => 'Email change', 'make' => fn () => new EmailChangeMail('sam.new@example.com', 'Sam', 'https://app.partna.au/auth/callback?token=preview')],
                'invite' => ['label' => 'Invite', 'make' => fn () => new InviteMail('sam@example.com', null, 'https://app.partna.au/auth/callback?token=preview')],
                'reauthentication' => ['label' => 'Reauthentication (OTP)', 'make' => fn () => new ReauthenticationMail('sam@example.com', 'Sam', '739204')],
            ],
            'Security' => [
                'two-factor-removed' => ['label' => 'Two-factor removed', 'make' => fn () => new TwoFactorRemovedMail('sam@example.com', 'Sam')],
            ],
            'Account' => [
                'deletion-requested' => ['label' => 'Deletion requested', 'make' => fn () => new AccountDeletionRequestedMail('Sam', 'https://app.partna.au/account/deletion/confirm?token=preview')],
                'deletion-scheduled' => ['label' => 'Deletion scheduled', 'make' => fn () => new AccountDeletionScheduledMail('Sam', now()->addDays(30)->toDayDateTimeString().' (UTC)', 'https://app.partna.au/account/deletion/cancel?token=preview')],
                'deletion-cancelled' => ['label' => 'Deletion cancelled', 'make' => fn () => new AccountDeletionCancelledMail('Sam')],
                'early-access-invite' => ['label' => 'Early access invite', 'make' => fn () => new EarlyAccessInviteMail('sam@example.com', 'https://app.partna.au/sign-up?invite=preview')],
                'early-access-thanks' => ['label' => 'Early access thank-you', 'make' => fn () => new EarlyAccessThankYouMail('sam@example.com')],
                'claim-invite' => ['label' => 'Claim invite', 'make' => fn () => new ClaimInviteMail('sam@example.com', 'https://app.partna.au/claim?token=preview')],
                'handle-alias-expiring' => ['label' => 'Handle alias expiring', 'make' => fn () => new HandleAliasExpiringMail((object) ['handle' => 'sams-cafe', 'expires_at' => now()->addDays(3), 'reclaim_until' => now()->addDays(3), 'professional' => (object) ['contact_email' => 'sam@example.com']], 't3')],
                'gdpr-export-self' => ['label' => 'GDPR export (self)', 'make' => fn () => new UserDataExportMail('https://exports.partna.au/preview.zip?sig=preview', 'sams-cafe', 'self', ['profile' => 1, 'enquiries' => 42, 'subscribers' => 128])],
                'gdpr-export-staff' => ['label' => 'GDPR export (staff)', 'make' => fn () => new UserDataExportMail('https://exports.partna.au/preview.zip?sig=preview', 'sams-cafe', 'staff', ['profile' => 1, 'enquiries' => 42, 'subscribers' => 128])],
            ],
            'Site forms (white-label)' => [
                'enquiry-confirmation' => ['label' => 'Enquiry confirmation (visitor)', 'make' => fn () => new EnquiryConfirmationMail($this->proBrand(), "Riley O'Brien", 'Booking for Saturday')],
                'enquiry-notification' => ['label' => 'Enquiry notification (owner)', 'make' => fn () => new SiteEnquiryNotification($this->fakeEnquiry())],
                'subscription-confirmation' => ['label' => 'Subscription confirmation', 'make' => fn () => new SubscriptionConfirmationMail($this->proBrand(), 'https://sams-cafe.partna.au/unsubscribe?token=preview', "Riley O'Brien")],
            ],
            'Category notifications' => [
                'notif-critical' => ['label' => 'Critical', 'make' => fn () => new CriticalNotificationMail($this->fakeNotification('critical', 'Action needed: reconnect your Google account', 'Your Google connection stopped syncing and your reviews are no longer updating. Reconnect it to keep your site current.'))],
                'notif-incident' => ['label' => 'Incident', 'make' => fn () => new IncidentMail($this->fakeNotification('incident', 'Resolved: site outage on 11 August', 'Between 2:10pm and 2:42pm AEST some sitepages were unavailable. Everything is back to normal and no data was lost.'))],
                'notif-policy' => ['label' => 'Policy update', 'make' => fn () => new PolicyUpdateMail($this->fakeNotification('policy_update', 'We have updated our privacy policy', 'We have clarified how analytics data is stored. The changes take effect on 1 September 2026.'))],
                'notif-feature' => ['label' => 'Feature announcement', 'make' => fn () => new FeatureAnnouncementMail($this->fakeNotification('feature_announcement', 'New: smart ordering for your links', 'Your links can now arrange themselves by engagement. Turn it on from the Site page.'))],
                'notif-profile-task' => ['label' => 'Profile task', 'make' => fn () => new ProfileTaskMail($this->fakeNotification('profile_tasks', 'Finish setting up your site', 'Add a logo and connect your first platform to make your site look its best.'))],
            ],
            'Moderation' => [
                'mod-suspended' => ['label' => 'Account suspended', 'make' => fn () => new AccountSuspendedNotification($this->fakeDecision())],
                'mod-banned' => ['label' => 'Account banned', 'make' => fn () => new AccountBannedNotification($this->fakeDecision())],
                'mod-content-hidden' => ['label' => 'Content hidden', 'make' => fn () => new ContentHiddenNotification($this->fakeDecision())],
                'mod-report-outcome' => ['label' => 'Report outcome', 'make' => fn () => new ReportOutcomeNotification($this->fakeDecision())],
            ],
            'Internal' => [
                'feedback-submitted' => ['label' => 'Feedback submitted', 'make' => fn () => new FeedbackSubmittedMail($this->fakeFeedback(), 'sam@example.com')],
                'staff-broadcast' => ['label' => 'Staff broadcast', 'make' => fn () => new StaffBroadcastMail($this->fakeNotification('broadcast', 'August product update', 'A quick look at what shipped this month across sitepages, analytics and the dashboard.'), 'https://partna.au/unsubscribe?token=preview')],
            ],
        ];
    }

    private function proBrand(): EmailBrand
    {
        return new EmailBrand(
            isPartna: false,
            proName: "Sam's Cafe",
            siteUrl: 'https://sams-cafe.partna.au',
            logoUrl: null,
            iconUrl: null,
            wordmarkUrl: null,
            replyToEmail: 'hello@samscafe.com.au',
            palette: EmailBrandDefaults::defaults(),
        );
    }

    private function fakeEnquiry(): Enquiry
    {
        return (new Enquiry)->forceFill([
            'name' => "Riley O'Brien",
            'email' => 'riley@example.com',
            'phone' => '+61 400 000 000',
            'subject' => 'Booking for Saturday',
            'message' => "Hi — do you have a table for four this Saturday around 7pm?\n\nAlso, is the courtyard open?",
            'created_at' => now(),
        ]);
    }

    private function fakeFeedback(): Feedback
    {
        return (new Feedback)->forceFill([
            'id' => 'f1e2d3c4-0000-0000-0000-000000000000',
            'kind' => 'bug',
            'message' => 'The analytics page shows a blank chart when I pick "This year".',
            'page_url' => 'https://app.partna.au/analytics',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15',
            'request_id' => 'req_preview_123',
            'created_at' => now(),
        ]);
    }

    private function fakeNotification(string $category, string $title, string $body): NotificationModel
    {
        return (new NotificationModel)->forceFill([
            // A real-looking user_id so CategoryNotificationMail builds the
            // signed unsubscribe URL and the footer shows in preview.
            'user_id' => '00000000-0000-4000-8000-000000000001',
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'cta_url' => 'https://app.partna.au/dashboard',
            'primary_action_label' => 'Open dashboard',
            'created_at' => now(),
        ]);
    }

    private function fakeDecision(): Decision
    {
        return (new Decision)->forceFill([
            'reason' => 'Repeated impersonation of another business after a prior warning.',
            'created_at' => now(),
        ]);
    }
}
