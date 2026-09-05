<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use RuntimeException;

/**
 * The dashboard-side reader of Square Appointments' buyer widget JSON
 * (SquareController, SquareAutoSelectJob). The ingest connector reads the
 * same document through Io; both go through SquareBookingPage for the
 * field names so the two never drift.
 */
final class SquareBookingClient
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when Square answers with anything but the
     *                          widget document (a 302 to HTML, a moved shape)
     */
    public function widget(string $merchant, ?string $unit): array
    {
        // Fixed vendor host, but through the one outbound fetcher (Rule 2):
        // budget, size cap and redirect policy in one place.
        $response = $this->fetcher->fetch(SquareBookingPage::widgetUrl($merchant, $unit), ['Accept' => 'application/json']);

        // 404 is not a transient failure — Square is telling us definitively
        // that this booking page doesn't exist (disabled, moved, or a stale
        // merchant token). Distinguished from other statuses so the
        // controller can give the user an actionable message instead of
        // "try again" (2026-09-05 production incident, issue #519).
        if ($response['status'] === 404) {
            throw new SquareWidgetNotFound("square widget returned 404 for merchant {$merchant}");
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException("square widget returned {$response['status']}");
        }
        $doc = json_decode($response['body'], true);
        if (! is_array($doc) || ! is_array($doc['services'] ?? null)) {
            throw new RuntimeException('square widget response carried no services[] — shape may have changed');
        }

        return $doc;
    }
}
