<?php

// The sector precedence ladder. Every case here encodes a rule that was got
// backwards at least once during design — see the spec's revision notes.

use App\Models\Core\User\User;
use App\Services\Profile\SectorProvenance;
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
