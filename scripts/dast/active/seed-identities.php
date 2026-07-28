<?php

// Active lane: seed two identities that each own distinct resources — a
// site, a gallery media row, a customer, and an enquiry. Known ids are the
// cross-identity IDOR targets for Phase 4's active scan (token A against
// B's resource ids). Deterministic: fixed handles, no randomness, because
// each run targets a fresh throwaway scratch DB (see bring-up.sh).
//
// Usage: php active/seed-identities.php --env=dast <OUTDIR>
// Must run with --env=dast so Laravel loads .env.dast (see bring-up.sh) —
// verified 2026-07-26 that a plain bootstrapped script (not just artisan)
// honours --env=X via Illuminate's own ArgvInput scan, the same mechanism
// `artisan --env=dast` uses. Writes $OUTDIR/identities.json.

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$outdir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (! str_starts_with($arg, '--')) {
        $outdir = $arg;
        break;
    }
}
if (! $outdir) {
    fwrite(STDERR, "usage: php active/seed-identities.php --env=dast <OUTDIR>\n");
    exit(1);
}

/**
 * core.users.auth_user_id carries a real FK to auth.users (discovered the
 * hard way: an arbitrary UUID 23503-violates it) — the row must exist via
 * Supabase Auth itself, not be invented. Creates it through the local
 * GoTrue admin API (service-role key), which is the supported way to
 * create an Auth user server-side and is what a real signup flow's
 * Auth-first step does anyway. The password is returned (not just
 * discarded) because mint-jwt.php needs it — see that file for why
 * direct-signing a token doesn't work against this GoTrue version.
 */
function createAuthUser(string $email, string $password): string
{
    $response = Http::withHeaders([
        'apikey' => config('supabase.service_role_key'),
        'Authorization' => 'Bearer '.config('supabase.service_role_key'),
    ])->post(rtrim(config('supabase.url'), '/').'/auth/v1/admin/users', [
        'email' => $email,
        'email_confirm' => true,
        'password' => $password,
    ]);

    if (! $response->successful()) {
        fwrite(STDERR, "Auth admin user creation failed: {$response->status()} {$response->body()}\n");
        exit(1);
    }

    return $response->json('id');
}

function seedIdentity(string $label): array
{
    $email = "dast-identity-{$label}@example.invalid";
    $password = 'dast-seed-'.bin2hex(random_bytes(12));
    $authUserId = createAuthUser($email, $password);

    $user = new User([
        'handle' => "dast-identity-{$label}",
        'handle_lc' => "dast-identity-{$label}",
        'display_name' => "DAST Identity {$label}",
        'first_name' => 'DAST',
        'last_name' => strtoupper($label),
        'account_type' => 'partna',
    ]);
    $user->auth_user_id = $authUserId;
    $user->save();

    $site = new Site([
        'subdomain' => "dast-identity-{$label}",
        'is_published' => true,
    ]);
    $site->user()->associate($user);
    $site->save();

    $media = new SiteMedia([
        'path' => "dast/{$label}/gallery-1.jpg",
        'pool' => 'gallery',
        'media_type' => 'image',
    ]);
    $media->site_id = $site->id;
    $media->save();

    $customer = new Customer([
        'email' => "dast-identity-{$label}@example.invalid",
        'full_name' => "DAST Customer {$label}",
        'source' => 'dast-seed',
    ]);
    $customer->user_id = $user->id;
    $customer->save();

    $enquiry = new Enquiry([
        'name' => "DAST Enquirer {$label}",
        'email' => "dast-enquiry-{$label}@example.invalid",
        'subject' => 'DAST seed enquiry',
        'message' => "Seeded by active/seed-identities.php for identity {$label}.",
    ]);
    $enquiry->user_id = $user->id;
    $enquiry->site_id = $site->id;
    $enquiry->save();

    return [
        'id' => $user->id,
        'auth_user_id' => $authUserId,
        'email' => $email,
        'password' => $password,
        'handle' => $user->handle,
        'site_id' => $site->id,
        'media_id' => $media->id,
        'customer_id' => $customer->id,
        'enquiry_id' => $enquiry->id,
    ];
}

$identities = [
    'A' => seedIdentity('a'),
    'B' => seedIdentity('b'),
];

file_put_contents("$outdir/identities.json", json_encode($identities, JSON_PRETTY_PRINT));
fwrite(STDERR, "[dast] seed-identities: wrote $outdir/identities.json (users {$identities['A']['id']}, {$identities['B']['id']})\n");
