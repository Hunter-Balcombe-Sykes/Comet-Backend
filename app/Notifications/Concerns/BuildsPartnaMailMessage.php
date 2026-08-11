<?php

namespace App\Notifications\Concerns;

use App\Mail\Support\HtmlToText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Header\TagHeader;

/**
 * Shared MailMessage factory for Notification classes (P3, 2026-08-12).
 *
 * Notification-channel mail bypasses BaseTransactionalMail, so before this
 * trait the whole moderation/T&S family shipped with no CR/LF subject defence,
 * no stable Message-ID, no pipeline header and no text part. This mirrors
 * those envelope concerns in one place; keep the two in step.
 */
trait BuildsPartnaMailMessage
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $dedupeKey  Stable identifier (e.g. the decision id) —
     *                                  pins the Message-ID so a Horizon retry of the same
     *                                  send is deduplicable downstream, like WHK-5 does
     *                                  for auth mail.
     */
    protected function partnaMailMessage(string $subject, string $view, array $data = [], ?string $dedupeKey = null): MailMessage
    {
        // Same CR/LF defence as BaseTransactionalMail::subject().
        $subject = (string) preg_replace('/[\r\n]+/', ' ', $subject);

        // Derived text/plain part from the rendered HTML (see HtmlToText).
        $html = view($view, $data)->render();
        $data['plainTextBody'] = HtmlToText::convert($html);

        return (new MailMessage)
            ->subject($subject)
            ->view(['html' => $view, 'text' => 'mail.text.generic'], $data)
            ->withSymfonyMessage(function ($message) use ($dedupeKey): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('X-Partna-Pipeline', 'transactional');
                $headers->add(new TagHeader(Str::kebab(class_basename(static::class))));

                if ($dedupeKey !== null && $dedupeKey !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $dedupeKey)) {
                    $fromAddress = (string) config('mail.from.address', 'hello@partna.au');
                    $domain = str_contains($fromAddress, '@')
                        ? substr($fromAddress, strrpos($fromAddress, '@') + 1)
                        : 'partna.au';
                    $headers->remove('Message-ID');
                    $headers->addIdHeader('Message-ID', $dedupeKey.'@'.$domain);
                }
            });
    }
}
