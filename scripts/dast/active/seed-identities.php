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

use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// The bootstrap MUST sit below the `use` block, not above it. PHP registers
// namespace imports linearly as it compiles a file, so a `use` declared after a
// call site does not apply to it — with the bootstrap first, `Kernel::class`
// compiles to the literal string 'Kernel' and the container dies with
// "Target class [Kernel] does not exist", killing the whole active lane.
//
// That is exactly how 6461cff5 (a PINT-ONLY commit, 2026-07-28) broke this
// script: Pint rewrote the original fully-qualified
// `Illuminate\Contracts\Console\Kernel::class` into a short reference plus an
// import, and placed the import below the call. Nothing caught it because this
// lane is manual-only. Keep this ordering — it is Pint-stable and correct.
// CONTAINMENT GUARD 1 — .env.dast must exist before anything boots.
// Without it, `--env=dast` finds no dast environment file and Laravel falls
// back to the repo's REAL .env, so this seeder would create users and write
// rows against whatever SUPABASE_URL/DB_HOST the developer actually uses.
// That is not theoretical: on 2026-08-07 a stale bring-up.env let this script
// run before bring-up.sh had written .env.dast, and it POSTed to a remote
// *.supabase.co admin endpoint — harmless only because the host was
// unreachable. mint-jwt.php has always had this check; this file did not.
$envDast = __DIR__.'/../../../.env.dast';
if (! file_exists($envDast)) {
    fwrite(STDERR, "$envDast not found — the active lane's scratch stack is not up. Refusing to seed: without .env.dast this would target the REAL .env's Supabase/DB. Run via zap-active.sh, never directly.\n");
    exit(1);
}

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// CONTAINMENT GUARD 2 — the resolved targets must be loopback, full stop.
// Guard 1 proves a dast env file exists; this proves it actually points at the
// throwaway stack. Belt and braces on purpose: this script creates auth users
// and writes rows, so "NEVER point this at real dev/prod" (bring-up.sh) needs
// an assertion, not just a comment. Checked AFTER bootstrap because it reads
// the resolved config rather than re-parsing the file.
foreach ([
    'supabase.url' => config('supabase.url'),
    'database.connections.pgsql.host' => config('database.connections.pgsql.host'),
] as $key => $value) {
    $host = parse_url((string) $value, PHP_URL_HOST) ?: (string) $value;
    if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "refusing to seed: $key resolves to '$host', which is not loopback. The active lane must only ever touch its own throwaway stack.\n");
        exit(1);
    }
}

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

/**
 * Tier 2 fixture: a staff identity. Staff-ness is the core.partna_staff row
 * ALONE (reference: staff access model) — the core.users row exists only so
 * LoadCurrentUser can resolve a caller for the non-staff control route the
 * probe hits first (/api/me).
 *
 * core.partna_staff keys on auth_user_id and has NO user_id column — verified
 * against supabase/migrations/20260726000000_baseline_pilot.sql:1000.
 *
 * auth_user_id and status are NOT in User::$fillable (verified 2026-07-30);
 * mass assignment would silently drop them, so both are assigned directly —
 * same discipline as seedIdentity() above.
 */
function seedStaffIdentity(string $label): array
{
    $email = "dast-staff-{$label}@example.invalid";
    $password = 'dast-seed-'.bin2hex(random_bytes(12));
    $authUserId = createAuthUser($email, $password);

    $user = new User([
        'handle' => "dast-staff-{$label}",
        'handle_lc' => "dast-staff-{$label}",
        'display_name' => "DAST Staff {$label}",
        'first_name' => 'DAST',
        'last_name' => 'STAFF',
        'account_type' => 'partna',
        'primary_email' => $email,
    ]);
    $user->auth_user_id = $authUserId;
    $user->save();

    DB::connection('pgsql')->table('core.partna_staff')->insert([
        'id' => (string) Str::uuid(),
        'auth_user_id' => $authUserId,
        'role' => 'admin',
        'primary_email' => $email,
        'name' => "DAST Staff {$label}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'email' => $email,
        'password' => $password,
        'auth_user_id' => $authUserId,
        'user_id' => $user->id,
        'role' => 'admin',
    ];
}

/**
 * Tier 2 fixture: a pool of claimants. A claimant is an auth user with NO
 * core.users row — ClaimSiteService::claim() throws ACCOUNT_EXISTS the moment
 * User::where('auth_user_id', $uid)->exists() is true, so seedIdentity() is
 * deliberately NOT reused here.
 *
 * Distinct uids also keep each claimant in its own throttle:claim bucket (the
 * limiter keys on 'claim:'.$supabase_uid — AppServiceProvider.php:754), which
 * is what lets N of them run concurrently without 429s.
 *
 * @return list<array{email: string, password: string, auth_user_id: string}>
 */
function seedClaimants(int $count): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $email = "dast-claimant-{$i}@example.invalid";
        $password = 'dast-seed-'.bin2hex(random_bytes(12));
        $out[] = [
            'email' => $email,
            'password' => $password,
            'auth_user_id' => createAuthUser($email, $password),
        ];
    }

    return $out;
}

/**
 * Tier 2 fixture: one unclaimed, published, FIRST-COME build.
 *
 * contact_email is left NULL on purpose — that is the branch in
 * ClaimSiteService::claim() which skips the email gate and makes the row
 * claimable by any verified email. With the gate active only one email could
 * ever win and the race would be untestable.
 *
 * status='unclaimed' is assigned directly (not fillable) because
 * claim() rejects anything else with ALREADY_CLAIMED before the race even
 * starts. site.sites and core.pre_account_builds are written with raw inserts
 * so no observer fires: SiteObserver dispatches Cloudflare purge / KV sync
 * jobs, and QUEUE_CONNECTION=sync in .env.dast would run them inline.
 *
 * source_type / built_via / build_state values are constrained by CHECKs in
 * the baseline migration (lines 1036-1038).
 */
function seedFirstComeBuild(string $label): array
{
    $subdomain = "dast-firstcome-{$label}";

    $user = new User([
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'display_name' => "DAST FirstCome {$label}",
        'first_name' => 'DAST',
        'last_name' => 'FIRSTCOME',
        'account_type' => 'partna',
    ]);
    $user->auth_user_id = null;
    $user->primary_email = null;
    $user->status = 'unclaimed';
    $user->save();

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $subdomain,
        'is_published' => true,
        'settings' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $buildId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $user->id,
        'source_type' => 'instagram',
        'source_ref' => $subdomain,
        'source_ref_lc' => $subdomain,
        'built_via' => 'signup',
        'build_state' => 'ready',
        'contact_email' => null,     // ← first-come. Do not set.
        'auto_invite' => false,      // no outreach from a test fixture
        'expires_at' => now()->addDays(30),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['subdomain' => $subdomain, 'user_id' => $user->id, 'build_id' => $buildId];
}

// CLAIM_RACE_N = 8 — enough contention to make a lock failure obvious without
// straining a laptop.
$identities = [
    'A' => seedIdentity('a'),
    'B' => seedIdentity('b'),
    'staff' => seedStaffIdentity('a'),
    'claimants' => seedClaimants(8),
    'firstComeBuild' => seedFirstComeBuild('a'),
];

file_put_contents("$outdir/identities.json", json_encode($identities, JSON_PRETTY_PRINT));
fwrite(STDERR, "[dast] seed-identities: wrote $outdir/identities.json (users {$identities['A']['id']}, {$identities['B']['id']})\n");
