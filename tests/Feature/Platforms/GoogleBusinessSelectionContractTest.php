<?php

// TEST-1: Google Business /selection exact-shape snapshot.
//
// GoogleBusinessConnectionResource is the ONLY dashboard resource that uses
// array_intersect_key() to spread ENRICHMENT_KEYS — highest drift/leak risk.
// We pin both the minimal (link-parse) shape and the enriched shape so any
// future change to the ENRICHMENT_KEYS list or the 5-key base set is
// immediately visible as a CI failure.
//
// Note on "private" keys: placeId/phoneIntl/priceLevel/priceRange/detailsFetchedAt
// ARE in ENRICHMENT_KEYS and are deliberately emitted on the dashboard resource
// (dashboard-only — the PUBLIC allowlist strips them). The test below pins this
// behaviour: they appear in the dashboard selection but NOT in keys outside the
// allowlist (e.g. _internal).

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function gbSelUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── (a) Minimal link-parse shape (no enrichment keys stored) ─────────────────

it('google-business selection freezes the minimal 5-key link-parse shape', function () {
    $user = gbSelUser('gbsel1');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'name' => 'Fade Lab Barbers',
            'address' => '12 Example St, Melbourne VIC 3000',
            'lat' => -37.8123,
            'lng' => 144.9601,
            // Extra key outside the allowlist — must not appear in the selection.
            '_internal' => 'leak',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->json('selection');

    // Exact minimal 5-key shape: base fields only, _internal stripped.
    expect($selection)->toEqual([
        'url' => 'https://maps.google.com/?cid=1',
        'name' => 'Fade Lab Barbers',
        'address' => '12 Example St, Melbourne VIC 3000',
        'lat' => -37.8123,
        'lng' => 144.9601,
    ]);

    // Explicitly assert extra keys outside the allowlist do not leak.
    expect($selection)->not->toHaveKey('_internal', "'_internal' must not appear in the dashboard selection");
});

// ── (b) Enriched shape (Place Details merged in) ──────────────────────────────
//
// The resource spreads all ENRICHMENT_KEYS via array_intersect_key. Pin the
// exact emitted set — including the dashboard-only keys placeId/phoneIntl/
// priceLevel/priceRange/detailsFetchedAt — so any ENRICHMENT_KEYS change
// is caught immediately. The PUBLIC endpoint strips these five; only the
// public allowlist test in GoogleBusinessDetailsTest.php covers that assertion.

it('google-business selection freezes the full enriched shape', function () {
    $user = gbSelUser('gbsel2');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            // Core 5 (always emitted):
            'url' => 'https://maps.google.com/?cid=2',
            'name' => 'Fade Lab Barbers',
            'address' => '12 Example St, Melbourne VIC 3000',
            'lat' => -37.8123,
            'lng' => 144.9601,
            // ENRICHMENT_KEYS — all present so the intersect spreads them:
            'placeId' => 'ChIJtest',
            'businessStatus' => 'OPERATIONAL',
            'category' => 'Barber shop',
            'phone' => '(03) 9123 4567',
            'phoneIntl' => '+61 3 9123 4567',
            'website' => 'https://fadelab.example',
            'rating' => 4.8,
            'reviewCount' => 127,
            'hours' => ['weekdays' => ['Monday: 9:00 AM – 5:00 PM']],
            'currentHours' => ['weekdays' => ['Monday: Closed']],
            'addressParts' => ['postcode' => '3000', 'suburb' => 'Melbourne', 'state' => 'VIC'],
            'links' => ['writeReview' => 'https://example.google/writereview'],
            'priceLevel' => 'PRICE_LEVEL_MODERATE',
            'priceRange' => ['startPrice' => ['units' => '20']],
            'editorialSummary' => 'A neighbourhood barber shop.',
            'reviewSummary' => 'People love the fades.',
            'reviews' => [['author' => 'Reviewer 1', 'rating' => 5, 'text' => 'Great cut']],
            'amenities' => ['goodForChildren' => true],
            'photos' => [['url' => 'https://lh3.googleusercontent.com/p/example']],
            'streetView' => ['panoId' => 'pano123', 'lat' => -37.8123, 'lng' => 144.9601],
            'detailsFetchedAt' => '2026-06-01T00:00:00+00:00',
            'menu' => 'https://fadelab.example/menu',
            'reservation' => ['url' => 'https://book.example/fadelab'],
            'order' => ['googleFood' => 'https://food.example/fadelab'],
            'booking' => ['https://booking.example/fadelab'],
            'socials' => ['instagram' => 'https://instagram.com/fadelab'],
            'apifyStatus' => 'ok',
            'apifyFetchedAt' => '2026-06-16T00:00:00+00:00',
            // Extra key outside the allowlist — must not appear in the selection.
            '_internal' => 'leak',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/google-business/selection')
        ->assertOk()
        ->json('selection');

    // Exact enriched shape — every key in ENRICHMENT_KEYS + the 5 base keys.
    // Order mirrors the resource: base 5 first, then ENRICHMENT_KEYS in declaration order.
    expect($selection)->toEqual([
        'url' => 'https://maps.google.com/?cid=2',
        'name' => 'Fade Lab Barbers',
        'address' => '12 Example St, Melbourne VIC 3000',
        'lat' => -37.8123,
        'lng' => 144.9601,
        'placeId' => 'ChIJtest',
        'businessStatus' => 'OPERATIONAL',
        'category' => 'Barber shop',
        'phone' => '(03) 9123 4567',
        'phoneIntl' => '+61 3 9123 4567',
        'website' => 'https://fadelab.example',
        'rating' => 4.8,
        'reviewCount' => 127,
        'hours' => ['weekdays' => ['Monday: 9:00 AM – 5:00 PM']],
        'currentHours' => ['weekdays' => ['Monday: Closed']],
        'addressParts' => ['postcode' => '3000', 'suburb' => 'Melbourne', 'state' => 'VIC'],
        'links' => ['writeReview' => 'https://example.google/writereview'],
        'priceLevel' => 'PRICE_LEVEL_MODERATE',
        'priceRange' => ['startPrice' => ['units' => '20']],
        'editorialSummary' => 'A neighbourhood barber shop.',
        'reviewSummary' => 'People love the fades.',
        'reviews' => [['author' => 'Reviewer 1', 'rating' => 5, 'text' => 'Great cut']],
        'amenities' => ['goodForChildren' => true],
        'photos' => [['url' => 'https://lh3.googleusercontent.com/p/example']],
        'streetView' => ['panoId' => 'pano123', 'lat' => -37.8123, 'lng' => 144.9601],
        'detailsFetchedAt' => '2026-06-01T00:00:00+00:00',
        'menu' => 'https://fadelab.example/menu',
        'reservation' => ['url' => 'https://book.example/fadelab'],
        'order' => ['googleFood' => 'https://food.example/fadelab'],
        'booking' => ['https://booking.example/fadelab'],
        'socials' => ['instagram' => 'https://instagram.com/fadelab'],
        'apifyStatus' => 'ok',
        'apifyFetchedAt' => '2026-06-16T00:00:00+00:00',
    ]);

    // Extra keys outside the allowlist must not leak.
    expect($selection)->not->toHaveKey('_internal', "'_internal' must not appear in the dashboard selection");
});
