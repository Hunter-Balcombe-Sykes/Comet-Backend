<?php

// The pre-account claim flow's two races, neither of which any existing test
// exercises. Both are settled purely by Postgres row-lock semantics, so the
// SQLite mirror cannot reach them — `lockForUpdate()` and
// `lock('for update skip locked')` are silently ignored there, and the default
// suite passes identically with or without them.
//
// What was already covered, and why it isn't enough:
//   tests/Feature/PreAccount/ClaimSiteServiceTest.php's "first-come wins: a
//   second claimer gets ALREADY_CLAIMED" runs the two claimers SEQUENTIALLY —
//   claimer A's transaction has fully committed before claimer B begins. That
//   proves the post-commit re-read arm, not the lock. Delete
//   ClaimSiteService's lockForUpdate() and that test stays green.
//
// Race 1 — claimer vs claimer. ClaimSiteService::claim() re-reads the user row
// under lockForUpdate() and only then checks `auth_user_id !== null`. Without
// the lock, N concurrent claimers all read auth_user_id = NULL, all pass the
// gate, and all write their own uid — last writer wins and the losers are told
// they succeeded. Proven here with a real N-way pcntl_fork race (8 children,
// each on its own reconnected Postgres connection, released against a shared
// start gate); the money assertion is that exactly ONE child comes back with a
// success and the surviving row belongs to that same child.
//
// Race 2 — claim vs prune. builds:prune-expired hard-deletes the user row, so
// a prune landing mid-claim would delete the account of someone who has just
// successfully claimed it. PruneExpiredPreAccountBuilds guards this with
// `for update skip locked` — it must never block on, and never steal, a row an
// in-flight claim transaction is holding. Exercised deterministically (no fork,
// no timing dependence) by holding a real FOR UPDATE on a second connection
// while the command runs, then releasing it and re-running: the second run is
// the control that keeps the first assertion from passing vacuously.

