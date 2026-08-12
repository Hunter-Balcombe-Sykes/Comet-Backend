<?php

namespace App\Mail\Notifications;

use App\Mail\BaseTransactionalMail;
use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Shared build for every category-driven notification email (P3, 2026-08-12).
 *
 * The five per-category mailables rendered five byte-identical wrapper views;
 * they are one view + one build now, with the category as data. Subclasses
 * stay registered per-category in config('partna.notifications.mailables') so
 * the registry keeps naming real classes.
 *
 * Non-critical, non-mandatory categories carry RFC 8058 one-click unsubscribe
 * (Gmail/Yahoo bulk rules — feature announcements are marketing-shaped) plus a
 * footer "Manage notification emails" link. The unsubscribe URL is a signed
 * route that flips the user's existing per-category email preference.
 */
abstract class CategoryNotificationMail extends BaseTransactionalMail
{
    use Queueable, SerializesModels;

    /** Category key as registered in config('partna.notifications.mailables'). */
    protected const CATEGORY = '';

    public function __construct(public readonly Notification $notification) {}

    public function build(): self
    {
        $unsubscribeUrl = $this->unsubscribeUrl();
        $manageUrl = rtrim((string) config('app.frontend_url', 'https://app.partna.au'), '/').'/settings/notifications';

        $mail = $this->buildEnvelope()
            ->subject($this->notification->title)
            ->view('emails.notifications.category', [
                'notification' => $this->notification,
                'category' => static::CATEGORY,
                'manageUrl' => $manageUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);

        if ($unsubscribeUrl !== null) {
            $mail->withSymfonyMessage(function ($message) use ($unsubscribeUrl): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }

    /**
     * Null when the category cannot be opted out of (critical always emails,
     * mandatory categories resolve to true at send time regardless of any
     * stored preference) or when the notification is global (user_id null —
     * there is no per-user preference row to flip).
     */
    protected function unsubscribeUrl(): ?string
    {
        if (static::CATEGORY === '' || static::CATEGORY === 'critical') {
            return null;
        }

        if (NotificationPublisher::isMandatory(static::CATEGORY)) {
            return null;
        }

        $userId = $this->notification->user_id;
        if (! is_string($userId) || $userId === '') {
            return null;
        }

        return URL::signedRoute('public.notification-unsubscribe', [
            'userId' => $userId,
            'category' => static::CATEGORY,
        ]);
    }
}
