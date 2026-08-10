<?php

use App\Models\Core\User\User;
use App\Services\Profile\PersonNameParser;

beforeEach(function () {
    setupUsersTable();
});

it('parses a surname out of the instagram full name', function () {
    $parsed = PersonNameParser::parse('SIMON DOYLE | Barber & Educator');

    $user = User::factory()->create([
        'display_name' => $parsed['displayName'],
        'first_name' => $parsed['firstName'],
        'last_name' => $parsed['lastName'],
    ]);

    expect($user->fresh()->last_name)->toBe('DOYLE');
});

it('writes the name onto the user before the connection seeder runs', function () {
    // Guards the ordering fix: InstagramSourceGenerator must fold the name
    // BEFORE seed(), because seed() is what routes links and dispatches the
    // Fresha auto-connect, and FreshaStaffMatcher reads first_name/last_name.
    $source = file_get_contents(base_path('app/Services/PreAccount/Generators/InstagramSourceGenerator.php'));

    $foldPosition = strpos($source, 'PersonNameParser::parse');
    $seedPosition = strpos($source, '$this->seeder->seed(');

    expect($foldPosition)->not->toBeFalse()
        ->and($seedPosition)->not->toBeFalse()
        ->and($foldPosition)->toBeLessThan($seedPosition);
});

it('still reads instagram snake_case full_name after the fold moves', function () {
    // Regression guard for the 2026-08-10 fix: actors drift between fullName and
    // Instagram's raw GraphQL full_name, and reading only camelCase left
    // display_name as the placeholder handle. Moving the block must not undo it.
    $source = file_get_contents(base_path('app/Services/PreAccount/Generators/InstagramSourceGenerator.php'));

    expect($source)->toContain("data_get(\$profile, 'full_name')");
});
