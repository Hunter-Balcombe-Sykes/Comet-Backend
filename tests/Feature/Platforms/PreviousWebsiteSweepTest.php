<?php

use App\Jobs\Platforms\SweepPreviousWebsiteCardsJob;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Plan 3 R2 (backend-fixes item 5): the previous-website race. Cards seeded
// BEFORE workplace.previous_website landed are retired when it does — but
// ONLY scrape-seeded ones; a card the owner typed, and every untagged legacy
// card, survive by construction.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function pwsUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'business', 'sector' => 'restaurant', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $handle,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

it('retires scrape-seeded cards of the previous website while manual and legacy cards survive', function () {
    $user = pwsUser('pws1');
    $writer = app(LinkPoolWriter::class);

    // The race's leftovers: two scrape-seeded old-site pages, one manual paste
    // on the same host, one legacy (untagged) card on the same host, and one
    // unrelated scrape card.
    $writer->add($user, 'https://stali.com.au/pages/rewards', enrich: false, origin: 'scrape');
    $writer->add($user, 'https://stali.com.au/collections/new', enrich: false, origin: 'scrape');
    $writer->add($user, 'https://www.stali.com.au/pages/story', enrich: false, origin: 'manual');
    $writer->add($user, 'https://stali.com.au/legacy-card', enrich: false);
    $writer->add($user, 'https://other-site.example/page', enrich: false, origin: 'scrape');

    (new SweepPreviousWebsiteCardsJob((string) $user->id, 'https://www.stali.com.au'))->handle();

    $live = DB::connection('pgsql')->table('content.items')
        ->where('user_id', $user->id)->where('kind', 'link')->whereNull('removed_at')
        ->join('content.f_link', 'content.f_link.item_id', '=', 'content.items.id')
        ->pluck('content.f_link.url')->all();

    expect($live)->toContain('https://www.stali.com.au/pages/story')  // manual survives
        ->toContain('https://stali.com.au/legacy-card')               // untagged survives
        ->toContain('https://other-site.example/page')                // other host survives
        ->not->toContain('https://stali.com.au/pages/rewards')
        ->not->toContain('https://stali.com.au/collections/new');
});

it('is lane-order independent: seeding first then sweeping equals the guard having existed', function () {
    $user = pwsUser('pws2');
    app(LinkPoolWriter::class)->add($user, 'https://oldsite.example/menu', enrich: false, origin: 'scrape');

    (new SweepPreviousWebsiteCardsJob((string) $user->id, 'https://oldsite.example'))->handle();

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $user->id)->where('kind', 'link')->whereNull('removed_at')->count())->toBe(0);
});

it('does nothing for an unparseable previous website', function () {
    $user = pwsUser('pws3');
    app(LinkPoolWriter::class)->add($user, 'https://oldsite.example/menu', enrich: false, origin: 'scrape');

    (new SweepPreviousWebsiteCardsJob((string) $user->id, 'not a url'))->handle();

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $user->id)->where('kind', 'link')->whereNull('removed_at')->count())->toBe(1);
});
