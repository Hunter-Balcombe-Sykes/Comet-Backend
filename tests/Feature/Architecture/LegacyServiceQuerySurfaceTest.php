<?php

use App\Console\Commands\PurgeSoftDeleted;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use Illuminate\Database\Eloquent\SoftDeletes;
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

// PGR-15: the slice 7 teardown left MenuItem/MenuCategory/MenuItemPlatform in
// the exact same table-less-DTO shape as Service/ServiceCategory above (they
// survive their dropped site.menu_items / site.menu_categories /
// site.menu_item_platforms tables ON PURPOSE — ManualMenuItems hydrates them
// unpersisted, exists=false, for the dashboard shape). Nothing previously
// pinned that in CI; this is the same guard, extended to the three menu DTOs.
it('no code queries the MenuItem, MenuCategory or MenuItemPlatform models', function () {
    $offenders = [];
    $patterns = [
        'MenuItem::query(', 'MenuItem::where', 'MenuItem::find', 'MenuItem::withTrashed',
        'MenuCategory::query(', 'MenuCategory::where', 'MenuCategory::find', 'MenuCategory::withTrashed',
        'MenuItemPlatform::query(', 'MenuItemPlatform::where', 'MenuItemPlatform::find', 'MenuItemPlatform::withTrashed',
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

// PGR-15, sibling of the case above — but NOT a copy of its positive control.
// Unlike Service/ServiceCategory, MenuItem/MenuCategory/MenuItemPlatform
// never used SoftDeletes to begin with (verified: none of the three model
// files declares `use SoftDeletes`), so they were never eligible for
// PURGE_HANDLED and are correctly ABSENT from PURGE_EXEMPT too — putting them
// there would misrepresent them as having exemption-worthy soft-delete state
// they don't have. The positive control here is the fact that makes the
// absence correct rather than accidental: SoftDeletePurgeCoverageTest's
// class_uses_recursive() sweep over every app/Models file is what would go
// red if any of the three ever gained the trait without also being wired
// into PURGE_HANDLED/PURGE_EXEMPT/PURGE_OTHER_PATH — so this assertion is not
// standing in for that guard, just documenting why this file's negated
// assertion isn't vacuous the way an always-true "not in an empty array"
// check would be.
it('never lets the table-less MenuItem/MenuCategory/MenuItemPlatform DTOs into a purge loop', function () {
    expect(PurgeSoftDeleted::PURGE_HANDLED)
        ->not->toContain(MenuItem::class)
        ->not->toContain(MenuCategory::class)
        ->not->toContain(MenuItemPlatform::class);

    // Positive control: prove the absence from PURGE_HANDLED/PURGE_EXEMPT is
    // legitimate (no SoftDeletes trait to purge) rather than the three models
    // having quietly dropped out of every list this sweep reads.
    expect(in_array(SoftDeletes::class, class_uses_recursive(MenuItem::class), true))->toBeFalse();
    expect(in_array(SoftDeletes::class, class_uses_recursive(MenuCategory::class), true))->toBeFalse();
    expect(in_array(SoftDeletes::class, class_uses_recursive(MenuItemPlatform::class), true))->toBeFalse();
});
