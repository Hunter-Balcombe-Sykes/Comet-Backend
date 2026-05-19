<?php

namespace Tests\Feature\Mail;

use App\Mail\Exports\CommissionExportReadyMail;
use Tests\TestCase;

class CommissionExportReadyMailTest extends TestCase
{
    public function test_renders_with_signed_url_and_expiry(): void
    {
        $mail = new CommissionExportReadyMail(
            signedUrl: 'https://r2.example.com/signed?sig=abc',
            role: 'brand',
            format: 'csv',
            filters: ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'],
            recordCount: 230,
            ttlDays: 3,
            expiresAt: new \DateTimeImmutable('2026-05-22T00:00:00Z'),
        );

        $html = $mail->render();
        $this->assertStringContainsString('https://r2.example.com/signed?sig=abc', $html);
        $this->assertStringContainsString('230', $html);
        $this->assertStringContainsString('3 days', $html);
    }

    public function test_renders_without_date_filters(): void
    {
        $mail = new CommissionExportReadyMail(
            signedUrl: 'https://r2.example.com/signed',
            role: 'affiliate',
            format: 'xlsx',
            filters: [],  // no date range
            recordCount: 42,
            ttlDays: 3,
            expiresAt: new \DateTimeImmutable('2026-05-22'),
        );

        $html = $mail->render();
        $this->assertStringContainsString('XLSX', $html);
        $this->assertStringContainsString('42', $html);
        $this->assertStringNotContainsString('from <strong>', $html);  // no "from X to Y" date range phrase when filters are empty
    }
}
