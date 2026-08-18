<?php

namespace App\Support\Fixtures;

use App\Services\Platforms\Payloads\GoogleBusinessPayload;

/**
 * PII redaction applied to every body BEFORE it lands in tests/fixtures/recorded/.
 * Places JSON drops reviewer attribution (PRIV-1, same strip as the unclaimed
 * write path); every text body has emails and phone numbers masked. Binary
 * media is passed through untouched.
 */
final class FixtureRedactor
{
    private const BINARY_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'ico', 'pdf', 'bin'];

    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    // International or AU-local phone shapes with ≥ 8 digits; deliberately
    // loose — a fixture with a false positive is still a valid fixture. Area
    // code is 1-4 digits (not 2-4): AU numbers written with a country code
    // commonly drop the leading zero (e.g. "+61 3 9123 4567" for Melbourne),
    // leaving a single-digit area code — a 2-digit floor missed that shape.
    private const PHONE = '/(?:\+?\d{1,3}[\s.-]?)?(?:\(?\d{1,4}\)?[\s.-]?)\d{3,4}[\s.-]?\d{3,4}/';

    public static function apply(string $source, string $body, string $ext): string
    {
        if (in_array(strtolower($ext), self::BINARY_EXTS, true)) {
            return $body;
        }

        if (strtolower($ext) === 'json') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if ($source === 'places') {
                    $decoded = GoogleBusinessPayload::stripThirdPartyPii($decoded);
                }

                // Redact only string leaves, not the flattened JSON text — the
                // phone pattern also matches bare numeric fields (e.g. lat/long,
                // rating counts), and replacing an unquoted number with a
                // non-numeric literal produces invalid JSON. See FixtureStore,
                // whose --from=db capture path writes JSON through here.
                return (string) json_encode(
                    self::redactValues($decoded),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }
        }

        return self::redactText($body);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function redactValues(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::redactValues($item);
            } elseif (is_string($item)) {
                $value[$key] = self::redactText($item);
            }
        }

        return $value;
    }

    private static function redactText(string $body): string
    {
        $body = (string) preg_replace(self::EMAIL, '[redacted-email]', $body);
        $body = (string) preg_replace(self::PHONE, '[redacted-phone]', $body);

        return $body;
    }
}
