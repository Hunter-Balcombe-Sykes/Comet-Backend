<?php

use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\User\HandleAllocator;
use App\Services\User\SiteProvisioningService;
use App\Support\BusinessName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// B4 (spec 2026-08-18-pipeline-assurance §5). SIGNUP-1 shipped because the two
// normalisations were only ever tested on a slug-shaped seed. This is the
// property version: for ANY name, the allocated handle is a valid DNS label and
// re-deriving a subdomain from it is the identity — so handle == subdomain holds
// structurally, not by luck. Plus the business-name cap that guards the owner's
// first PATCH after claim (display_name max:15 for business accounts).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

dataset('ugly_names', [
    'apostrophe' => ["Beef's Barbers"],
    'periods' => ['D.O.C. Pizza'],
    'accented n' => ['Añada'],
    'accented e' => ['Café Été'],
    '23 chars' => ['Melbourne Tattoo Company'],
    'ampersand' => ['Bar & Grill'],
    'emoji' => ['Glow ✨ Studio'],
    'leading digit' => ['3 Little Pigs'],
    'all digits' => ['1234'],
    'double spaces' => ['Two  Spaces   Here'],
    'trailing punctuation' => ['Errol\'s.'],
    'leading punctuation' => ['-Dash Studio'],
    'all caps' => ['LOUD BARBERS'],
    'hyphenated' => ['Cut-Throat Barbers'],
    'slash' => ['Hair/Beauty'],
    'parentheses' => ['Acme (Carlton)'],
    'quotes' => ['"Quoted" Salon'],
    'plus' => ['A+ Nails'],
    'underscore' => ['snake_case_studio'],
    'very long' => [str_repeat('Supercalifragilistic', 4)],
    'single char' => ['X'],
    'unicode only' => ['日本料理'],
    'mixed unicode' => ['Ramen 一番'],
    'at sign ig style' => ['@janedoe'],
    'dotted ig' => ['jane.doe.hair'],
    'reserved word' => ['admin'],
    'reserved www' => ['www'],
    'trailing hyphen slug' => ['Salon-'],
    'ellipsis' => ['Wait…'],
    'tabs' => ["Tab\tName"],
]);

it('allocates a handle that is a valid DNS label and a subdomain fixed point', function (string $name) {
    $handle = app(HandleAllocator::class)->allocate($name)['handle_lc'];
    $subdomain = app(SiteProvisioningService::class)->subdomainBaseFromHandle($handle);

    expect($handle)->toMatch('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', "handle '{$handle}' from '{$name}' is not a DNS label")
        ->and($subdomain)->toBe($handle, "subdomainBaseFromHandle('{$handle}') diverged to '{$subdomain}' for '{$name}'");
})->with('ugly_names');

it('word-trims a business name to ≤ 15 chars at a word boundary', function (string $name) {
    $trimmed = BusinessName::wordTrim($name);
    $squished = Str::squish($name);

    expect(mb_strlen($trimmed))->toBeLessThanOrEqual(15);
    if ($squished !== '' && mb_strlen($squished) <= 15) {
        expect($trimmed)->toBe($squished);
    } elseif ($trimmed !== '' && ! str_contains($trimmed, ' ') && mb_strlen(explode(' ', $squished)[0]) > 15) {
        // single over-long first word: a hard cut is the documented behaviour
        expect(mb_strlen($trimmed))->toBe(15);
    } elseif ($trimmed !== '') {
        // multi-word: the kept prefix must end exactly at a word boundary
        expect(str_starts_with($squished, $trimmed))->toBeTrue("'{$trimmed}' is not a prefix of '{$squished}'")
            ->and(mb_strlen($squished) === mb_strlen($trimmed) || mb_substr($squished, mb_strlen($trimmed), 1) === ' ')->toBeTrue("'{$trimmed}' ends mid-word");
    }
})->with('ugly_names');

it('converges handle and subdomain end to end on the business path for the ugliest names', function (string $name, string $salt) {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'business', 'google_business', 'ChIJ'.md5($name), $name, hash('sha256', $salt),
    )['build'];

    $subdomain = DB::connection('pgsql')->table('site.sites')->where('user_id', $build->user_id)->value('subdomain');
    $user = User::query()->find($build->user_id);

    expect($subdomain)->not->toBeNull()
        ->and(strtolower((string) $subdomain))->toBe($user->handle_lc);
})->with([
    'apostrophe' => ["Beef's Barbers", 'a'],
    'accented n' => ['Añada', 'b'],
    'emoji' => ['Glow ✨ Studio', 'c'],
    'unicode only' => ['日本料理', 'd'],
]);
