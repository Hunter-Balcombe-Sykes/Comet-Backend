<?php

/**
 * Segment filter validation — the rules merged from SegmentCriteria, with
 * particular attention to max-only shapes, which a plain `gte` would reject.
 */

use App\Http\Requests\Api\Staff\Segments\StoreSegmentRequest;
use App\Services\Segments\Criteria\SegmentCriteria;
use App\Services\Segments\Criteria\SegmentCriterion;
use Illuminate\Support\Facades\Validator;

function ovaValidate(array $filters): Illuminate\Validation\Validator
{
    return Validator::make(
        ['name' => 'Test segment', 'filters' => $filters],
        (new StoreSegmentRequest)->rules()
    );
}

it('accepts a max-only bound on every ranged criterion', function () {
    expect(ovaValidate(['tenure_days_max' => 90])->passes())->toBeTrue()
        ->and(ovaValidate(['ig_followers' => ['max' => 5000]])->passes())->toBeTrue()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'max' => 10]])->passes())->toBeTrue();
});

it('rejects a max below an explicit min', function () {
    expect(ovaValidate(['tenure_days_min' => 90, 'tenure_days_max' => 30])->passes())->toBeFalse()
        ->and(ovaValidate(['ig_followers' => ['min' => 5000, 'max' => 100]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30, 'min' => 100, 'max' => 10]])->passes())->toBeFalse();
});

it('enforces the ISO alpha-2 shape on country_code', function () {
    expect(ovaValidate(['country_code' => ['AU', 'NZ']])->passes())->toBeTrue()
        ->and(ovaValidate(['country_code' => ['aus']])->passes())->toBeFalse()
        ->and(ovaValidate(['country_code' => ['au']])->passes())->toBeFalse();
});

it('requires at least one bound on the object criteria', function () {
    expect(ovaValidate(['ig_followers' => ['synced_within_days' => 30]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 30]])->passes())->toBeFalse();
});

it('requires metric and window_days whenever analytics is present', function () {
    expect(ovaValidate(['analytics' => ['min' => 10]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'not_a_metric', 'window_days' => 30, 'min' => 10]])->passes())->toBeFalse()
        ->and(ovaValidate(['analytics' => ['metric' => 'visits', 'window_days' => 400, 'min' => 10]])->passes())->toBeFalse();
});

it('leaves the existing created-range pair accepting an open lower bound', function () {
    expect(ovaValidate(['created_to' => '2026-07-01'])->passes())->toBeTrue()
        ->and(ovaValidate(['created_from' => '2026-08-01', 'created_to' => '2026-07-01'])->passes())->toBeFalse();
});

it('strips unknown sub-keys from object criteria', function () {
    $cleaned = StoreSegmentRequest::stripUnknownSubKeys([
        'ig_followers' => ['min' => 100, 'nonsense' => 'x'],
        'analytics' => ['metric' => 'visits', 'window_days' => 7, 'min' => 1, 'bogus' => true],
        'country_code' => ['AU'],
    ]);

    expect($cleaned['ig_followers'])->toBe(['min' => 100])
        ->and($cleaned['analytics'])->toBe(['metric' => 'visits', 'window_days' => 7, 'min' => 1])
        ->and($cleaned['country_code'])->toBe(['AU']);
});

it('exposes validation rules for every key the registry claims to own', function () {
    $rules = (new StoreSegmentRequest)->rules();

    foreach (SegmentCriteria::all() as $criterion) {
        foreach ($criterion->keys() as $key) {
            // toHaveKey's 2nd positional arg checks the VALUE at that key, not a
            // message — pass the message via the named 3rd param or it's read
            // as an expected value and fails on the type mismatch instead.
            expect($rules)->toHaveKey("filters.{$key}",
                message: sprintf('%s claims key "%s" but declares no rule for it.', $criterion::class, $key));
        }
    }
});

it('never silently strips a sub-key that has a validation rule declared for it', function () {
    // The strip-list in StoreSegmentRequest::objectSubKeys() is DERIVED from
    // the registry's own rules() output, not hand-maintained — so this test
    // reconstructs the same "filters.<parent>.<child>" dot-path scan the
    // production code uses and asserts every declared sub-key round-trips
    // through stripUnknownSubKeys() untouched. If a future refactor
    // reintroduces a separate hand-written list, this catches it drifting
    // from rules() the same way the sibling coverage tests above catch a
    // criterion missing from the registry.
    $rules = (new StoreSegmentRequest)->rules();
    $declaredSubKeys = [];

    foreach (array_keys($rules) as $ruleKey) {
        $segments = explode('.', $ruleKey);

        if (count($segments) === 3 && $segments[0] === 'filters' && $segments[2] !== '*') {
            $declaredSubKeys[$segments[1]][] = $segments[2];
        }
    }

    // Sanity check: this suite only means something if the object criteria
    // still exist and still declare named sub-keys.
    expect($declaredSubKeys)->toHaveKeys(['ig_followers', 'analytics']);

    foreach ($declaredSubKeys as $parent => $subKeys) {
        $probe = [$parent => array_fill_keys($subKeys, 'probe-value')];

        $stripped = StoreSegmentRequest::stripUnknownSubKeys($probe);

        expect($stripped[$parent])->toBe($probe[$parent],
            message: sprintf('A sub-key declared on "%s" was silently stripped before validation ran.', $parent));
    }
});

it('registers every criterion class that exists on disk', function () {
    $onDisk = collect(glob(app_path('Services/Segments/Criteria/*Criterion.php')))
        ->map(fn (string $path) => 'App\\Services\\Segments\\Criteria\\'.basename($path, '.php'))
        ->reject(fn (string $class) => $class === SegmentCriterion::class)
        ->sort()->values()->all();

    $registered = collect(SegmentCriteria::all())
        ->map(fn ($c) => $c::class)->sort()->values()->all();

    // A criterion added to the folder but forgotten in the registry is silently
    // inert — every segment using its key would match everyone.
    expect($registered)->toBe($onDisk);
});
