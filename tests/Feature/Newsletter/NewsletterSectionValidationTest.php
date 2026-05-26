<?php

use App\Http\Requests\Api\User\Site\UpsertSectionBlockRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings-shape validation for the newsletter section. Uses the direct
 * form-request pattern (no DB, no HTTP stack) — same pattern as
 * tests/Feature/Site/LinkBlockSocialValidationTest.php.
 */
function validateNewsletterUpsert(array $payload): array
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

it('accepts a newsletter block with no settings (uses defaults)', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts a newsletter block with full settings', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'input_placeholder' => 'Enter your email',
            'list_key' => 'marketing',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['input_placeholder'])->toBe('Enter your email');
});

it('rejects an overly long input_placeholder', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'input_placeholder' => str_repeat('a', 121),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.input_placeholder');
});

it('rejects an overly long list_key', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'list_key' => str_repeat('a', 41),
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.list_key');
});

it('strips HTML tags from newsletter input_placeholder (defense-in-depth)', function () {
    // strip_tags removes tags but preserves inner text — same behavior as the
    // existing settings.text sanitization. The frontend is the primary XSS
    // defense via auto-escaping on render; this is just belt-and-braces.
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'input_placeholder' => 'Enter <b>your</b> email',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['settings']['input_placeholder'])->toBe('Enter your email');
});

it('rejects a list_key with invalid characters', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'list_key' => 'has spaces!',
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings.list_key');
});

it('accepts a valid slug-shaped list_key', function () {
    $result = validateNewsletterUpsert([
        'block_type' => 'newsletter',
        'settings' => [
            'list_key' => 'vip-announcements',
        ],
    ]);

    expect($result['ok'])->toBeTrue();
});