use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    foreach (['core', 'site', 'notifications', 'audit'] as $schema) {
        $pg->statement("CREATE SCHEMA IF NOT EXISTS {$schema}");
    }

    // core.users and site.sites are SHARED across this lane (siblings FK to
    // them and never drop them), so: create with the FULL shape — every column
    // defaulted so a sibling's 2-column insert still works — then ALTER-heal in
    // case a sibling that ran first created a narrower one. Never DROP them.
    // LoadCurrentUser:108 resolves PartnaStaff on EVERY authenticated request,
    // and the forked claimers below go through the full HTTP stack. Without
    // this the middleware 42P01s before the claim race under test even runs.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.partna_staff (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        auth_user_id uuid,
        role text
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        auth_user_id  uuid,
        handle        character varying(63),
        handle_lc     character varying(63),
        primary_email text,
        display_name  text,
        status        text NOT NULL DEFAULT \'active\',
        account_type  text NOT NULL DEFAULT \'partna\',
        deleted_at    timestamptz,
        created_at    timestamptz NOT NULL DEFAULT now(),
        updated_at    timestamptz NOT NULL DEFAULT now()
    )');
    foreach ([
        'auth_user_id' => 'uuid',
        'handle' => 'character varying(63)',
        'handle_lc' => 'character varying(63)',
        'primary_email' => 'text',
        'display_name' => 'text',
        'status' => 'text NOT NULL DEFAULT \'active\'',
        'account_type' => 'text NOT NULL DEFAULT \'partna\'',
        'deleted_at' => 'timestamptz',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE core.users ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }

    // The partial unique index ClaimSiteService's 23505 backstop is written
    // against — present for fidelity, not because these cases trip it.
    $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique_claim_race
        ON core.users (lower(primary_email)) WHERE primary_email IS NOT NULL AND deleted_at IS NULL');

    $pg->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id              uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        subdomain            character varying(63) NOT NULL DEFAULT \'\',
        subdomain_changed_at timestamptz,
        settings             jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_published         boolean NOT NULL DEFAULT false,
        unpublished_at       timestamptz,
        custom_domain        text,
        custom_domain_status text,
        architecture_id      text NOT NULL DEFAULT \'staple\',
        created_at           timestamptz NOT NULL DEFAULT now(),
        updated_at           timestamptz NOT NULL DEFAULT now()
    )');
    foreach ([
        'subdomain_changed_at' => 'timestamptz',
        'settings' => 'jsonb NOT NULL DEFAULT \'{}\'::jsonb',
        'is_published' => 'boolean NOT NULL DEFAULT false',
        'unpublished_at' => 'timestamptz',
        'custom_domain' => 'text',
        'custom_domain_status' => 'text',
        'architecture_id' => 'text NOT NULL DEFAULT \'staple\'',
        'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        'updated_at' => 'timestamptz NOT NULL DEFAULT now()',
    ] as $col => $type) {
        $pg->statement("ALTER TABLE site.sites ADD COLUMN IF NOT EXISTS {$col} {$type}");
    }
    // A sibling creates subdomain NOT NULL with no default; this file's own
    // inserts always supply it, but heal it so nothing downstream breaks.
    $pg->statement('ALTER TABLE site.sites ALTER COLUMN subdomain SET DEFAULT \'\'');

    // SiteCacheService::invalidateSite() reads this on every claim AND every
    // prune (it busts each alias's payload key). Without it the post-commit
    // block throws 42P01 and a genuinely successful claim reports as a failure.
    $pg->statement('CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id       uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
        subdomain     character varying(63) NOT NULL,
        created_at    timestamptz NOT NULL DEFAULT now(),
        updated_at    timestamptz NOT NULL DEFAULT now(),
        reclaim_until timestamptz,
        expires_at    timestamptz
    )');

    // Read-only for these tests (both stay empty) but the claim and prune paths
    // query them, so the columns their WHERE clauses name must exist.
    $pg->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id             uuid,
        platform            text,
        is_active           boolean NOT NULL DEFAULT true,
        payload             jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        last_refresh_status text,
        deleted_at          timestamptz,
        created_at          timestamptz NOT NULL DEFAULT now(),
        updated_at          timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS deleted_at timestamptz');
    $pg->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id    uuid NOT NULL,
        path       text,
        pool       varchar(20) NOT NULL DEFAULT \'gallery\',
        media_type varchar(20) NOT NULL DEFAULT \'image\',
        deleted_at timestamptz
    )');
    $pg->statement('ALTER TABLE site.site_media ADD COLUMN IF NOT EXISTS media_type varchar(20) NOT NULL DEFAULT \'image\'');

    // T20 (2026-08-27): claim() now calls ContactFormSeeder::applyClaimDefault(),
    // which queries this table via contactBlock(). Without it that SELECT raises
    // 42P01 — caught by claim()'s own try/catch, but the swallow does not save
    // the surrounding Postgres transaction from being poisoned (25P02 on every
    // statement after), so every claim in this file was failing for a reason
    // that has nothing to do with the row lock under test. Read-only here (no
    // seeded rows — a fresh site has no contact block), so a minimal shape is
    // enough; not shared with any sibling file.
    $pg->statement('CREATE TABLE IF NOT EXISTS site.blocks (
        id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id      uuid NOT NULL,
        site_id      uuid NOT NULL,
        block_type   text NOT NULL DEFAULT \'link\',
        block_group  text NOT NULL DEFAULT \'links\',
        settings     jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        is_active    boolean NOT NULL DEFAULT true,
        deleted_at   timestamptz,
        created_at   timestamptz NOT NULL DEFAULT now(),
        updated_at   timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id    uuid NOT NULL,
        type       text NOT NULL,
        title      text NOT NULL,
        body       text,
        cta_url    text,
        severity   text,
        starts_at  timestamptz,
        ends_at    timestamptz,
        dedupe_key text,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    // createWelcomeNotification's insertOrIgnore compiles to ON CONFLICT DO
    // NOTHING against THIS index — without it the "rows inserted" return value
    // it gates the welcome email on is meaningless.
    $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS notifications_dedupe_key_per_pro_uq
        ON notifications.notifications (user_id, dedupe_key)');

    // Owned exclusively by this file — safe to drop and rebuild each test.
    $pg->statement('DROP TABLE IF EXISTS core.pre_account_builds CASCADE');
    // published_by_claim: T28 (issue 22, 2026-08-27) — claim() forceFills this
    // alongside claimed_at (supabase/migrations/20260828010000_pre_account_builds_published_by_claim.sql);
    // without it that UPDATE 42703s.
    $pg->statement('CREATE TABLE core.pre_account_builds (
        id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id            uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        source_type        text NOT NULL DEFAULT \'instagram\',
        source_ref         text NOT NULL,
        source_ref_lc      text NOT NULL,
        build_state        text NOT NULL DEFAULT \'pending\',
        built_via          text NOT NULL DEFAULT \'signup\',
        contact_email      text,
        claimed_at         timestamptz,
        expires_at         timestamptz,
        built_by_staff_id  uuid,
        published_by_claim boolean NOT NULL DEFAULT false,
        created_at         timestamptz NOT NULL DEFAULT now(),
        updated_at         timestamptz NOT NULL DEFAULT now()
    )');
    // The real partial unique index — one LIVE build per source for the
    // OUTREACH lanes only; signup builds multiply freely since 2026-09-03
    // (migration 20260903140000). Named AND shaped as in supabase/migrations/.
    $pg->statement("CREATE UNIQUE INDEX pre_account_builds_live_source_unique
        ON core.pre_account_builds (source_type, source_ref_lc) WHERE claimed_at IS NULL AND built_via <> 'signup'");

    // PruneExpiredPreAccountBuilds calls this before forceDelete (it is gated on
    // the driver being pgsql, which in THIS lane it genuinely is). A stub is
    // enough — the audit tables it nulls out are not what these tests assert on.
    $pg->statement('CREATE OR REPLACE FUNCTION audit.null_user_audit_links(p_user_id uuid) RETURNS void
        LANGUAGE plpgsql AS $$ BEGIN RETURN; END; $$');

    $pg->statement('DROP TABLE IF EXISTS core.claim_race_probe');
    $pg->statement('CREATE TABLE core.claim_race_probe (
        id        bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        outcome   text NOT NULL,
        uid       text
    )');

    // This lane's CI container runs no Redis and no queue worker. Array cache +
    // faked bus/mail keep every post-commit side effect off the wire; none of
    // them run inside the claim transaction, so none of them affect the race.
    config(['cache.default' => 'array']);
    Bus::fake();
    Mail::fake();
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('DROP TABLE IF EXISTS core.claim_race_probe');
    $pg->statement('DROP TABLE IF EXISTS core.pre_account_builds CASCADE');
    // core.users / site.sites / site.site_media / site.platform_connections /
    // notifications.notifications are shared — leave them for siblings and clean
    // only this file's rows.
    $pg->table('site.sites')->where('subdomain', 'like', 'claimrace-%')->delete();
    $pg->table('core.users')->where('handle', 'like', 'claimrace-%')->delete();
});

/** Seeds an unclaimed provisional user + unpublished site + live build. */
function seedClaimTarget(string $subdomain, ?string $contactEmail = null, ?string $expiresAt = null): array
{
    $pg = DB::connection('pgsql');

    $userId = (string) Str::uuid();
    $pg->table('core.users')->insert([
        'id' => $userId,
        'auth_user_id' => null,          // THE provisional-user model — no auth identity
        'handle' => $subdomain,
        'handle_lc' => $subdomain,
        'primary_email' => null,
        'display_name' => 'Claim Race Fixture',
        'status' => 'unclaimed',
        'account_type' => 'partna',
    ]);

    $siteId = (string) Str::uuid();
    $pg->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $userId,
        'subdomain' => $subdomain,
        'is_published' => false,
    ]);

    $buildId = (string) Str::uuid();
    $pg->table('core.pre_account_builds')->insert([
        'id' => $buildId,
        'user_id' => $userId,
        'source_type' => 'instagram',
        'source_ref' => $subdomain,
        'source_ref_lc' => mb_strtolower($subdomain),
        'build_state' => PreAccountBuild::STATE_READY,
        'contact_email' => $contactEmail,
        'expires_at' => $expiresAt,
    ]);

    return ['user_id' => $userId, 'site_id' => $siteId, 'build_id' => $buildId];
}

