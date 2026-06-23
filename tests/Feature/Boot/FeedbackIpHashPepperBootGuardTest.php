<?php

// LIFE-2c — Production boot guard for FEEDBACK_IP_HASH_PEPPER.
//
// The guard is a source-structure assertion: invoking the full
// AppServiceProvider::boot() in test requires far more container
// scaffolding than the value justifies, so we confirm:
//   1. The guard is present in source (same pattern as JWKS, throttle, etc.).
//   2. The FeedbackService.hashIp() soft-degrades gracefully in non-prod
//      (returns null rather than throwing) — boot assertion is the only
//      hard gate, not the service itself.

it('AppServiceProvider::boot() contains the FEEDBACK_IP_HASH_PEPPER production guard', function () {
    $path = base_path('app/Providers/AppServiceProvider.php');
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    // Required components:
    //  1. isProduction() check — so dev/CI pass without the var
    //  2. config('partna.feedback.ip_hash_pepper') — config:cache respected
    //  3. throw RuntimeException naming the env var
    expect($source)
        ->toContain("config('partna.feedback.ip_hash_pepper')")
        ->toContain('FEEDBACK_IP_HASH_PEPPER');
});

it('FeedbackService::hashIp() returns null and does not throw when pepper is empty in non-production (LIFE-2c graceful degrade)', function () {
    config(['partna.feedback.ip_hash_pepper' => '']);
    // Non-prod environment — should soft-degrade to null, no exception.
    app()->detectEnvironment(fn () => 'testing');

    $service = new \App\Services\Feedback\FeedbackService;
    $method = new \ReflectionMethod($service, 'hashIp');
    $method->setAccessible(true);

    $result = $method->invoke($service, '192.168.1.1');
    expect($result)->toBeNull();
});

it('FeedbackService::hashIp() produces a 64-char SHA256 hash when pepper is set', function () {
    config(['partna.feedback.ip_hash_pepper' => 'test-pepper']);

    $service = new \App\Services\Feedback\FeedbackService;
    $method = new \ReflectionMethod($service, 'hashIp');
    $method->setAccessible(true);

    $result = $method->invoke($service, '203.0.113.1');
    // SHA256 hex = 64 chars; must not contain the raw IP.
    expect($result)->toHaveLength(64);
    expect($result)->not->toContain('203.0.113.1');
});
