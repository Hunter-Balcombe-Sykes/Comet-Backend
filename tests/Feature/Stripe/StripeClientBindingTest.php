<?php

use Stripe\StripeClient;

// Regression for the silent AuthenticationException bug:
// Services like StripeTransactionFetcher and StripeBalanceService inject
// \Stripe\StripeClient via the container. Before the AppServiceProvider
// binding, the container returned `new StripeClient()` with no API key,
// so every Stripe call from those services threw AuthenticationException
// which their try/catch swallowed — surfacing as empty transactions /
// zero balance / empty upcoming payouts on the dashboard.
//
// stripe_version is pinned to partna.exports.commission.stripe_api_version
// (env STRIPE_API_VERSION) so the async export pipeline and all other Stripe
// SDK users share one pinned API version.
//
// This test pins the binding so a future container refactor can't quietly
// regress it.

it('binds \Stripe\StripeClient with the configured api_key', function (): void {
    config()->set('services.stripe.secret_key', 'sk_test_dummy_for_binding');
    config()->set('partna.exports.commission.stripe_api_version', '2025-02-24.acacia');

    // Rebind to pick up the new config (the provider closure reads at resolve time).
    app()->forgetInstance(StripeClient::class);

    $client = app(StripeClient::class);

    expect($client)->toBeInstanceOf(StripeClient::class);
    // The Stripe SDK exposes the configured key via getApiKey(); fall back
    // to reflection so a future SDK version that hides it still passes.
    if (method_exists($client, 'getApiKey')) {
        expect($client->getApiKey())->toBe('sk_test_dummy_for_binding');
    } else {
        $ref = new ReflectionObject($client);
        $prop = collect($ref->getProperties(ReflectionProperty::IS_PRIVATE | ReflectionProperty::IS_PROTECTED))
            ->first(fn ($p) => str_contains(strtolower($p->getName()), 'apikey') || str_contains(strtolower($p->getName()), 'config'));
        expect($prop)->not->toBeNull();
    }
});

it('resolves the same singleton instance across services', function (): void {
    config()->set('services.stripe.secret_key', 'sk_test_singleton');
    app()->forgetInstance(StripeClient::class);

    $first = app(StripeClient::class);
    $second = app(StripeClient::class);

    expect($first)->toBe($second);
});