it('lets exactly one of 8 concurrently forked claimers win the same subdomain — the others get ALREADY_CLAIMED, never a second success', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $subdomain = 'claimrace-fork';
    $ids = seedClaimTarget($subdomain);

    $childCount = 8;
    $startAt = microtime(true) + 0.25; // shared release gate, not a guess

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // A forked PDO socket shared with the parent corrupts in a way that
            // looks exactly like the bug under test — drop it before doing anything.
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            // Every child is a DIFFERENT person: its own Supabase uid and its own
            // verified email. Only the row lock decides which one wins.
            $uid = (string) Str::uuid();
            $email = "claimer-{$i}@claimrace.test";

            try {
                app(ClaimSiteService::class)->claim($uid, $email, $subdomain);
                $outcome = 'claimed';
            } catch (Throwable $e) {
                $outcome = $e->getMessage();
            }

            DB::connection('pgsql')->table('core.claim_race_probe')->insert([
                'child_idx' => $i,
                'outcome' => $outcome,
                'uid' => $uid,
            ]);

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $probes = DB::connection('pgsql')->table('core.claim_race_probe')->orderBy('child_idx')->get();
    expect($probes)->toHaveCount($childCount, 'A child died before recording its outcome');

    $winners = $probes->filter(fn ($p) => $p->outcome === 'claimed');
    $losers = $probes->filter(fn ($p) => $p->outcome !== 'claimed');

    // 1. THE assertion: one winner, no matter how many raced.
    expect($winners)->toHaveCount(1, 'Outcomes were: '.$probes->pluck('outcome')->implode(', '));

    // 2. Every loser was refused for the RIGHT reason. A loser that came back
    //    with anything else (a 23505 leaking out, a deadlock, a 25P02) means the
    //    lock is not doing the serialising — the count above could still be 1 by
    //    luck while the mechanism is broken.
    foreach ($losers as $loser) {
        expect($loser->outcome)->toBe('ALREADY_CLAIMED',
            "Child {$loser->child_idx} lost for the wrong reason: {$loser->outcome}");
    }

    // 3. The surviving row belongs to the winner, and the account is live.
    $user = DB::connection('pgsql')->table('core.users')->where('id', $ids['user_id'])->first();
    expect($user->auth_user_id)->toBe($winners->first()->uid);
    expect($user->status)->toBe('active');
    expect($user->primary_email)->toBe('claimer-'.$winners->first()->child_idx.'@claimrace.test');

    // 4. The build was consumed exactly once and the site auto-published.
    $build = DB::connection('pgsql')->table('core.pre_account_builds')->where('id', $ids['build_id'])->first();
    expect($build->claimed_at)->not->toBeNull();
    expect((bool) DB::connection('pgsql')->table('site.sites')->where('id', $ids['site_id'])->value('is_published'))->toBeTrue();
});

