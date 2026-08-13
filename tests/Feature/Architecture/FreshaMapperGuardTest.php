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
    expect($callers)->toEqualCanonicalizing([
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
