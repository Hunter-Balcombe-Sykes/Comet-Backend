<?php

use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Direct form-request validation harness — no DB, no HTTP stack.
 * Matches the pattern used by tests/Feature/Newsletter/NewsletterSectionValidationTest.php.
 */
function validateCountdownUpsert(array $payload): array
{
    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = UpsertSectionBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

it('accepts a countdown with no settings (draft with no timeline yet)', function () {
    // Affiliate can create the block in draft mode before they've decided
    // on a drop time. The publish gate (enforced elsewhere) blocks going
    // Live without a valid timeline.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a countdown with valid drop and expiry times', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['timeline']['drop_time'])->toBe('2026-05-01T20:00:00Z');
});

it('rejects expiry_time equal to drop_time', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('rejects expiry_time before drop_time', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-03T20:00:00Z',
                'expiry_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('rejects drop_time that is not a valid date', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => 'not-a-date',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.drop_time');
});

it('requires expiry_time when drop_time is provided', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.expiry_time');
});

it('requires drop_time when expiry_time is provided', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'timeline' => [
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.timeline.drop_time');
});

it('accepts a full per-state countdown payload', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => 'The Drop',
            'timeline' => [
                'drop_time' => '2026-05-01T20:00:00Z',
                'expiry_time' => '2026-05-03T20:00:00Z',
            ],
            'states' => [
                'pre_drop' => [
                    'headline' => 'Coming Friday',
                    'subtitle' => 'A limited run of three new knits.',
                ],
                'live' => [
                    'headline' => "It's live",
                    'subtitle' => "Shop now before they're gone.",
                ],
                'expired' => [
                    'headline' => null,
                    'subtitle' => null,
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['title'])->toBe('The Drop');
    expect($result['data']['settings']['states']['live']['headline'])->toBe("It's live");
});

it('rejects a title longer than 80 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => str_repeat('a', 81),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.title');
});

it('rejects a state headline longer than 80 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => ['headline' => str_repeat('a', 81)],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.headline');
});

it('rejects a state subtitle longer than 200 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'pre_drop' => ['subtitle' => str_repeat('a', 201)],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.pre_drop.subtitle');
});

it('accepts a CTA with https URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop the drop', 'url' => 'https://stan.store/foo'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a CTA with a hash anchor URL (internal section link)', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop now', 'url' => '#shop?products=abc,def'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a CTA with an absolute path URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Go', 'url' => '/some/page'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});

it('rejects a javascript: URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a mailto: URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => 'mailto:foo@example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a protocol-relative URL', function () {
    // //example.com is protocol-relative and inherits the current scheme.
    // Reject it — if someone needs an external URL, they should be explicit.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Bad', 'url' => '//evil.example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a CTA label without a URL', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => 'Shop now'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.url');
});

it('rejects a CTA URL without a label', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['url' => 'https://example.com'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.label');
});

it('rejects a CTA label longer than 40 chars', function () {
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'states' => [
                'live' => [
                    'cta' => ['label' => str_repeat('a', 41), 'url' => 'https://x.test'],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.states.live.cta.label');
});

it('strips HTML tags from countdown string fields (defense-in-depth)', function () {
    // Matches the newsletter/bio sanitization pattern. Frontend auto-escape is
    // the primary XSS defense; this prevents stored tags from reaching a
    // future buggy renderer.
    $result = validateCountdownUpsert([
        'block_type' => 'countdown',
        'settings' => [
            'title' => 'The <b>Drop</b>',
            'states' => [
                'live' => [
                    'headline' => '<script>alert(1)</script>Live now',
                    'subtitle' => 'Shop <em>now</em>',
                    'cta' => [
                        'label' => '<img>Go',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['title'])->toBe('The Drop');
    expect($result['data']['settings']['states']['live']['headline'])->toBe('Live now');
    expect($result['data']['settings']['states']['live']['subtitle'])->toBe('Shop now');
    expect($result['data']['settings']['states']['live']['cta']['label'])->toBe('Go');
});