it('never lets two forked claimers both satisfy an email-gated build', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    // Same race, but every racer presents the SAME verified email — the
    // email-gate branch passes for all of them, so the row lock is the only
    // thing left standing between them. This is the shape that would silently
    // double-bind an invited owner's inbox.
    $subdomain = 'claimrace-emailgate';
    $gatedEmail = 'invited@claimrace.test';
    $ids = seedClaimTarget($subdomain, contactEmail: $gatedEmail);

    $childCount = 6;
    $startAt = microtime(true) + 0.25;

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            $uid = (string) Str::uuid();
            try {
                app(ClaimSiteService::class)->claim($uid, $gatedEmail, $subdomain);
                $outcome = 'claimed';
            } catch (Throwable $e) {
                $outcome = $e->getMessage();
            }

            DB::connection('pgsql')->table('core.claim_race_probe')->insert([
                'child_idx' => $i,
                'outcome' => $outcome,
                'uid' => $uid,
            ]);

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $probes = DB::connection('pgsql')->table('core.claim_race_probe')->get();
    expect($probes)->toHaveCount($childCount);

    $outcomes = $probes->pluck('outcome')->implode(', ');
    expect($probes->filter(fn ($p) => $p->outcome === 'claimed'))->toHaveCount(1, "Outcomes were: {$outcomes}");

    // Every loser must be refused BY THE LOCK — i.e. it acquired the row, saw a
    // committed auth_user_id, and bailed at the ALREADY_CLAIMED gate.
    //
    // This is the load-bearing half of this case. "Exactly one winner" alone is
    // NOT sensitive to the lock here: with lockForUpdate() removed, the losers
    // still mostly fail — but with EMAIL_ALREADY_REGISTERED, thrown by
    // EmailReuseGuard on a fresh READ COMMITTED snapshot that happens to have
    // caught the winner's commit. That is an accident of scheduling, not a
    // guarantee; it degrades to a double-claim as soon as the racers arrive
    // closer together. Pinning the REASON is what makes the case fail when the
    // lock goes away instead of passing on a timing coincidence.
    foreach ($probes->filter(fn ($p) => $p->outcome !== 'claimed') as $loser) {
        expect($loser->outcome)->toBe('ALREADY_CLAIMED',
            "Child {$loser->child_idx} was refused by a downstream guard rather than the row lock: {$loser->outcome}");
    }

    // The invited inbox is bound to exactly one account.
    expect(DB::connection('pgsql')->table('core.users')->where('primary_email', $gatedEmail)->count())->toBe(1);
});

