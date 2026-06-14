<?php

/**
 * B23 — GdprPolicy enforcement (DataExportAudit model).
 *
 * GdprPolicy is registered: Gate::policy(DataExportAudit::class, GdprPolicy::class).
 * The `view` method is owner-only; non-owners receive denyAsNotFound() (404 status),
 * not 403 — preventing resource-existence enumeration by non-owners.
 *
 * DataExportAudit.user_id is compared as a string (cast in policy via (string) cast),
 * so in-memory models with string IDs work without DB persistence.
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;

function gdprPolicy_audit(string $userId): DataExportAudit
{
    $audit = new DataExportAudit;
    $audit->user_id = $userId;

    return $audit;
}

function gdprPolicy_user(string $id): User
{
    $user = new User;
    $user->id = $id;

    return $user;
}

// --- Owner access ---

it('allows the owner to view their DataExportAudit record', function () {
    expect(
        Gate::forUser(gdprPolicy_user('owner-1'))->allows('view', gdprPolicy_audit('owner-1'))
    )->toBeTrue();
});

// --- Non-owner: 404 enumeration defence ---

it('returns 404 (not 403) when a non-owner tries to view a DataExportAudit record', function () {
    $response = Gate::forUser(gdprPolicy_user('intruder-2'))->inspect('view', gdprPolicy_audit('owner-1'));

    // denyAsNotFound() must produce a 404 status — never 403 — to prevent
    // enumeration of another user's export records.
    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});
