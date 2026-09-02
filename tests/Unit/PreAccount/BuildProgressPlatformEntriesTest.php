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
