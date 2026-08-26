<?php

use App\Services\Platforms\ConnectionDisplayName as D;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// R9 (overnight 2026-08-18): the one human name for a connection row.
it('prefers display_name, then the connected thing\'s name (never the brand label), then a prefixed native handle, then a handle captured from the url', function () {
    expect(D::for('github.profile', ['display_name' => 'Linus']))->toBe('Linus')
        ->and(D::for('github.profile', ['url' => 'https://github.com/torvalds', 'name' => 'torvalds - Overview']))->toBe('torvalds')
        ->and(D::for('patreon.page', ['url' => 'https://www.patreon.com/hbomberguy', 'name' => 'Patreon']))->toBe('hbomberguy')
        ->and(D::for('substack.publication', ['url' => 'https://astralcodexten.substack.com', 'name' => 'Substack']))->toBe('astralcodexten')
        ->and(D::for('medium.profile', ['username' => 'julie.zhuo']))->toBe('@julie.zhuo')
        ->and(D::for('x.profile', ['username' => '@elonmusk']))->toBe('@elonmusk')
        ->and(D::for('reddit.profile', ['username' => 'announcements']))->toBe('u/announcements')
        ->and(D::for('discord.server', ['username' => 'minecraft']))->toBe('discord.gg/minecraft')
        ->and(D::for('google_business.listing', ['name' => 'Lower East by RÜH']))->toBe('Lower East by RÜH')
        ->and(D::for('shopify.store', ['name' => 'Atolea Jewelry | Waterproof Jewelry']))->toBe('Atolea Jewelry');
});

it('reads a feed platform\'s handle, not its latest item title, and humanises an ordering store slug over an opaque id', function () {
    expect(D::for('youtube.channel', ['handle' => 'veritasium', 'name' => 'Total Solar Eclipse From Space']))->toBe('@veritasium')
        ->and(D::for('apple_music.artist', ['name' => 'Dracula (Remix) - Single', 'input' => 'https://music.apple.com/au/artist/tame-impala/290242959']))->toBe('Tame Impala')
        ->and(D::for('uber_eats.order', ['url' => 'https://www.ubereats.com/au/store/top-choice-restaurant/rebBnbA2UJmwycojX-Gb8w', 'name' => 'Uber Eats']))->toBe('Top Choice Restaurant')
        ->and(D::for('doordash.order', ['url' => 'https://www.doordash.com/en-AU/store/top-choice-restaurant-wollongong-27510544', 'name' => 'DoorDash']))->toBe('Top Choice Restaurant Wollongong')
        ->and(D::for('menulog.order', ['url' => 'https://www.menulog.com.au/restaurants-top-choice-restaurant-wollongong-2500/menu', 'name' => 'Menulog']))->toBe('Top Choice Restaurant Wollongong')
        ->and(D::for('nowbookit.reserve', ['url' => 'https://bookings.nowbookit.com/?accountid=x&venueid=1']))->toBeNull()
        // A bare host under payload.name (the menu lane's ordering rows) is
        // not a name — the store slug wins (session 3, "def.uber.com").
        ->and(D::for('uber_eats.order', ['url' => 'https://www.ubereats.com/au/store/souva-king/RV0ChXJAXiaEjATmAdjQeg', 'name' => 'def.uber.com']))->toBe('Souva King')
        ->and(D::for('doordash.order', ['url' => 'https://www.doordash.com/store/souva-king-wollongong-23852127/', 'name' => 'doordash.com']))->toBe('Souva King Wollongong');
});

it('shows a whatsapp chat as its dialable number — the phone IS the identity (plan-03 batch 2 find)', function () {
    // The FI-6 all-digits guard and looksOpaque both refuse raw digits on
    // purpose, which left the account label EMPTY for every wa.me row.
    expect(D::for('whatsapp.chat', ['username' => '', 'url' => 'https://wa.me/5591991335229']))->toBe('+5591991335229')
        ->and(D::for('whatsapp.chat', ['url' => 'https://api.whatsapp.com/send?phone=919227007060']))->toBe('+919227007060')
        // A send link with no phone has no identity to show.
        ->and(D::for('whatsapp.chat', ['url' => 'https://www.whatsapp.com/send']))->toBeNull();
});

it('never shows a numeric id captured from the URL as the account name (plan-03 batch 4 find)', function () {
    // tidal.player's detector captures /artist/{id} — all digits. The key
    // loop already refuses digit-only names (FI-6); the URL fallback now
    // does too, falling through to the brand-label tail instead.
    expect(D::for('tidal.player', ['url' => 'https://tidal.com/artist/3648857']))->not->toBe('3648857');
});
