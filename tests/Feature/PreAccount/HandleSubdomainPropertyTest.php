<?php

use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\User\HandleAllocator;
use App\Services\User\SiteProvisioningService;
use App\Support\BusinessName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// B4 (spec 2026-08-18-pipeline-assurance §5). Two independent properties over
// ~30 deliberately ugly names:
//   1. every name allocates a valid DNS-label handle (below).
//   2. wordTrim() never exceeds the 15-char business-name cap and never cuts
//      mid-word.
//
// The handle==subdomain invariant itself (SIGNUP-1) is NOT proven by
// re-deriving a subdomain from the handle and comparing — see the correction
// on the first it() below. It is guarded by HandleSubdomainCallSiteGuardTest
// (pins that no signup path may re-derive) and driven end-to-end by the third
// test here, over the nastiest names in this file's own dataset.

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

// NOT a SIGNUP-1 regression guard. Corrected per review 2026-08-19: this test
// was originally written (and its "fixed point" framing implied) that
// re-deriving a subdomain from the allocated handle via
// SiteProvisioningService::subdomainBaseFromHandle() and comparing it back
// proves handle==subdomain. It does not, for three compounding reasons:
//   1. subdomainBaseFromHandle() has ZERO live call sites — the real signup
//      path (createSiteForHandle(), :39) sets
//      `$subdomain = strtolower(trim($handleLc))` directly, verbatim reuse,
//      no re-derivation.
//   2. HandleSubdomainCallSiteGuardTest explicitly FORBIDS either signup call
//      site from calling ->subdomainBaseFromHandle( — the SIGNUP-1 fix was to
//      stop re-deriving, not to make re-derivation idempotent.
//   3. HandleAllocator::allocate() already guarantees lowercase [a-z0-9-] with
//      no leading/trailing hyphen, and subdomainBaseFromHandle() only replaces
//      [^a-z0-9]+ and trims '-' — a no-op on a string already in that shape.
//      So whenever the DNS-label assertion below passes, the second assertion
//      passes by mathematical necessity; it cannot independently fail and
//      could not have caught the original SIGNUP-1 bug (a different,
//      now-removed re-derivation path).
// What this test actually proves — genuine, worth keeping — is property 1:
// EVERY one of the 30 ugly names allocates a handle that is a valid DNS
// label. The second assertion is kept only as a labelled-honest idempotence
// property of subdomainBaseFromHandle() itself, a decommissioned helper that
// still exists on the class; it is not evidence for the handle==subdomain
// invariant. That invariant is guarded elsewhere: statically by
// HandleSubdomainCallSiteGuardTest (no signup path may re-derive), and
// end-to-end by the third test below (drives requestBuild() for real and
// reads both sides back out of the DB).
it('allocates a handle that is a valid DNS label for every ugly name', function (string $name) {
    $handle = app(HandleAllocator::class)->allocate($name)['handle_lc'];

    expect($handle)->toMatch('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', "handle '{$handle}' from '{$name}' is not a DNS label");

    // Idempotence of the decommissioned subdomainBaseFromHandle() helper on an
    // already-valid handle — a property of that helper, NOT the SIGNUP-1
    // invariant (see comment above). Holds trivially per point 3 above.
    $subdomain = app(SiteProvisioningService::class)->subdomainBaseFromHandle($handle);
    expect($subdomain)->toBe($handle, "subdomainBaseFromHandle('{$handle}') is not idempotent on its own already-valid output for '{$name}'");
})->with('ugly_names');

it('word-trims a business name to ≤ 15 chars at a word boundary', function (string $name) {
    $trimmed = BusinessName::wordTrim($name);
    $squished = Str::squish($name);

    expect(mb_strlen($trimmed))->toBeLessThanOrEqual(15);
    if ($squished !== '' && mb_strlen($squished) <= 15) {
        expect($trimmed)->toBe($squished);
    } elseif ($trimmed !== '' && ! str_contains($trimmed, ' ') && mb_strlen(explode(' ', $squished)[0]) > 15) {
        // single over-long first word: a hard cut is the documented behaviour.
        // wordTrim() also strips trailing punctuation off the cut (BusinessName
        // :30-38), so the result can legitimately be SHORTER than 15 — <= is the
        // actual rule, not ==.
        expect(mb_strlen($trimmed))->toBeLessThanOrEqual(15);
    } elseif ($trimmed !== '') {
        // multi-word: the kept prefix must end exactly at a word boundary
        expect(str_starts_with($squished, $trimmed))->toBeTrue("'{$trimmed}' is not a prefix of '{$squished}'")
            ->and(mb_strlen($squished) === mb_strlen($trimmed) || mb_substr($squished, mb_strlen($trimmed), 1) === ' ')->toBeTrue("'{$trimmed}' ends mid-word");
    }
})->with('ugly_names');

// THE real invariant, driven end-to-end: requestBuild() -> HandleAllocator ->
// createSiteForHandle() (verbatim reuse, no re-derivation) -> read BOTH sides
// back out of the DB. 10 of the nastiest names in this file's dataset, each
// with its own ipHash (per-IP cap = 3 unclaimed builds).
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
    'leading digit' => ['3 Little Pigs', 'e'],
    '23 chars' => ['Melbourne Tattoo Company', 'f'],
    'all caps' => ['LOUD BARBERS', 'g'],
    'double spaces' => ['Two  Spaces   Here', 'h'],
    'trailing punctuation' => ["Errol's.", 'i'],
    'periods' => ['D.O.C. Pizza', 'j'],
]);
