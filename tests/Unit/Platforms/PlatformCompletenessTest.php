<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

function completenessConn(array $payload): IntegrationConnection
{
    return new IntegrationConnection(['platform' => 'fresha', 'payload' => $payload]);
}

it('defaults to complete for a descriptor that never calls complete()', function () {
    $descriptor = PlatformDescriptor::make('square')->label('Square');

    expect($descriptor->isComplete(completenessConn([])))->toBeTrue();
});

it('honours a declared predicate', function () {
    $descriptor = PlatformDescriptor::make('fake')
        ->complete(fn (IntegrationConnection $c): bool => ($c->payload['ready'] ?? false) === true);

    expect($descriptor->isComplete(completenessConn(['ready' => true])))->toBeTrue()
        ->and($descriptor->isComplete(completenessConn(['ready' => false])))->toBeFalse()
        ->and($descriptor->isComplete(completenessConn([])))->toBeFalse();
});

it('treats a fresha row with no selection as incomplete and one with a selection as complete', function () {
    $fresha = app(PlatformRegistry::class)->get('fresha');

    expect($fresha)->not->toBeNull()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x', 'selection' => null])))->toBeFalse()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x'])))->toBeFalse()
        ->and($fresha->isComplete(completenessConn(['url' => 'https://www.fresha.com/a/x', 'selection' => []])))->toBeTrue()
        ->and($fresha->isComplete(completenessConn([
            'url' => 'https://www.fresha.com/a/x',
            'selection' => ['mode' => 'employee', 'services' => []],
        ])))->toBeTrue();
});

it('leaves square complete regardless of payload — a url IS the whole integration', function () {
    $square = app(PlatformRegistry::class)->get('square');

    expect($square->isComplete(completenessConn([])))->toBeTrue()
        ->and($square->isComplete(completenessConn(['url' => 'https://squareup.com/appointments/book/x'])))->toBeTrue();
});
