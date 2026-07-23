<?php

// RV-6 architecture guards for the Google Places spend ceiling. Two cheap
// checks that stop the gate rotting silently, in the spirit of
// CacheStoreFailoverGuardTest.php / SubdomainKvWritersTest.php:
//
// 1. Sole-origin guard: GoogleBusinessService is the ONLY class that can issue
//    a keyed Places request. If a second caller of 'places.googleapis.com' or
//    'services.google_maps.server_api_key' ever appears, a billed call has
//    escaped the PlacesBudget gate entirely.
// 2. Signature guard: fetchPlaceDetails()'s $userId parameter has NO default,
//    so a future caller cannot silently omit attribution and land an
//    unaccounted claim.

use App\Services\Platforms\GoogleBusinessService;

it('no app/ file other than GoogleBusinessService (and EnvCheckService for the config key) references the billed Places surface', function () {
    $allowedPlacesGoogleapis = [
        'app/Services/Platforms/GoogleBusinessService.php',
    ];
    $allowedServerKeyConfig = [
        'app/Services/Platforms/GoogleBusinessService.php',
        'app/Services/Diagnostics/EnvCheckService.php', // only checks the var is SET, never reads/uses it
    ];

    $violations = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, 'places.googleapis.com') && ! in_array($relative, $allowedPlacesGoogleapis, true)) {
            $violations[] = "{$relative} references places.googleapis.com directly — a billed Places call outside GoogleBusinessService bypasses PlacesBudget entirely.";
        }

        if (str_contains($contents, 'services.google_maps.server_api_key') && ! in_array($relative, $allowedServerKeyConfig, true)) {
            $violations[] = "{$relative} reads the Places server key — only GoogleBusinessService may issue a keyed request.";
        }
    }

    expect($violations)->toBe([]);
});

it('fetchPlaceDetails() requires an explicit $userId with no default, so a new caller cannot omit attribution', function () {
    $method = new ReflectionMethod(GoogleBusinessService::class, 'fetchPlaceDetails');
    $params = $method->getParameters();

    expect($params[1]->getName())->toBe('userId');
    expect((string) $params[1]->getType())->toBe('string');
    expect($params[1]->isDefaultValueAvailable())->toBeFalse();
});
