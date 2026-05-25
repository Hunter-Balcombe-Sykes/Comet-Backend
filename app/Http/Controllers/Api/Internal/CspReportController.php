<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receiver for Content-Security-Policy violation reports.
 *
 * Browsers POST here automatically when a page violates the CSP set by
 * SecureHeaders (see `report-uri` directive on the Horizon branch). No auth —
 * browsers send these without credentials. Rate-limited at the route so a
 * misconfigured page or hostile caller cannot flood the log pipeline.
 *
 * Accepts both legacy `application/csp-report` (Chromium, Safari) and the
 * newer Reporting-API `application/reports+json` payload shapes; both decode
 * to JSON.
 */
class CspReportController extends Controller
{
    /** Max characters retained per string value when normalising a report. */
    private const MAX_STRING_LEN = 2048;

    /** Max top-level keys retained from a single report (defence vs giant payloads). */
    private const MAX_KEYS = 20;

    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();
        if ($payload === [] || $payload === null) {
            // Legacy browsers POST with `application/csp-report` content-type
            // that bypasses $request->json(); fall back to the raw body.
            $raw = (string) $request->getContent();
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = [];
            }
        }

        // Log at error level (not warning) so the Nightwatch breadcrumb-vs-alert
        // line catches this — CSP violations on the Horizon admin surface are
        // high-signal: they indicate either a real bypass attempt or a CSP
        // regression introduced by a Horizon/Vue upgrade.
        Log::error('csp.violation', [
            'report' => self::normalise($payload),
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, self::MAX_STRING_LEN),
        ]);

        return response()->noContent();
    }

    /**
     * Cap the payload before it hits the log pipeline. Browsers send mostly-
     * predictable shapes, but a malicious or buggy client could inflate any
     * field. Truncate strings, cap key count, recurse one level for nested
     * report objects.
     */
    private static function normalise(array $payload, int $depth = 0): array
    {
        $out = [];
        $i = 0;
        foreach ($payload as $key => $value) {
            if ($i++ >= self::MAX_KEYS) {
                $out['_truncated'] = true;
                break;
            }
            if (is_string($value)) {
                $out[$key] = strlen($value) > self::MAX_STRING_LEN
                    ? substr($value, 0, self::MAX_STRING_LEN).'…'
                    : $value;
            } elseif (is_array($value) && $depth < 2) {
                $out[$key] = self::normalise($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
            // Drop everything else (objects, resources) silently — never
            // expected in a CSP report.
        }

        return $out;
    }
}
