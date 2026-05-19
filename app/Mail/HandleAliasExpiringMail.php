<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HandleAliasExpiringMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly object $alias,
        public readonly string $bucket  // 't3' or 't1'
    ) {}

    public function envelope(): Envelope
    {
        $days = $this->bucket === 't3' ? 3 : 1;

        return new Envelope(
            subject: "Your old handle \"{$this->alias->handle}\" releases in {$days} day(s)",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.handle-alias-expiring',
        );
    }
}
