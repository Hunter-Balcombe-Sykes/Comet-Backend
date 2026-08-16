<?php

// Slice 3b Task 13, spec §1.5: the scraped-price trap. ManualServiceWriter::
// projectionFor() maps price_cents === 0 -> qualifier 'free' — correct for
// hand-entered data, and a lie on scraped data: all 61 legacy Fresha rows
// carry price_cents = 0 purely because the stored blob's priceValue is null.
// Routing a Fresha row through that mapper would mark a whole salon's menu
// free. This pins the current set of files that even mention
// 'projectionFor(' so a new caller fails the build and forces a deliberate
// look, rather than silently landing scraped rows through the owner-authored
// mapper.
//
// glob(app_path('**/*.php'), GLOB_BRACE) does NOT recurse in PHP — '**' has
// no special recursive meaning to glob(), it behaves exactly like '*'. This
// walks app/ with a RecursiveIteratorIterator instead.
//
// Mutation-checked 2026-08-13: added a scratch file under app/ containing
// the literal string 'projectionFor(' and confirmed this test went RED
// (toEqualCanonicalizing failed on the unexpected 5th file); deleted the
// scratch file and confirmed GREEN again. See task-13-report.md.

it('routes no Fresha service through the owner-authored price mapper', function () {
    $callers = collect(projectionForMentionFiles())
        ->map(fn (string $path) => basename($path))
        ->values()
        ->all();

    // Derived from the real tree (2026-08-13), not the plan's placeholder
    // list: ManualServiceWriter.php defines projectionFor() (the literal
    // matches its own method signature); ServiceBackfiller.php and
    // UserServiceController.php are the two callers the 3a spec named;
    // StaffServiceManagementController.php is a 3b-era caller for staff-
    // created (still owner-authored/manual) services, added since the task
    // brief was written.
    // ManualMenuWriter.php added by slice 7, after the deliberate look this
    // guard exists to force. It defines its OWN projectionFor(), delegating to
    // MenuProjectionMapper — it does not reach the owner-authored service
    // mapper, so the salon-menu-marked-free trap cannot be reached from it.
    //
    // The analogous menu question was asked rather than assumed: menus ARE
    // scraped, and MenuProjectionMapper::offer() does map 0.0 -> 'free'. It is
    // safe because offers() SKIPS a null amount (`if ($amount === null)
    // continue`) instead of coercing it to zero, so an unpriced scraped dish
    // mints no offer at all. The Fresha trap was the opposite shape: legacy
    // rows stored a real 0 to mean "unknown". A menu writer that ever starts
    // defaulting a null price to 0 reintroduces it.
    //
    // MenuScanApplier.php added by slice 7 Task 8, same deliberate look: it
    // calls ManualMenuWriter::projectionFor(), i.e. MenuProjectionMapper, never
    // the service mapper. Its own price handling keeps the Fresha shape out —
    // an absent scan price stays null (`$item['price'] ?? null`) rather than
    // becoming 0, so it mints no offer at all; a 0 there is an OCR-read "free",
    // which is the hand-entered case the 'free' qualifier is FOR.
    expect($callers)->toEqualCanonicalizing([
        'ManualMenuWriter.php',
        'MenuScanApplier.php',
        'ManualServiceWriter.php',
        'ServiceBackfiller.php',
        'UserServiceController.php',
        'StaffServiceManagementController.php',
    ]);
});

/**
 * Every .php file under app/ whose contents mention the literal string
 * 'projectionFor(' — a call site OR the method's own definition.
 *
 * @return list<string> absolute file paths
 */
function projectionForMentionFiles(): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    $matches = [];
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents !== false && str_contains($contents, 'projectionFor(')) {
            $matches[] = $file->getPathname();
        }
    }

    return $matches;
}
