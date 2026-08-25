<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// tests/Pest.php binds TestCase to Feature ONLY — without this the container
// is never booted and app()/now()/factories all fail.
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
    // Global Constraint: unconditional even though this file never calls
    // requestBuild and PreAccountBuild has no observer today — the point is
    // nobody has to re-derive that safety before touching this file next.
    Queue::fake();
});

// Local by design: cross-file Pest helpers break under --parallel.
// PreAccountBuildFactory creates NO user, but user_id is NOT NULL and is
// deliberately not fillable — it must be attached via associate().
function issuerBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);

    $build = PreAccountBuild::factory()->make($attrs);
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

it('stores only the hash, never the plaintext', function () {
    $build = issuerBuild();

    $plain = app(ClaimTokenIssuer::class)->issue($build);

    expect($build->fresh()->claim_token_hash)->toBe(hash('sha256', $plain))
        ->and($build->fresh()->claim_token_hash)->not->toBe($plain)
        ->and($build->fresh()->claim_token_issued_at)->not->toBeNull();
});

it('matches the token it minted', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeTrue();
});

it('rejects a wrong token', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain.'x'))->toBeFalse();
});

it('rejects a null presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), null))->toBeFalse();
});

it('rejects an empty presented token', function () {
    $build = issuerBuild();
    app(ClaimTokenIssuer::class)->issue($build);

    expect(app(ClaimTokenIssuer::class)->matches($build->fresh(), ''))->toBeFalse();
});

it('rejects any token on a build that has none', function () {
    $build = issuerBuild(['expires_at' => now()->addDays(30)]);

    expect(app(ClaimTokenIssuer::class)->matches($build, 'anything'))->toBeFalse();
});

it('refuses a valid token on an expired build', function () {
    $build = issuerBuild(['expires_at' => now()->subMinute()]);
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeFalse();
});

it('accepts a valid token on a never-expiring build', function () {
    $build = issuerBuild(['expires_at' => null]);
    $issuer = app(ClaimTokenIssuer::class);
    $plain = $issuer->issue($build);

    expect($issuer->matches($build->fresh(), $plain))->toBeTrue();
});

it('mints a different token every time', function () {
    $build = issuerBuild();
    $issuer = app(ClaimTokenIssuer::class);

    expect($issuer->issue($build))->not->toBe($issuer->issue($build));
});

it('burn() yields the attribute fragment that clears the hash', function () {
    expect(app(ClaimTokenIssuer::class)->burn())->toBe(['claim_token_hash' => null]);
});
