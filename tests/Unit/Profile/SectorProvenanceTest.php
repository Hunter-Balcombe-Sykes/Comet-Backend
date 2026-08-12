<?php

// The sector precedence ladder. Every case here encodes a rule that was got
// backwards at least once during design — see the spec's revision notes.

use App\Models\Core\User\User;
use App\Services\Profile\SectorProvenance;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** A bare in-memory model. mayWrite reads two attributes and touches no database. */
function provenanceUser(?string $sector, ?string $source): User
{
    $user = new User;
    $user->sector = $sector;
    $user->sector_source = $source;

    return $user;
}

it('lets any recognised source fill a blank value, whatever provenance is stamped', function (?string $sector, ?string $source) {
    foreach ([SectorProvenance::INSTAGRAM, SectorProvenance::GOOGLE, SectorProvenance::MANUAL] as $incoming) {
        expect(SectorProvenance::mayWrite(provenanceUser($sector, $source), $incoming))
            ->toBeTrue("{$incoming} should fill a blank value");
    }
})->with([
    'null value, null source' => [null, null],
    'empty value, null source' => ['', null],
    'whitespace value, null source' => [' ', null],
    'null value, instagram source' => [null, 'instagram'],
    'empty value, manual source' => ['', 'manual'],
]);

it('ranks manual above google above instagram', function () {
    // Google beats instagram.
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::GOOGLE))->toBeTrue();
    // Instagram loses to google.
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::INSTAGRAM))->toBeFalse();
    // Manual beats both.
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::MANUAL))->toBeTrue();
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::MANUAL))->toBeTrue();
    // Nothing automated beats manual.
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::GOOGLE))->toBeFalse();
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::INSTAGRAM))->toBeFalse();
});

it('lets google and manual refresh their own value but never instagram', function () {
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::GOOGLE))->toBeTrue();
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::MANUAL))->toBeTrue();
    // Instagram may not: PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback, and the
    // dashboard refresh button would otherwise silently rewrite a stored sector.
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::INSTAGRAM))->toBeFalse();
});

it('treats a set value with unrecognised provenance as unwritable', function (?string $source) {
    foreach ([SectorProvenance::INSTAGRAM, SectorProvenance::GOOGLE, SectorProvenance::MANUAL] as $incoming) {
        expect(SectorProvenance::mayWrite(provenanceUser('restaurant', $source), $incoming))
            ->toBeFalse("{$incoming} must not overwrite a row it did not write");
    }
})->with([
    'null source (the mass-assignment shape)' => [null],
    'bogus source' => ['bogus'],
]);

it('refuses an unrecognised incoming source even on a blank row', function () {
    // Fail-closed BEFORE the blank short-circuit: users_sector_source_check
    // permits exactly three values, and a 23514 kills the whole connect job.
    expect(SectorProvenance::mayWrite(provenanceUser(null, null), 'facebook'))->toBeFalse();
    expect(SectorProvenance::mayWrite(provenanceUser('', null), 'facebook'))->toBeFalse();
});

it('identifies a food demotion only on a business account leaving a food sector', function () {
    // The case that matters: business, currently food, moving to non-food.
    expect(SectorProvenance::isFoodDemotion(true, 'restaurant', 'event-venue'))->toBeTrue();
    expect(SectorProvenance::isFoodDemotion(true, 'cafe', 'barber'))->toBeTrue();

    // Not a business — sector gates no capability, so nothing to protect.
    expect(SectorProvenance::isFoodDemotion(false, 'restaurant', 'event-venue'))->toBeFalse();
    // Not currently food.
    expect(SectorProvenance::isFoodDemotion(true, 'barber', 'event-venue'))->toBeFalse();
    expect(SectorProvenance::isFoodDemotion(true, null, 'event-venue'))->toBeFalse();
    // Staying in food.
    expect(SectorProvenance::isFoodDemotion(true, 'restaurant', 'cafe'))->toBeFalse();
});

it('logs a transition with both sources and an outcome', function () {
    $user = provenanceUser('cafe', 'google-business');
    $user->id = '00000000-0000-4000-8000-000000000001';

    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
        return $message === 'sector.transition'
            && $context['from'] === 'cafe'
            && $context['from_source'] === 'google-business'
            && $context['to'] === 'barber'
            && $context['to_source'] === 'google-business'
            && $context['outcome'] === 'applied'
            && $context['user_id'] === '00000000-0000-4000-8000-000000000001'
            && $context['caller'] === 'IdentitySync::applySector';
    });

    SectorProvenance::logTransition($user, 'barber', SectorProvenance::GOOGLE, 'IdentitySync::applySector');
});

it('distinguishes a refusal from an applied write', function () {
    $user = provenanceUser('restaurant', 'google-business');
    $user->id = '00000000-0000-4000-8000-000000000002';

    Log::shouldReceive('info')->once()->withArgs(
        fn (string $message, array $context) => $message === 'sector.transition'
            && $context['outcome'] === 'refused_food_demotion'
            && $context['caller'] === 'IdentitySync::applySector'
    );

    SectorProvenance::logTransition($user, 'event-venue', SectorProvenance::GOOGLE, 'IdentitySync::applySector', 'refused_food_demotion');
});

it('ranks exactly the sources the migrations permit', function () {
    // Fast-lane mirror of tests/Schema/SectorSourceCheckTest.php, which does not
    // run in `composer test`. Scans every migration, not just the baseline — a
    // later one is how the CHECK would actually widen.
    $allowed = [];
    foreach (glob(base_path('supabase/migrations/*.sql')) as $file) {
        $sql = file_get_contents($file);
        if (! str_contains($sql, 'users_sector_source_check')) {
            continue;
        }
        preg_match('/users_sector_source_check.*?ARRAY\[(.*?)\]/s', $sql, $m);
        if (isset($m[1])) {
            preg_match_all("/'([a-z-]+)'/", $m[1], $values);
            $allowed = $values[1];
        }
    }

    expect($allowed)->not->toBeEmpty('users_sector_source_check not found in any migration');

    $ranked = array_keys((new ReflectionClass(SectorProvenance::class))->getConstant('RANKS'));

    sort($allowed);
    sort($ranked);

    expect($ranked)->toBe($allowed);
});
