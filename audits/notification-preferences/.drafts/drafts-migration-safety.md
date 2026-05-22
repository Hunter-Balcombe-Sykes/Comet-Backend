I cannot perform a meaningful migration-safety audit on `supabase/migrations/*.sql` files because **no migration SQL files were provided**. The "Files Under Audit" section contains only PHP application code (controllers, models, services, jobs, policies, routes). The lens requires quoting verbatim DDL/DML from migration files — category (1) `CREATE INDEX`, category (2) `ADD CONSTRAINT`, category (3) backfill `UPDATE` statements, etc. None of that exists in the provided PHP files.

That said, the PHP code does surface **one pattern that reveals a migration-deployment gap** worth flagging:

- [ ] **MIG-1** · P2 — Runtime `Schema::hasColumn()` check suggests `email_lc` migration was never hardened for guaranteed apply
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:283-299`
    - **Affects:** Every public subscribe request (`POST /public/subscribe`) — the `Schema::hasColumn` call runs on every request until the static cache warms per process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Locate the migration that added `email_lc` to `notifications.email_subscriptions` (likely `202605190000002_add_enum_check_constraints.sql` or similar).
        - Remove the `emailLcColumnExists()` guard and the conditional `$subscription->email_lc = $email` assignment — assume the column is present in all environments.
        - If the column truly doesn't exist in production, backfill it in a dedicated migration with proper online-DDL hygiene before removing the guard.
    - **Technical:** The guard pattern (`static $cached = null; Schema::hasColumn(...)`) exists because the codebase doesn't trust that `email_lc` is present everywhere. This usually means the migration was applied out-of-order on some environment, or the column was added without a `supabase db push` verification step. In either case the guard is a silently-deployed partial migration — the exact class of risk this lens hunts. The guard itself isn't lock-risky, but it signals that the underlying migration (which I cannot quote because the file wasn't provided) may have issues like missing `IF NOT EXISTS`, a volatile default on a hot table, or a cross-schema `ADD COLUMN` without schema qualification.
    - **Plain English:** There's a piece of code that checks "does this database column exist?" every time someone subscribes to a newsletter, and only writes data to it if the answer is yes. It's like having a mailbox that you only use if you remember to check whether it's been installed. This means some environments have the column and some don't — the migration that was supposed to add it everywhere probably got applied inconsistently, and the guard is the band-aid. Remove the band-aid by making sure the column exists everywhere, then delete the check.
    - **Evidence:**
        ```php
        // PublicEmailSubscriptionController.php:283-299
        private function emailLcColumnExists(): bool
        {
            static $cached = null;
            if ($cached !== null) {
                return $cached;
            }
            $cached = Schema::hasColumn('email_subscriptions', 'email_lc')
                || Schema::hasColumn('core.email_subscriptions', 'email_lc');
            return $cached;
        }
        ```
        ```php
        // Called at PublicEmailSubscriptionController.php:145
        if ($this->emailLcColumnExists()) {
            $subscription->email_lc = $email;
        }
        ```
    - `[DRAFT, confidence: 0.7]`

---

**To complete this audit**, re-scope with the actual migration files. The canonical command:

```
--scope supabase/migrations
```

I need to see every `.sql` file in that directory to hunt for: `CREATE INDEX` without `CONCURRENTLY`, `ADD CONSTRAINT` without `NOT VALID`, backfill `UPDATE` statements without `WHERE` guards, `DROP COLUMN` without rollback comments, `ALTER COLUMN TYPE`, and `SET NOT NULL` on populated columns. The PHP code alone cannot answer any category (1)–(8) question.
