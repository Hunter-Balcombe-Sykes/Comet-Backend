<?php

use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Direct form-request validation harness — no DB, no HTTP stack.
 * Same pattern as Newsletter + Countdown validation tests.
 */
function validateContactUpsert(array $payload): array
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

it('accepts a contact block with no settings (draft with no config yet)', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a contact block with full settings', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'headline' => 'Get in touch',
            'description' => 'Fill out the form and I will get back to you.',
            'notification_email' => 'hello@mybrand.com',
            'cta_label' => 'Send message',
            'subject_options' => ['Wholesale', 'Stockist'],
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['notification_email'])->toBe('hello@mybrand.com');
});

it('rejects invalid notification_email', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => ['notification_email' => 'not-an-email'],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.notification_email');
});

it('caps subject_options at 10 items', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => array_map(fn ($i) => "Option {$i}", range(1, 11)),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.subject_options');
});

it('rejects a subject option over 60 chars', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => [str_repeat('x', 61)],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.subject_options.0');
});

it('rejects duplicate subject options', function () {
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'subject_options' => ['Press', 'Press'],
        ],
    ]);

    expect($result['ok'])->toBeFalse();
});

it('strips HTML tags from headline and description (defense-in-depth)', function () {
    // strip_tags removes tags but preserves inner text — same behaviour as the
    // newsletter copy fields. Frontend is the primary XSS defence via
    // auto-escaping on render; this is belt-and-braces.
    $result = validateContactUpsert([
        'block_type' => 'contact',
        'settings' => [
            'headline' => 'Get <b>in</b> touch',
            'description' => '<em>Fill</em> out the form.',
            'notification_email' => 'a@b.com',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['headline'])->toBe('Get in touch');
    expect($result['data']['settings']['description'])->toBe('Fill out the form.');
});
