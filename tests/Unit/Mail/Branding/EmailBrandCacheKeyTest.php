<?php

use App\Services\Cache\CacheKeyGenerator;

it('namespaces the email brand key by site id', function () {
    expect(CacheKeyGenerator::emailBrand('abc-123'))->toBe('site:abc-123:email_brand');
});
