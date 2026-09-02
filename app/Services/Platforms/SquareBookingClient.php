<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The dashboard-side reader of Square Appointments' buyer widget JSON
 * (SquareController, SquareAutoSelectJob). The ingest connector reads the
 * same document through Io; both go through SquareBookingPage for the
 * field names so the two never drift.
 */
final class SquareBookingClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when Square answers with anything but the
     *                          widget document (a 302 to HTML, a moved shape)
     */
    public function widget(string $merchant, ?string $unit): array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout((int) config('partna.http_fetch.connect_budget_seconds', 20))
            ->get(SquareBookingPage::widgetUrl($merchant, $unit));

        if (! $response->ok()) {
            throw new RuntimeException("square widget returned {$response->status()}");
        }
        $doc = $response->json();
        if (! is_array($doc) || ! is_array($doc['services'] ?? null)) {
            throw new RuntimeException('square widget response carried no services[] — shape may have changed');
        }

        return $doc;
    }
}
