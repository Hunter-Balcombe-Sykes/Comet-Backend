<?php

/**
 * Unit tests for HoursRhythmFactor — the band-15 refiner that reads a
 * business's weekly operating hours and nudges energy (fast/bold vs slow).
 *
 * IdentityEvidence::businessHours() queries the Workplace model by site id
 * (site.workplaces), so a pure unit test with an unsaved in-memory Site can
 * only drive the top-level abstention path — no workplace row exists for the
 * unsaved site id, so the query returns null and detect() abstains. We use
 * tests/Pest.php's setupWorkplacesTable() helper only to give the SQLite
 * connection the `site.workplaces` table shape to query against (no row is
 * ever inserted or read) — it's what every other Unit test in this suite
 * does to satisfy an Eloquent model's underlying table lookup.
 *
 * The rhythm CLASSIFICATION logic (the interesting part) lives in the
 * private rhythmOf() method, so it's exercised directly via reflection.
 */

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Design\Presets\Factors\HoursRhythmFactor;
use App\Services\Design\Presets\IdentityEvidence;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** IdentityEvidence over an empty connections collection (no workplace row exists either). */
function hoursEvidence(): IdentityEvidence
{
    setupWorkplacesTable();

    return new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        new Collection,
        new Collection,
    );
}

/**
 * Invoke HoursRhythmFactor's private rhythmOf(array $hours): ?string via
 * reflection — the classification logic detect() can't reach without a
 * saved Workplace row.
 */
function rhythmOf(array $hours): ?string
{
    $method = new ReflectionMethod(HoursRhythmFactor::class, 'rhythmOf');

    return $method->invoke(new HoursRhythmFactor, $hours);
}

// ── detect() abstention (no workplace row) ──────────────────────────────────

it('abstains when no parseable hours exist (no workplace)', function () {
    setupWorkplacesTable();

    // The connections collection carries an unrelated connection just to prove
    // detect() truly falls through to the businessHours() DB lookup rather
    // than short-circuiting on empty evidence.
    $connections = new Collection([
        new IntegrationConnection([
            'user_id' => 'u1', 'platform' => 'instagram', 'payload' => ['fullName' => 'Test'],
        ]),
    ]);
    $evidence = new IdentityEvidence(
        (new User)->forceFill(['id' => 'u1']),
        (new Site)->forceFill(['id' => 's1']),
        $connections,
        new Collection,
    );

    expect((new HoursRhythmFactor)->detect($evidence))->toBe([]);
    expect((new HoursRhythmFactor)->detect(hoursEvidence()))->toBe([]);
});

// ── rhythmOf() classification (via reflection) ──────────────────────────────

it('classifies late weekend hours as energetic', function () {
    expect(rhythmOf([
        'fri' => [['open' => '1700', 'close' => '0200']],
        'sat' => [['open' => '1700', 'close' => '0200']],
    ]))->toBe('energetic');
});

it('classifies two late weekdays as energetic', function () {
    expect(rhythmOf([
        'thu' => [['open' => '1000', 'close' => '2100']],
        'fri' => [['open' => '1000', 'close' => '2200']],
    ]))->toBe('energetic');
});

it('classifies strictly weekday daytime hours as calm', function () {
    expect(rhythmOf([
        'mon' => [['open' => '0900', 'close' => '1700']],
        'tue' => [['open' => '0900', 'close' => '1700']],
        'wed' => [['open' => '0900', 'close' => '1700']],
    ]))->toBe('calm');
});

it('is ambiguous for mixed weekday daytime plus weekend daytime', function () {
    expect(rhythmOf([
        'mon' => [['open' => '0900', 'close' => '1700']],
        'sat' => [['open' => '1000', 'close' => '1400']],
    ]))->toBeNull();
});

it('is ambiguous for a single late day alone', function () {
    expect(rhythmOf([
        'fri' => [['open' => '1700', 'close' => '2300']],
    ]))->toBeNull();
});

// ── RHYTHM_TARGETS mapping ───────────────────────────────────────────────────

it('maps the energetic and calm rhythms to their design-kit target overlays', function () {
    $targets = (new ReflectionClassConstant(HoursRhythmFactor::class, 'RHYTHM_TARGETS'))->getValue();

    expect($targets['energetic'])->toBe(['motion_pace' => 'fast', 'weight_regular' => '500'])
        ->and($targets['calm'])->toBe(['motion_pace' => 'slow']);
});

// ── Declared band/mode/key ───────────────────────────────────────────────────

it('is an auto factor in band 15', function () {
    $factor = new HoursRhythmFactor;
    expect($factor->priority())->toBe(15)
        ->and($factor->mode()->value)->toBe('auto')
        ->and($factor->key())->toBe('hours-rhythm:energy');
});
