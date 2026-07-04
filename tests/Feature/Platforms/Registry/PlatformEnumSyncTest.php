<?php

use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;

it('keeps every Platform enum case pointed at a real PlatformRegistry key', function () {
    $registry = app(PlatformRegistry::class);

    foreach (Platform::cases() as $case) {
        expect($registry->has($case->value))->toBeTrue(
            "Platform::{$case->name} ('{$case->value}') has no matching PlatformRegistry descriptor — the enum has drifted from the registry."
        );
    }
});
