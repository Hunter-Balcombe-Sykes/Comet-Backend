<?php

use App\Services\User\ConfirmationPreferenceService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // TestCase::setUp redirects 'pgsql' to in-memory SQLite already.
    // Just attach the 'core' schema and create the table under it so the
    // model's $table = 'core.user_confirmation_preferences' resolves.
    attachTestSchemas();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.user_confirmation_preferences (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        action_key TEXT NOT NULL,
        skip_confirmation INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (user_id, action_key)
    )');
})->group('confirmation-preferences');

it('returns default false values when no rows exist', function () {
    $service = app(ConfirmationPreferenceService::class);
    $userId = '00000000-0000-0000-0000-000000000101';

    expect($service->getForProfessional($userId))->toBe([
        'delete_customer' => false,
        'delete_media' => false,
    ]);
});

it('updates and reads confirmation preferences for a professional', function () {
    $service = app(ConfirmationPreferenceService::class);
    $userId = '00000000-0000-0000-0000-000000000102';

    $updated = $service->updateForProfessional($userId, [
        'delete_customer' => true,
        'delete_media' => false,
    ]);

    expect($updated)->toBe([
        'delete_customer' => true,
        'delete_media' => false,
    ]);

    $fresh = $service->getForProfessional($userId);
    expect($fresh)->toBe($updated);
});

it('enables a single action via helper', function () {
    $service = app(ConfirmationPreferenceService::class);
    $userId = '00000000-0000-0000-0000-000000000103';

    $service->enableForProfessional($userId, ConfirmationPreferenceService::ACTION_DELETE_CUSTOMER);

    expect($service->getForProfessional($userId))->toBe([
        'delete_customer' => true,
        'delete_media' => false,
    ]);
});

it('ignores unsupported action keys during updates', function () {
    $service = app(ConfirmationPreferenceService::class);
    $userId = '00000000-0000-0000-0000-000000000104';

    $updated = $service->updateForProfessional($userId, [
        'delete_customer' => true,
        'some_future_key' => true,
    ]);

    expect($updated)->toBe([
        'delete_customer' => true,
        'delete_media' => false,
    ]);

    $rowCount = DB::connection('pgsql')
        ->table('user_confirmation_preferences')
        ->where('user_id', $userId)
        ->count();

    expect($rowCount)->toBe(1);
});
