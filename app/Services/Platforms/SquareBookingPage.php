<?php

namespace App\Services\Platforms;

/**
 * Pure reader of Square Appointments' buyer widget JSON — what
 * `GET https://app.squareup.com/appointments/api/buyer/widget/{merchant}?unit_token={unit}`
 * returns with `Accept: application/json`, and the same document the booking
 * page embeds. Verified live 2026-09-02 (merchant 7rn54rnv21ng7n): no auth, no
 * cookie, no browser UA; without the Accept header the endpoint 302s to HTML.
 *
 * Shared by SquareBookingConnector (ingest, via Io) and SquareController /
 * SquareAutoSelectJob (via SquareBookingClient) so the two never drift on
 * field names. Square's internal buyer API, not a published contract — the
 * connector reports Unavailable, never throws, when the shape moves.
 */
final class SquareBookingPage
{
    public const WIDGET_URL = 'https://app.squareup.com/appointments/api/buyer/widget/';

    public const BOOK_URL = 'https://book.squareup.com/appointments/';

    /** @return array{merchant: ?string, unit: ?string, teamMember: ?string} */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url(trim($url));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $merchant = null;
        $unit = null;
        if (preg_match('~^/appointments/(?:book/)?([a-z0-9]{8,32})(?:/(?:location/)?([A-Z0-9]{8,32}))?~i', $path, $m) === 1) {
            $merchant = strtolower($m[1]);
            $unit = ($m[2] ?? '') !== '' ? strtoupper($m[2]) : null;
        }
        $tm = $query['team_member_id'] ?? null;

