<?php

namespace App\Mail\Exports;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
        return new Content(
            view: 'emails.exports.commission-ready',
            with: [
                'signedUrl' => $this->signedUrl,
                'role' => $this->role,
                'format' => $this->format,
                'dateFrom' => $this->filters['date_from'] ?? null,
                'dateTo' => $this->filters['date_to'] ?? null,
                'recordCount' => $this->recordCount,
                'ttlDays' => $this->ttlDays,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
