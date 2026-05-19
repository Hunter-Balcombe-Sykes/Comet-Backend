<?php

namespace App\Mail\Exports;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * STUB — replaced by Plan Task 13 with real Blade template + envelope.
 * Constructor signature is the production one so the FinalizerJob's call
 * site does not change in Task 13.
 */
class CommissionExportReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $signedUrl,
        public string $role,
        public string $format,
        public array $filters,
        public int $recordCount,
        public int $ttlDays,
        public \DateTimeInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your commission export is ready');
    }

    public function content(): Content
    {
        // Stub view — real Blade template lands in Task 13.
        return new Content(htmlString: 'STUB: <a href="' . htmlspecialchars($this->signedUrl) . '">download</a>');
    }
}
