<?php

use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validation-layer security regression tests for link block create/update.
 * Exercises the StoreLinkBlockRequest / UpdateLinkBlockRequest pipeline directly
 * (rules + prepareForValidation + withValidator) without going through the full
 * HTTP stack or touching the Block model. Storage-side behaviour is verified
 * separately by SocialLinkNormalizerTest + manual smoke tests.
 *
 * Each test corresponds to a row in the security checklist of
 * docs/social-links.md (or the inline plan in chat).
 */

// --- Helpers ---

function validateStoreRequest(array $payload): array
{
    $request = Request::create('/api/test', 'POST', $payload);
    $formRequest = StoreLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

function validateUpdateRequest(array $payload, ?string $blockId = null): array
{
    $request = Request::create('/api/test', 'PATCH', $payload);
    $request->setRouteResolver(function () use ($blockId) {
        $route = new Route(['PATCH'], '/api/test', []);
        $route->parameters = ['linkBlock' => $blockId ?? (string) Str::uuid()];

        return $route;
    });

    $formRequest = UpdateLinkBlockRequest::createFrom($request);
    $formRequest->setContainer(app())->setRedirector(app('redirect'));

    try {
        $formRequest->validateResolved();

        return ['ok' => true, 'data' => $formRequest->validated()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'errors' => $e->errors()];
    }
}

// --- Social mode: happy path ---

it('accepts a valid social link with a handle', function () {
    $result = validateStoreRequest([
        'platform' => 'instagram',
        'handle' => 'joshhunter',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['platform'])->toBe('instagram');
    expect($result['data']['handle'])->toBe('joshhunter');
});

it('accepts a valid social link with a URL', function () {
    $result = validateStoreRequest([
        'platform' => 'instagram',
        'url' => 'https://instagram.com/joshhunter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('accepts all 8 platforms in the registry', function () {
    foreach (['instagram', 'facebook', 'linkedin', 'youtube', 'tiktok', 'x', 'spotify', 'soundcloud'] as $platform) {
        $result = validateStoreRequest([
            'platform' => $platform,
            'handle' => 'validhandle',
        ]);
        expect($result['ok'])->toBeTrue("Expected {$platform} to validate");
    }
});

// --- Social mode: missing inputs ---

it('rejects social mode with neither handle nor url', function () {
    $result = validateStoreRequest([
        'platform' => 'instagram',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('handle');
});

it('rejects an unknown platform key', function () {
    $result = validateStoreRequest([
        'platform' => 'myspace',
        'handle' => 'joshhunter',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('platform');
});

// --- Custom mode: happy path + legacy contract preservation ---

it('accepts a valid custom link with title and https url', function () {
    $result = validateStoreRequest([
        'title' => 'Book Now',
        'url' => 'https://booking.example.com/joshhunter',
        'icon_key' => 'calendar',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['title'])->toBe('Book Now');
});

it('accepts a custom link with http (not just https)', function () {
    $result = validateStoreRequest([
        'title' => 'Local Site',
        'url' => 'http://localhost:8080/intranet',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
});

// --- Custom mode: missing required fields ---

it('rejects custom mode with no title', function () {
    $result = validateStoreRequest([
        'url' => 'https://example.com',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('title');
});

it('rejects custom mode with no url', function () {
    $result = validateStoreRequest([
        'title' => 'Book Now',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

// --- Security: scheme allowlist ---

it('rejects a custom link with javascript: scheme', function () {
    $result = validateStoreRequest([
        'title' => 'Click me',
        'url' => 'javascript:alert(1)',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

it('rejects a custom link with data: scheme', function () {
    $result = validateStoreRequest([
        'title' => 'Click me',
        'url' => 'data:text/html,<script>alert(1)</script>',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

it('rejects a custom link with file: scheme', function () {
    $result = validateStoreRequest([
        'title' => 'Click me',
        'url' => 'file:///etc/passwd',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

it('rejects a custom link with ftp: scheme', function () {
    $result = validateStoreRequest([
        'title' => 'Download',
        'url' => 'ftp://example.com/file.zip',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

// --- Security: title sanitization (XSS defense-in-depth) ---

it('strips HTML tags from the title (executable content removed)', function () {
    // cleanString() removes the entire <script> block including its content
    // (not just the tags), so injected JS payloads cannot survive even as
    // plain text. Plain content after the block is preserved.
    $result = validateStoreRequest([
        'title' => '<script>alert(1)</script>Book',
        'url' => 'https://example.com',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['title'])->toBe('Book');
    expect($result['data']['title'])->not->toContain('alert');
    expect($result['data']['title'])->not->toContain('<');
    expect($result['data']['title'])->not->toContain('>');
});

it('strips control characters from the title', function () {
    $result = validateStoreRequest([
        'title' => "Book\x00\x01Now",
        'url' => 'https://example.com',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['title'])->toBe('BookNow');
});

it('strips img tag attempts from the title', function () {
    $result = validateStoreRequest([
        'title' => '<img src=x onerror=alert(1)>Hello',
        'url' => 'https://example.com',
        'category' => 'other',
    ]);

    expect($result['ok'])->toBeTrue();
    expect($result['data']['title'])->toBe('Hello');
});

// --- Update request: partial updates ---

it('allows update with just is_active toggle', function () {
    $result = validateUpdateRequest([
        'is_active' => false,
    ]);

    expect($result['ok'])->toBeTrue();
});

it('allows update switching to social mode', function () {
    $result = validateUpdateRequest([
        'platform' => 'tiktok',
        'handle' => 'joshhunter',
    ]);

    expect($result['ok'])->toBeTrue();
});

it('rejects update with javascript scheme on URL change', function () {
    $result = validateUpdateRequest([
        'url' => 'javascript:alert(1)',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('url');
});

it('rejects update with social mode but no handle or url', function () {
    $result = validateUpdateRequest([
        'platform' => 'instagram',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('handle');
});

// --- Settings allowlist (existing behaviour preserved) ---

it('still rejects unknown settings keys', function () {
    $result = validateStoreRequest([
        'title' => 'Test',
        'url' => 'https://example.com',
        'settings' => [
            'highlight' => true,
            'malicious_key' => 'value',
        ],
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['errors'])->toHaveKey('settings');
});

it('keeps handle in the settings allowlist; platform is a column now', function () {
    // Phase 2: platform is a promoted column on site.blocks and is no longer accepted
    // as a settings sub-key. handle stays in settings JSONB and remains in the allowlist.
    $allowed = config('partna.link_block_settings_keys');
    expect($allowed)->toContain('handle');
    expect($allowed)->not->toContain('platform');
});

// --- Cross-check: normalizer integration is callable from the request layer ---

it('the normalizer service is resolvable from the container', function () {
    $normalizer = app(SocialLinkNormalizer::class);

    expect($normalizer)->toBeInstanceOf(SocialLinkNormalizer::class);
    expect($normalizer->isKnownPlatform('instagram'))->toBeTrue();
});
