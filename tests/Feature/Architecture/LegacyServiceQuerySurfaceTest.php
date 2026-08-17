<?php

use Symfony\Component\Finder\Finder;

// Services cutover: Service and ServiceCategory are in-memory DTOs — their
// tables are dropped. Any query through them is a guaranteed 42P01 in
// production shape that SQLite tests cannot catch. This guard turns the
// grep the cutover ran by hand into CI.
it('no code queries the Service or ServiceCategory models', function () {
    $offenders = [];
    $patterns = [
        'Service::query(', 'Service::where', 'Service::find', 'Service::withTrashed',
        'ServiceCategory::query(', 'ServiceCategory::where', 'ServiceCategory::find', 'ServiceCategory::withTrashed',
    ];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $contents = $file->getContents();
        foreach ($patterns as $pattern) {
            if (str_contains($contents, $pattern)) {
                $offenders[] = $file->getRelativePathname().' contains '.$pattern;
            }
        }
    }

    expect($offenders)->toBe([]);
});
