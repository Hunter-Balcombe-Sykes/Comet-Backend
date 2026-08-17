<?php

use App\Console\Commands\PurgeSoftDeleted;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
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

// The string sweep above is blind to a DYNAMIC call — `$modelClass::onlyTrashed()`
// over a list of class names reads as no pattern at all. That is exactly how the
// services cutover left `partna:purge-soft-deletes` (scheduled daily 03:20)
// querying both dropped tables: the classes sat in PURGE_HANDLED, and the loop
// resolved them at runtime. Caught after the DROP had already landed on dev.
//
// So this case guards the OTHER shape: a DTO with no table must never appear in
// any list a query loop iterates. PURGE_EXEMPT is fine — it is inert, and its
// justification string is the record.
it('never lets the table-less Service DTOs into a purge loop', function () {
    expect(PurgeSoftDeleted::PURGE_HANDLED)
        ->not->toContain(Service::class)
        ->not->toContain(ServiceCategory::class);

    expect(PurgeSoftDeleted::PURGE_OTHER_PATH)
        ->not->toHaveKey(Service::class)
        ->not->toHaveKey(ServiceCategory::class);

    // Positive control: they ARE accounted for, so this cannot pass by the
    // models having quietly vanished from every list (which is what the
    // SoftDeletePurgeCoverageTest sweep would then fail on, one lane later).
    expect(PurgeSoftDeleted::PURGE_EXEMPT)
        ->toHaveKey(Service::class)
        ->toHaveKey(ServiceCategory::class);
});
