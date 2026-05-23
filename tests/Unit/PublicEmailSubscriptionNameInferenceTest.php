<?php

use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;

function infer_name_from_email(string $email): ?string
{
    $controller = new PublicEmailSubscriptionController;
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('inferNameFromEmail');

    $value = $method->invoke($controller, $email);

    return is_string($value) ? $value : null;
}

it('infers a single-part name from email local part', function () {
    expect(infer_name_from_email('john@example.com'))->toBe('John');
    expect(infer_name_from_email('taylah@example.com'))->toBe('Taylah');
});

it('infers a multi-part name from separated email local part', function () {
    expect(infer_name_from_email('john.doe@example.com'))->toBe('John Doe');
    expect(infer_name_from_email('jane_smith@example.com'))->toBe('Jane Smith');
});

it('does not infer known non-name mailbox tokens', function () {
    expect(infer_name_from_email('support@example.com'))->toBeNull();
    expect(infer_name_from_email('marketing.team@example.com'))->toBeNull();
});
