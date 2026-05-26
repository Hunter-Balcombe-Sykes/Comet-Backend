<?php

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Mailable Category Coverage Sweep
|--------------------------------------------------------------------------
| Every category registered in config('partna.notifications.mailables') must
| have at least one `publish(category: 'X', ...)` call site in app/. Dead
| wiring (Mailable + view + config entry but no emitter) is a footgun —
| someone will assume "we already send X emails" when nothing ever fires.
|
| Adding a new category? Wire an emit site OR add the category to
| MAILABLE_COVERAGE_EXEMPT with a justification.
|
| Mirror of tests/Feature/Security/PolicyCoverageTest.php.
*/

const MAILABLE_COVERAGE_EXEMPT = [
    // Issued by StaffNotificationController via direct Notification::create() with
    // category from a request variable, not via NotificationPublisher::publish() with
    // a string literal. The grep-based sweep can't detect dynamic-category emits,
    // and we don't want to force a publish() refactor for the staff-authored path
    // (it has different semantics — no dedupe_key, supports global broadcasts).
    'policy_update',
    'incident',
    'feature_announcement',
    // ProfileTaskMail exists but its emit site was removed with the account-type
    // defaults machinery (3-account-type strip). Retained in config pending a
    // replacement emit site for the individual-only onboarding flow.
    'profile_tasks',
];

it('every configured mailable category has at least one publish() call site', function () {
    $categories = array_keys((array) config('partna.notifications.mailables', []));
    expect($categories)->not->toBeEmpty('no mailable categories registered — config regression');

    $appPath = base_path('app');
    $finder = (new Finder)->files()->in($appPath)->name('*.php');

    // Build the haystack once.
    $haystack = '';
    foreach ($finder as $file) {
        $haystack .= $file->getContents()."\n";
    }

    $missing = [];
    foreach ($categories as $category) {
        if (in_array($category, MAILABLE_COVERAGE_EXEMPT, true)) {
            continue;
        }

        // Match both named-arg style `category: 'X'` and array style `'category' => 'X'`.
        $named = "category: '{$category}'";
        $array = "'category' => '{$category}'";

        if (! str_contains($haystack, $named) && ! str_contains($haystack, $array)) {
            $missing[] = $category;
        }
    }

    expect($missing)->toBe(
        [],
        'These mailable categories are registered in config but have NO publish() emit sites in app/: '
        .implode(', ', $missing)
        .'. Either wire an emit site, remove the category from config/partna.php, or add it to MAILABLE_COVERAGE_EXEMPT.',
    );
});
