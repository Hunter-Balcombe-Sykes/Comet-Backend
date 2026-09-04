<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\PreAccount\BuildProgress;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Sign-up preview (2026-09-02, A.5): platformEntries() turns a bio-link
// scan's or auto-sync's platform slugs into {platform, handle, url} rows,
// one per slug in the emitter's own order — including a slug with no
// connection row (a conflict finding) still getting a null-filled entry.
it('returns platform/handle/url entries in slug order, with nulls for a missing row', function () {
    setupIntegrationConnectionsTable();
    $user = createTenant('bpe-user');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'tiktok', 'resource_id' => 'tiktok',
        'payload' => ['username' => 'jane_tk', 'url' => 'https://tiktok.com/@jane_tk'], 'is_active' => true,
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'JaneYT', 'link' => 'https://youtube.com/@JaneYT'], 'is_active' => true,
    ]);

    $entries = BuildProgress::platformEntries((string) $user->id, ['tiktok', 'facebook', 'youtube']);

    expect($entries)->toBe([
        ['platform' => 'tiktok', 'handle' => 'jane_tk', 'url' => 'https://tiktok.com/@jane_tk'],
        ['platform' => 'facebook', 'handle' => null, 'url' => null],
        ['platform' => 'youtube', 'handle' => 'JaneYT', 'url' => 'https://youtube.com/@JaneYT'],
    ]);
});

// Both emitters hand this method classify() values, and for every catalog-only
// brand that value is a dotted SURFACE KEY. `platform` is a generated column
// carrying only the brand prefix, so the old whereIn('platform', …) found
// nothing for them and the signup card showed a mark with no handle under it —
// the one thing the method exists to prevent. Found 2026-09-04.
it('finds the row when the emitter names a surface key rather than a legacy slug', function () {
    setupIntegrationConnectionsTable();
    $user = createTenant('bpe-surface');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'calendly.book', 'resource_id' => 'calendly',
        'payload' => ['url' => 'https://calendly.com/jane/30min'], 'is_active' => true,
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bluesky.profile', 'resource_id' => 'jane.bsky.social',
        'payload' => ['username' => 'jane.bsky.social', 'url' => 'https://bsky.app/profile/jane.bsky.social'], 'is_active' => true,
    ]);

    expect(BuildProgress::platformEntries((string) $user->id, ['calendly.book', 'bluesky.profile']))->toBe([
        ['platform' => 'calendly.book', 'handle' => null, 'url' => 'https://calendly.com/jane/30min'],
        ['platform' => 'bluesky.profile', 'handle' => 'jane.bsky.social', 'url' => 'https://bsky.app/profile/jane.bsky.social'],
    ]);
});

// The complement: a legacy slug must keep resolving even though the row's
// surface_key is the dotted form. This is what a straight swap to surface_key
// would have broken.
it('still finds the row when the emitter names the legacy slug', function () {
    setupIntegrationConnectionsTable();
    $user = createTenant('bpe-legacy');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'booksy', 'resource_id' => 'booksy',
        'payload' => ['url' => 'https://booksy.com/en-au/1111_jane'], 'is_active' => true,
    ]);

    expect(BuildProgress::platformEntries((string) $user->id, ['booksy']))->toBe([
        ['platform' => 'booksy', 'handle' => null, 'url' => 'https://booksy.com/en-au/1111_jane'],
    ]);
});
