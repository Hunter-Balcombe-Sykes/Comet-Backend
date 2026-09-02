<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function customLinksUser(string $h): User
{
    $user = User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h), 'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

// Convergence Phase 6: the card is assembled from the pool item's facets
// (f_link.url, f_text.headline/body) rather than a connection payload blob.
// favicon/logo are null BY DESIGN — see LinkPoolWriter.
it('lists a stored custom link with its full card shape', function () {
    // The /platforms/custom/links endpoint left 2026-08-19; LinkPoolReader's
    // cards() IS the card shape every live reader consumes.
    $user = customLinksUser('clink');
    $id = app(LinkPoolWriter::class)->add($user, 'https://acme.test', 'Acme', 'Best');

    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1);
    expect($cards[0]['id'])->toBe($id);
    expect($cards[0]['url'])->toBe('https://acme.test');
    expect($cards[0]['name'])->toBe('Acme');
    expect($cards[0]['description'])->toBe('Best');
    expect($cards[0]['favicon'])->toBeNull();
    expect($cards[0]['logo'])->toBeNull();
});

it('stamps the catalog platform onto a link whose host it knows, and nothing onto one it does not', function () {
    $user = customLinksUser('clinkpf');
    $writer = app(LinkPoolWriter::class);

    $youtube = $writer->add($user, 'https://www.youtube.com/@acmebarbers', 'Acme on YouTube');
    $custom = $writer->add($user, 'https://acme-barbers.example', 'Acme');
    // The enrich job's second pass re-adds the same URL — one row, not two.
    $writer->add($user, 'https://www.youtube.com/@acmebarbers', 'Acme on YouTube', enrich: false);

    $stamped = DB::connection('pgsql')->table('content.item_links')->where('item_id', $youtube)->get();
    expect($stamped)->toHaveCount(1)
        ->and($stamped[0]->platform)->toBe('youtube')
        ->and($stamped[0]->url)->toBe('https://www.youtube.com/@acmebarbers');
    expect(DB::connection('pgsql')->table('content.item_links')->where('item_id', $custom)->exists())->toBeFalse();
});
