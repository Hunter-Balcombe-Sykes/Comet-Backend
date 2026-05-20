<?php

use App\Jobs\Cloudflare\RetireSubdomainFromKvJob;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('has the correct retry policy and queue configuration', function () {
    $job = new RetireSubdomainFromKvJob('myhandle');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(30)
        ->and($job->queue)->toBe('integrations');
});

it('does not log in the catch block — only failed() logs on terminal failure', function () {
    $kv = Mockery::mock(\App\Services\Cloudflare\CloudflareKvService::class);
    $kv->shouldReceive('delete')->andThrow(new \RuntimeException('CF timeout'));

    Log::spy();

    expect(fn () => (new RetireSubdomainFromKvJob('myhandle'))->handle($kv))
        ->toThrow(\RuntimeException::class, 'CF timeout');

    // The catch-block log was removed; no warning emitted during handle().
    Log::shouldNotHaveReceived('warning');
});

it('failed() logs and reports after retries are exhausted', function () {
    $handler = $this->spy(\Illuminate\Contracts\Debug\ExceptionHandler::class);
    Log::spy();

    $e = new \RuntimeException('CF timeout');
    (new RetireSubdomainFromKvJob('myhandle'))->failed($e);

    $handler->shouldHaveReceived('report')->once()->withArgs(fn ($r) => $r === $e);
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('cloudflare.retire_subdomain_from_kv.failed', \Mockery::on(
            fn ($ctx) => $ctx['handle'] === 'myhandle'
        ));
});
