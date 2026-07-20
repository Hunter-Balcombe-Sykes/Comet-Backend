<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// Shared seeding for the integration contract golden master. Mirrors the
// existing PlatformResourceContractTest helpers so the snapshots use the same
// row shape the app writes in production.
function gmUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function gmSeed(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId ?? $platform,
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}
