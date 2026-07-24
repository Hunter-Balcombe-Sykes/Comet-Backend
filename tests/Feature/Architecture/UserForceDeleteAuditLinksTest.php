<?php

// Guard for Nightwatch #308 — any hard-delete of a core.users row must pre-null
// the two append-only audit FK links (audit.staff_audit_log, audit.handle_change_log)
// via audit.null_user_audit_links() BEFORE forceDelete(), or Postgres's
// reject-mutation triggers abort the delete (ON DELETE SET NULL needs an UPDATE
// on those tables — see docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md).
//
// This bug shipped once already: PruneExpiredPreAccountBuilds called
// $user->forceDelete() directly, skipping the helper that
// AccountDeletionService::purge() already called, so the nightly sweep threw
// P0001 and never pruned anything. A third hard-delete path added later
// without the guard would reproduce it.
//
// Parses source files for `$var->forceDelete()` where $var is resolvable to a
// User instance in the same file (typed `User $var` in a signature, or
// `$var = User::...` assignment), and asserts the file invokes
// audit.null_user_audit_links() at least once PER such call site. Counting,
// not mere presence: the likeliest place for a future unguarded hard-delete is
// one of the two files that already call the helper, and a file-wide
// str_contains() would wave it straight through. String-cheap by design,
// mirroring SubdomainKvWritersTest's single-writer guard — a full AST type
// resolver is overkill for one guard test.
//
// Known limitation: the receiver must be a bare `$var`. A property chain
// (`$this->user->forceDelete()`) or an indirectly-assigned User
// (`$u = $repo->find(...)`) is not detected. None exist in app/ today.

// Documented exemptions: relative file path => reason. Empty today — both
// known call sites (AccountDeletionService::purge, PruneExpiredPreAccountBuilds)
// invoke the helper. A new entry here must justify why the guard genuinely
// doesn't apply (e.g. the row provably can't have staff_audit_log/
// handle_change_log references), not just silence the failure.
const USER_FORCE_DELETE_AUDIT_EXEMPT = [];

it('every core.users forceDelete call site pre-nulls the append-only audit links', function () {
    $violations = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/\$(\w+)\s*->\s*forceDelete\s*\(\s*\)/', $source, $matches) === 0) {
            continue;
        }

        if (isset(USER_FORCE_DELETE_AUDIT_EXEMPT[$relative])) {
            continue;
        }

        // `User` only means the Core\User\User model if this file imports it —
        // otherwise a typed `User $var` refers to some other class entirely.
        if (! str_contains($source, 'use App\Models\Core\User\User;')) {
            continue;
        }

        $userSites = [];

        foreach ($matches[1] as $var) {
            // Resolve $var to a User instance within this file: either a typed
            // parameter/property (`User $var`) or a static-call assignment
            // (`$var = User::...`). Both known call sites use one of these.
            $typed = preg_match('/\bUser\s+\$'.preg_quote($var, '/').'\b/', $source) === 1;
            $assigned = preg_match('/\$'.preg_quote($var, '/').'\s*=\s*User::/', $source) === 1;

            if ($typed || $assigned) {
                $userSites[] = $var;
            }
        }

        if ($userSites === []) {
            continue;
        }

        // Count real invocations, not the string anywhere: `audit.null_user_audit_links(`
        // is the SQL call form, so a log message or comment naming the helper
        // can't satisfy the guard on another call site's behalf.
        $guards = preg_match_all('/audit\.null_user_audit_links\s*\(/', $source);

        if ($guards >= count($userSites)) {
            continue;
        }

        $violations[$relative] = count($userSites).' User forceDelete() site(s) ($'
            .implode(', $', array_unique($userSites)).") but only {$guards} pre-null call(s)";
    }

    expect($violations)->toBe([], "core.users forceDelete() without the append-only audit pre-null (Nightwatch #308):\n - ".
        implode("\n - ", array_map(fn ($f, $v) => "{$f}: {$v}", array_keys($violations), $violations)));

    foreach (array_keys(USER_FORCE_DELETE_AUDIT_EXEMPT) as $relative) {
        expect(file_exists(base_path($relative)))->toBeTrue("Exemption entry {$relative} does not resolve to an existing file.");
    }
});
