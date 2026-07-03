<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\ConditionalContext;
use Tests\TestCase;

// config() requires the Laravel container — opt into TestCase bootstrapping.
uses(TestCase::class)->in(__FILE__);

it('returns null when the kill-switch is off', function () {
    config()->set('partna.refresh.conditional.enabled', false);
    $conn = new IntegrationConnection(['refresh_etag' => '"e"']);
    expect(ConditionalContext::for($conn))->toBeNull();
});

it('builds If-None-Match / If-Modified-Since headers from the stored validators', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection([
        'refresh_etag' => '"abc"',
        'refresh_last_modified' => 'Wed, 21 Oct 2026 07:28:00 GMT',
    ]);

    expect(ConditionalContext::for($conn)->headers())->toBe([
        'If-None-Match' => '"abc"',
        'If-Modified-Since' => 'Wed, 21 Oct 2026 07:28:00 GMT',
    ]);
});

it('sends no conditional headers when the connection has no stored validators', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    expect(ConditionalContext::for(new IntegrationConnection)->headers())->toBe([]);
});

it('flags notModified on a 304 result', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $cond = ConditionalContext::for(new IntegrationConnection);

    expect($cond->handle(['status' => 304]))->toBeTrue()
        ->and($cond->notModified)->toBeTrue();
});

it('captures fresh validators on a 200 and applies them to the connection', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection(['refresh_etag' => '"old"']);
    $cond = ConditionalContext::for($conn);

    expect($cond->handle(['status' => 200, 'etag' => '"new"', 'lastModified' => 'D']))->toBeFalse()
        ->and($cond->notModified)->toBeFalse();

    $cond->applyTo($conn);
    expect($conn->refresh_etag)->toBe('"new"')
        ->and($conn->refresh_last_modified)->toBe('D');
});

it('clears validators on a 200 that carries none (self-correcting)', function () {
    config()->set('partna.refresh.conditional.enabled', true);
    $conn = new IntegrationConnection(['refresh_etag' => '"old"']);
    $cond = ConditionalContext::for($conn);

    $cond->handle(['status' => 200]); // no etag/lastModified keys
    $cond->applyTo($conn);

    expect($conn->refresh_etag)->toBeNull()
        ->and($conn->refresh_last_modified)->toBeNull();
});
