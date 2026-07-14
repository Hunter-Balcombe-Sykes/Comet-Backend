<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use Illuminate\Support\Str;

// BE2: mirrors GoogleBusinessAutoSync::seed()'s shape (only-if-empty seeding,
// conflict findings carrying an `apply` swap recipe, findings returned for the
// caller to persist as syncFindings) but for Instagram bio links, classified
// via WebsiteLinkHarvester::classify() rather than a Google Apify enrichment.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igAutoSyncUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── social: seed only-if-empty ────────────────────────────────────────────────

it('seeds a facebook, tiktok, x and linkedin connection from bio links when none exist', function () {
    $user = igAutoSyncUser('igas1');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/docpizzabar',
        'https://www.tiktok.com/@docpizza',
        'https://x.com/docpizza',
        'https://www.linkedin.com/in/docpizza',
    ]);

    expect($result['unmatched'])->toBe([]);
    expect(collect($result['findings'])->pluck('outcome')->unique()->all())->toBe(['seeded']);
    expect(collect($result['findings'])->pluck('platform')->all())->toBe(['facebook', 'tiktok', 'x', 'linkedin']);
    expect(collect($result['findings'])->pluck('category')->unique()->all())->toBe(['social']);

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/docpizzabar');
    expect($fb['username'])->toBe('docpizzabar');
    expect($fb['source'])->toBe('instagram');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'tiktok')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'x')->exists())->toBeTrue();
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'linkedin')->exists())->toBeTrue();
});

it('never overwrites a social connection the user already set with the same url (only-if-empty is silent, not a finding)', function () {
    $user = igAutoSyncUser('igas2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([]);
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['source'])->toBe('manual'); // untouched
});

it('marks conflict when a social platform exists with a DIFFERENT url, leaving the existing row untouched', function () {
    $user = igAutoSyncUser('igas3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);

    expect($result['findings'])->toHaveCount(1);
    expect($result['findings'][0]['platform'])->toBe('facebook');
    expect($result['findings'][0]['category'])->toBe('social');
    expect($result['findings'][0]['outcome'])->toBe('conflict');
    expect($result['findings'][0]['foundUrl'])->toBe('https://www.facebook.com/docpizzabar');
    expect($result['findings'][0]['apply']['remove'])->toBe(['facebook']);
    expect($result['findings'][0]['apply']['write']['payload']['url'])->toBe('https://www.facebook.com/docpizzabar');

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['source'])->toBe('manual'); // untouched — conflict does not overwrite
    expect($fb['url'])->toBe('https://facebook.com/mine');
});

// ── booking: fresha / square ───────────────────────────────────────────────────

it('seeds fresha and square booking connections with url-only payloads', function () {
    $user = igAutoSyncUser('igas4');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.fresha.com/a/doc-cuts',
        'https://acme.square.site',
    ]);

    expect(collect($result['findings'])->pluck('category')->unique()->all())->toBe(['booking']);

    $fresha = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    expect($fresha)->toMatchArray(['url' => 'https://www.fresha.com/a/doc-cuts', 'selection' => null, 'source' => 'instagram']);

    $square = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'square')->firstOrFail()->payload;
    expect($square)->toMatchArray(['url' => 'https://acme.square.site', 'source' => 'instagram']);
});

it('marks conflict for booking when the platform exists with a different url', function () {
    $user = igAutoSyncUser('igas5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/mine', 'selection' => null, 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.fresha.com/a/doc-cuts']);

    expect($result['findings'][0]['outcome'])->toBe('conflict');
    expect($result['findings'][0]['platform'])->toBe('fresha');
    $fresha = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    expect($fresha['url'])->toBe('https://www.fresha.com/a/mine'); // untouched
});

// ── unmatched: unclassified + classified-but-not-actionable ──────────────────

it('routes a genuinely unclassified link to unmatched with a host-derived label', function () {
    $user = igAutoSyncUser('igas6');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://linktr.ee/docpizza']);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toBe([['url' => 'https://linktr.ee/docpizza', 'label' => 'linktr.ee']]);
});

it('routes classified-but-not-auto-synced links (youtube, opentable, instagram) to unmatched with their real label', function () {
    $user = igAutoSyncUser('igas7');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.youtube.com/@docpizza',
        'https://www.opentable.com.au/r/doc-pizza',
        'https://www.instagram.com/anotheraccount',
    ]);

    expect($result['findings'])->toBe([]);
    expect($result['unmatched'])->toContain(['url' => 'https://www.youtube.com/@docpizza', 'label' => 'YouTube']);
    expect($result['unmatched'])->toContain(['url' => 'https://www.opentable.com.au/r/doc-pizza', 'label' => 'OpenTable']);
    expect($result['unmatched'])->toContain(['url' => 'https://www.instagram.com/anotheraccount', 'label' => 'Instagram']);
    // None of these caused any IntegrationConnection writes.
    foreach (['youtube', 'opentable', 'instagram'] as $p) {
        expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', $p)->exists())->toBeFalse();
    }
});

// ── de-dupe / edge cases ───────────────────────────────────────────────────────

it('first bio link per platform wins when two links classify to the same platform', function () {
    $user = igAutoSyncUser('igas8');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, [
        'https://www.facebook.com/first',
        'https://www.facebook.com/second',
    ]);

    expect($result['findings'])->toHaveCount(1);
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/first');
});

it('returns empty findings and unmatched for an empty bio-links list', function () {
    $user = igAutoSyncUser('igas9');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, []);

    expect($result)->toBe(['findings' => [], 'unmatched' => []]);
});

it('skips malformed bio-link entries without throwing', function () {
    $user = igAutoSyncUser('igas10');

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['', '   ', 123, null]);

    expect($result)->toBe(['findings' => [], 'unmatched' => []]);
});

// ── applyFinding() — "Change to" swap, mirrors GoogleBusinessAutoSync::applyFinding ──

it('applyFinding removes the existing connection and writes the found one', function () {
    $user = igAutoSyncUser('igas11');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $result = app(InstagramAutoSync::class)->seed((string) $user->id, ['https://www.facebook.com/docpizzabar']);
    $finding = $result['findings'][0];
    expect($finding['outcome'])->toBe('conflict');

    app(InstagramAutoSync::class)->applyFinding((string) $user->id, $finding);

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->get();
    expect($fb)->toHaveCount(1); // old one removed, new one written — not both
    expect($fb->first()->payload['url'])->toBe('https://www.facebook.com/docpizzabar');
    expect($fb->first()->payload['source'])->toBe('instagram');
});

it('applyFinding is a safe no-op for a malformed/seeded finding with no apply recipe', function () {
    $user = igAutoSyncUser('igas12');

    app(InstagramAutoSync::class)->applyFinding((string) $user->id, ['platform' => 'facebook', 'outcome' => 'seeded', 'apply' => null]);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