        return [
            'merchant' => $merchant,
            'unit' => $unit,
            'teamMember' => is_string($tm) && preg_match('/^TM[A-Za-z0-9_-]{4,64}$/', $tm) === 1 ? $tm : null,
        ];
    }

    public static function widgetUrl(string $merchant, ?string $unit): string
    {
        return self::WIDGET_URL.rawurlencode($merchant).($unit === null ? '' : '?unit_token='.rawurlencode($unit));
    }

    public static function bookingDeepLink(string $merchant, string $unit, string $serviceId, ?string $teamMember): string
    {
        $url = self::BOOK_URL.rawurlencode($merchant).'/location/'.rawurlencode($unit).'/services/'.rawurlencode($serviceId);

        return $teamMember === null ? $url : $url.'?team_member_id='.rawurlencode($teamMember);
    }

    /** @param  array<string, mixed>  $doc */
    public static function unitToken(array $doc): ?string
    {
        return is_string($doc['unit_token'] ?? null) && $doc['unit_token'] !== '' ? $doc['unit_token'] : null;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array{employeeId:string, staffId:string, displayName:string, jobTitle:?string, avatarUrl:?string, bio:?string}>
     */
    public static function team(array $doc): array
    {
        $out = [];
        foreach (is_array($doc['staff'] ?? null) ? $doc['staff'] : [] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $token = is_string($s['employee_token'] ?? null) ? $s['employee_token'] : '';
            $id = is_string($s['id'] ?? null) ? $s['id'] : '';
            if ($token === '' || $id === '') {
                continue;
            }
            $name = trim((string) ($s['long_name'] ?? ''))
                ?: trim((string) ($s['short_name'] ?? ''))
                ?: trim(trim((string) ($s['first_name'] ?? '')).' '.trim((string) ($s['last_name'] ?? '')));
            $avatar = data_get($s, 'profile_image.url');
            $out[] = [
                'employeeId' => $token,
                'staffId' => $id,
                'displayName' => $name,
                'jobTitle' => null,
                'avatarUrl' => is_string($avatar) && $avatar !== '' ? $avatar : null,
                'bio' => is_string($s['bio'] ?? null) && trim($s['bio']) !== '' ? trim($s['bio']) : null,
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $doc */
    public static function staffIdFor(array $doc, string $employeeToken): ?string
    {
        foreach (self::team($doc) as $member) {
            if ($member['employeeId'] === $employeeToken) {
                return $member['staffId'];
            }
        }

        return null;
    }

    /**
     * The bookable menu, one row per service, cheapest counted variation
     * first. With a staff id only the variations that staff member can be
     * booked for count (their price is the exact price); without one the
     * whole menu lands and a service whose variations differ in price reads
     * "from" its cheapest.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array<string, mixed>>
     */
    public static function services(array $doc, ?string $staffId): array
    {
        $currencyDefault = is_string(data_get($doc, 'business.currency_code')) ? data_get($doc, 'business.currency_code') : null;
        $categories = [];
        foreach (is_array($doc['categories'] ?? null) ? $doc['categories'] : [] as $i => $c) {
            if (is_array($c) && is_string($c['name'] ?? null)) {
                $categories[(string) ($c['id'] ?? $c['token'] ?? '')] = ['name' => $c['name'], 'position' => $i];
            }
        }
        $out = [];
        $position = 0;
        foreach (is_array($doc['services'] ?? null) ? $doc['services'] : [] as $svc) {
            if (! is_array($svc)) {
                continue;
            }
            $id = is_string($svc['id'] ?? null) ? $svc['id'] : (is_string($svc['item_token'] ?? null) ? $svc['item_token'] : '');
            $name = is_string($svc['name'] ?? null) ? trim($svc['name']) : '';
            if ($id === '' || $name === '') {
                continue;
            }
            $variations = is_array($svc['variations'] ?? null) && $svc['variations'] !== []
                ? $svc['variations']
                : [['price_cents' => $svc['price_cents'] ?? null, 'service_time' => $svc['time'] ?? null, 'staff_ids' => $svc['staff_ids'] ?? []]];
            $counted = [];
            foreach ($variations as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $staffIds = is_array($v['staff_ids'] ?? null) ? $v['staff_ids'] : [];
                if ($staffId !== null && ! in_array($staffId, $staffIds, true)) {
                    continue;
                }
                if (($v['is_visible_in_default_booking'] ?? true) === false) {
                    continue;
                }
                $counted[] = [
                    'price' => is_numeric($v['price_cents'] ?? null) ? fdiv((int) $v['price_cents'], 100) : null,
                    'seconds' => is_numeric($v['service_time'] ?? null) ? (int) $v['service_time'] : (is_numeric($svc['time'] ?? null) ? (int) $svc['time'] : null),
                ];
            }
            if ($counted === []) {
                continue;
            }
            usort($counted, static fn ($a, $b) => ($a['price'] ?? PHP_FLOAT_MAX) <=> ($b['price'] ?? PHP_FLOAT_MAX));
            $prices = array_values(array_unique(array_filter(array_column($counted, 'price'), static fn ($p) => $p !== null)));
            $categoryKey = is_string($svc['category_token'] ?? null) ? $svc['category_token'] : null;
            $category = $categoryKey !== null ? ($categories[$categoryKey] ?? null) : null;
            $description = is_string($svc['description'] ?? null) ? trim($svc['description']) : '';
            $out[] = array_filter([
                'service_id' => $id,
                'name' => $name,
                'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
                'price' => $counted[0]['price'],
                'price_qualifier' => count($prices) > 1 ? 'from' : 'exact',
                'currency' => is_string($svc['currency_code'] ?? null) ? $svc['currency_code'] : $currencyDefault,
                'duration_seconds' => $counted[0]['seconds'],
                'category' => $category['name'] ?? null,
                'category_position' => $category['position'] ?? null,
                'position' => $position++,
            ], static fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array{name:?string, phone:?string, email:?string, timezone:?string, currency:?string, websiteUrl:?string, instagramUrl:?string, logoUrl:?string}
     */
    public static function business(array $doc): array
    {
        $loc = is_array($doc['active_business_locations'][0] ?? null) ? $doc['active_business_locations'][0] : [];
        $str = static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $str(data_get($doc, 'business.name')),
            'phone' => $str(data_get($doc, 'business.phone')),
            'email' => $str(data_get($doc, 'business.email')),
            'timezone' => $str(data_get($doc, 'business.timezone')),
            'currency' => $str(data_get($doc, 'business.currency_code')),
            'websiteUrl' => $str($loc['website_url'] ?? null),
            'instagramUrl' => $str($loc['instagram_url'] ?? null),
            'logoUrl' => $str(data_get($doc, 'business.profile_image.url')) ?? $str(data_get($doc, 'seller_brand.logos.framed.url')),
        ];
    }
}
