<?php

/**
 * F22 (cold-build audit, 2026-08-31). GoogleMenuPhotoScanJob checked
 * can_use_menu above its first Log::info, so a denied scan was indistinguishable
 * from a scan that never ran: pret-a-manger returned in 14.35ms having logged
 * nothing, with 0 menu items on a sandwich chain.
 *
 * The gate itself is not the bug and is not touched here — AccountCapabilities'
 * sector clause is LAW. The cause is fixed upstream by mapping the category
 * (SectorTaxonomyGoogleCategoryTest); this pins the trace that makes the NEXT
 * unmapped category visible in minutes rather than in a quarterly audit.
 */

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleMenuImagesScraper;
use App\Services\Platforms\MenuAiExtractor;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function capabilityLogUser(string $handle, string $accountType, ?string $sector): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/**
 * The job's three collaborators, resolved the way the queue worker resolves
 * them. None of them is reached on a denial — the point of the assertion is
 * that the job returns before spending anything.
 */
function runMenuScan(User $user, string $placeId = 'ChIJU6LqOtYEdkgRnvEzd4hFOGM'): void
{
    (new GoogleMenuPhotoScanJob((string) $user->id, $placeId))->handle(
        app(MenuAiExtractor::class),
        app(GoogleMenuImagesScraper::class),
        app(MenuScanApplier::class),
    );
}

it('says why it skipped when the account cannot use a menu', function () {
    Log::spy();

    $user = capabilityLogUser('pret-a-manger', 'business', null);
    runMenuScan($user);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $ctx) => $message === 'google_menu_scan.capability_denied'
            && $ctx['user_id'] === (string) $user->id
            && $ctx['sector'] === null
            && $ctx['sector_missing'] === true);
});

/**
 * A deliberate non-food account is denied too, and must be distinguishable from
 * the classification miss above — otherwise the log line is noise and nobody
 * reads it. sector_missing is the whole discriminator.
 */
it('marks a deliberate non-food denial as classified, not missing', function () {
    Log::spy();

    $user = capabilityLogUser('gate-barbershop', 'business', 'barber');
    runMenuScan($user);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $ctx) => $message === 'google_menu_scan.capability_denied'
            && $ctx['sector'] === 'barber'
            && $ctx['sector_missing'] === false);
});

/**
 * The counterweight: a food account must NOT trip the denial line. It gets
 * past the gate and stops at the next guard instead (an unconfigured
 * extractor in the test environment), which is its own log message.
 */
it('does not log a denial for an account that can use a menu', function () {
    Log::spy();

    $user = capabilityLogUser('ollies', 'business', 'cafe');
    runMenuScan($user);

    Log::shouldNotHaveReceived('info', ['google_menu_scan.capability_denied', Mockery::any()]);
});

/**
 * Sandwich Shop is the category that started this. Once the taxonomy maps it,
 * the account that used to be denied is not denied — the two halves of F5/F22
 * meeting in one assertion.
 */
it('no longer denies the account whose Google category now classifies', function () {
    $sector = SectorTaxonomy::fromGoogleCategory('Sandwich Shop');

    Log::spy();
    $user = capabilityLogUser('pret-a-manger-mapped', 'business', $sector);
    runMenuScan($user);

    Log::shouldNotHaveReceived('info', ['google_menu_scan.capability_denied', Mockery::any()]);
});