it('leaves an expired build alone while a claim transaction holds its user row, and prunes it once the lock is released', function () {
    $subdomain = 'claimrace-prune';
    $ids = seedClaimTarget($subdomain, expiresAt: now()->subDay()->toDateTimeString());

    // A genuinely separate Postgres connection standing in for an in-flight
    // ClaimSiteService::claim() — it holds exactly the lock claim() holds
    // (lockForUpdate on the user row) for the whole of the command below.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->select('SELECT id FROM core.users WHERE id = ? FOR UPDATE', [$ids['user_id']]);

    // Bound the blocking case so a regression fails in seconds instead of
    // hanging the lane forever. CI sets DB_APPLY_TIMEOUTS_IN_TESTS=1 (10s), but
    // a bare local run applies no timeout at all — this makes the case behave
    // identically either way.
    DB::connection('pgsql')->statement('SET lock_timeout = 2000');

    Artisan::call('builds:prune-expired');
    $skipRunOutput = trim(Artisan::output());

    // "Did not delete" is NOT enough on its own: `for update skip locked`
    // downgraded to a plain `for update` ALSO leaves the row in place — it
    // blocks on the claim's lock, dies on lock_timeout, and the command's
    // per-candidate catch turns that into a skipped candidate. Same surviving
    // row, completely different behaviour: one is a clean pass-over, the other
    // is a stalled nightly sweep burning its timeout on every locked row.
    //
    // So assert the command's OWN verdict. SKIP LOCKED yields a clean
    // zero-failure run; anything that blocks reports "(1 failed, will retry
    // next run)".
    expect($skipRunOutput)->toBe('Pruned 0 of 1 candidate build(s).',
        'prune did not pass cleanly over a locked row — it blocked and failed the candidate');

    expect(DB::connection('pgsql')->table('core.users')->where('id', $ids['user_id'])->exists())
        ->toBeTrue('prune deleted a user row that an in-flight claim was holding');
    expect(DB::connection('pgsql')->table('core.pre_account_builds')->where('id', $ids['build_id'])->exists())
        ->toBeTrue();

    // Release the claim's lock and re-run. THIS is the control: without it, the
    // assertions above would pass just as happily against a prune that never
    // deletes anything at all.
    $second->rollBack();
    DB::purge('pgsql_second');

    Artisan::call('builds:prune-expired');

    expect(DB::connection('pgsql')->table('core.users')->where('id', $ids['user_id'])->exists())
        ->toBeFalse('prune failed to delete an expired unclaimed build once the lock was gone — the skip assertion above is vacuous');
    expect(DB::connection('pgsql')->table('core.pre_account_builds')->where('id', $ids['build_id'])->exists())
        ->toBeFalse();

    DB::connection('pgsql')->statement('SET lock_timeout = DEFAULT');
});
