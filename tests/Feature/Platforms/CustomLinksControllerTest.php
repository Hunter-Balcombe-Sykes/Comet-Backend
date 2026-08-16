<?php

use App\Jobs\Content\EnrichPoolLinkJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Convergence Phase 6: custom links are `content.items` of kind `link` in the
// custom_links pool, not partna.custom_link connections. The ENDPOINTS and their
// JSON are unchanged — only the storage moved — so these cases still assert the
// dashboard's contract, just against the pool.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function customLinksCtrlUser(string $h): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    // A site is required now: links live in a pool SECTION, which hangs off the
    // site. The connection lane could store a link for a siteless user; the pool
    // cannot, and that is the pool's model rather than a regression to paper over.
    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('addLink returns 202 with a host-titled card and no outbound HTTP', function () {
    Queue::fake();
    Http::fake();

    $user = customLinksCtrlUser('asynclink1');

    $res = actingAsUser($user)->postJson('/api/platforms/custom/links', ['url' => 'https://www.example.com/x']);

    // The host stands in until enrichment lands — the same contract the
    // connection lane's minimal card had.
    $res->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('link.name', 'www.example.com');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichPoolLinkJob::class, fn ($j) => $j->userId === (string) $user->id);
});

// Favicon and logo are NOT carried onto the pool (Phase 3's ruling, restated in
// LinkPoolWriter). Pinned so the loss is a decision on record rather than
// something a later reader discovers and "fixes" by reaching for slice 1a's
// borrowed-asset lane.
it('publishes null favicon and logo, deliberately', function () {
    Queue::fake();
    Http::fake();

    $user = customLinksCtrlUser('asynclink3');
    actingAsUser($user)->postJson('/api/platforms/custom/links', ['url' => 'https://www.example.com/y']);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonPath('links.0.favicon', null)
        ->assertJsonPath('links.0.logo', null);
});

it('status endpoint reports ready for an existing link', function () {
    Queue::fake();
    $user = customLinksCtrlUser('asynclink2');
    $itemId = app(LinkPoolWriter::class)->add($user, 'https://example.com');

    actingAsUser($user)
        ->getJson("/api/platforms/custom/links/{$itemId}/status")
        ->assertOk()
        ->assertJsonPath('status', 'ready');
});

it('404s the status of an item that is not the users link', function () {
    $user = customLinksCtrlUser('asynclink4');

    actingAsUser($user)
        ->getJson('/api/platforms/custom/links/'.Str::uuid().'/status')
        ->assertNotFound();
});

it('reorders links and lists them in the new order', function () {
    Queue::fake();
    $user = customLinksCtrlUser('clinkorder');
    $writer = app(LinkPoolWriter::class);

    $a = $writer->add($user, 'https://link-a.test', 'link-a');
    $b = $writer->add($user, 'https://link-b.test', 'link-b');
    $c = $writer->add($user, 'https://link-c.test', 'link-c');

    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => [$c, $a]])
        ->assertOk()
        ->assertJsonPath('links.0.id', $c)
        ->assertJsonPath('links.1.id', $a)
        // Omitted rows keep their relative order after the listed ones.
        ->assertJsonPath('links.2.id', $b);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonPath('links.0.id', $c)
        ->assertJsonPath('links.1.id', $a)
        ->assertJsonPath('links.2.id', $b);
});

// #TEST-1 carried across from the connection lane. There it caught a pure
// sort_order shuffle firing NO observer, leaving site.updated_at pinned on the
// old order for the full cache TTL. The pool lane busts all three lanes through
// SiteCacheLanes::bust(), but the assertion is what proves it — ">0" would pass
// with the bust deleted, which is why it compares timestamps.
it('touches the site on reorder so the cached public payload does not stay pinned on the old order', function () {
    Queue::fake();
    $user = customLinksCtrlUser('clinktouch');
    $writer = app(LinkPoolWriter::class);
    $a = $writer->add($user, 'https://link-a.test', 'link-a');
    $b = $writer->add($user, 'https://link-b.test', 'link-b');

    $before = $user->site->fresh()->updated_at;

    $this->travel(5)->minutes();

    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => [$b, $a]])
        ->assertOk();

    $after = Site::query()->find($user->site->id)->updated_at;

    expect($after->gt($before))->toBeTrue();
});

it('rejects reorder ids that are not the users links', function () {
    $user = customLinksCtrlUser('clinkbad');
    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => [(string) Str::uuid()]])
        ->assertNotFound();
});

it('removes one link and leaves the rest', function () {
    Queue::fake();
    $user = customLinksCtrlUser('clinkdel');
    $writer = app(LinkPoolWriter::class);
    $a = $writer->add($user, 'https://link-a.test', 'link-a');
    $b = $writer->add($user, 'https://link-b.test', 'link-b');

    actingAsUser($user)->deleteJson("/api/platforms/custom/links/{$a}")
        ->assertOk()
        ->assertJsonCount(1, 'links')
        ->assertJsonPath('links.0.id', $b);
});

// An explicit re-add is the owner saying "bring it back". The coord is
// deterministic, so the re-add resolves to the SAME item — which means the
// un-delete in LinkPoolWriter::add() is the only thing standing between the
// owner and a link that can never be re-added.
it('re-adding a removed link brings back the same item', function () {
    Queue::fake();
    Http::fake();
    $user = customLinksCtrlUser('clinkredo');
    $writer = app(LinkPoolWriter::class);
    $id = $writer->add($user, 'https://link-a.test', 'link-a');

    actingAsUser($user)->deleteJson("/api/platforms/custom/links/{$id}")->assertOk();
    actingAsUser($user)->postJson('/api/platforms/custom/links', ['url' => 'https://link-a.test'])
        ->assertStatus(202);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonCount(1, 'links')
        ->assertJsonPath('links.0.id', $id);
});

it('forget removes every link', function () {
    Queue::fake();
    $user = customLinksCtrlUser('clinkforget');
    $writer = app(LinkPoolWriter::class);
    $writer->add($user, 'https://link-a.test', 'link-a');
    $writer->add($user, 'https://link-b.test', 'link-b');

    actingAsUser($user)->deleteJson('/api/platforms/custom')
        ->assertOk()
        ->assertJsonPath('links', []);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonCount(0, 'links');
});
