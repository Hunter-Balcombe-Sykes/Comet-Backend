# DEFERRED — PART 1, unit 11d: `#SEC-12` ≡ `SEM-6`

**Findings:** `#SEC-12` and `SEM-6` (duplicate pair — both boxes left unticked).
A nonexistent `site_id` returns **422** while a real-but-unpublished one returns **404**, so the
status code is a validity oracle for site UUIDs.

**DEFER triggers: §1.2 #2 (public wire) and #3 (larger than S).**
`EXECUTE-PART-1.md` §4 unit 11d authorises exactly this outcome in its own ⚠️:

> If the 422 comes from a Form Request validation rule rather than the controller, moving it may
> change the response shape for other keys in the same request. If that is the case and it is not
> cleanly separable, **DEFER 11d only** and keep 11a–11c.

11a, 11b and 11c were kept. This defers 11d alone.

---

## What I found

The 422 does not come from a controller. It comes from `Rule::exists()` in a Form Request — and in
**eight** of them, all on the public analytics ingest wire:

```
app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php:22
app/Http/Requests/Api/PublicSite/Analytics/PingRequest.php:25
app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php:55
app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php:38
app/Http/Requests/Api/PublicSite/Analytics/ActionTapRequest.php:27
app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php:26
app/Http/Requests/Api/PublicSite/Analytics/SectionDwellRequest.php:29
app/Http/Requests/Api/PublicSite/Analytics/SectionSeenRequest.php:25
```

Each carries the identical rule:

```php
'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
```

## Why it is not cleanly separable

1. **Eight files, not one.** Each has sibling keys whose 422 shape must not change. Collapsing only
   `site_id` to a 404 means the request can now fail two different ways depending on which key is
   bad — the controller would have to re-check `site_id` after validation, in eight places. That is
   comfortably past the **S** this unit was scoped as (§1.2 trigger 3).
2. **It is the public wire.** These are the `Origin`-gated public analytics ingest routes (SEC-1).
   Changing a status code on them is a change a client can observe, which §1.2 trigger 2 puts out of
   bounds for this part.
3. **`required_without:subdomain`.** Removing `exists` also has to preserve the
   `site_id`-XOR-`subdomain` semantics — the rule set is doing two jobs at once, and only one of them
   is the oracle.

## Severity, stated honestly

Lower than the finding's framing suggests, which is part of why deferring is safe:

- Exploiting it requires **guessing a v4 UUID**, so it confirms rather than discovers. It is an
  oracle, not an enumeration primitive — you cannot walk the keyspace.
- Production currently has **`core.users` = 0** and the env is stopped, so nothing is exposed today.
- The route is already `Origin`-gated (SEC-1), which is not an authorisation boundary but does mean a
  browser-based attacker cannot trivially probe it cross-origin.

## The plan I had reached

The CLAUDE.md house rule is unambiguous — *"Public endpoints: always 404 (403 enables enumeration)"* —
so collapsing both cases to 404 is the right destination. Doing it properly:

1. Drop `Rule::exists(...)` from the `site_id` rule in all eight requests, keeping
   `['required_without:subdomain', 'uuid']` so a malformed UUID still 422s (a *format* error is not an
   oracle — it tells you nothing about which sites exist).
2. Resolve the site in the controller/service that already resolves `subdomain`, and `abort(404)` when
   it is null — so the nonexistent and the unpublished case become **byte-identical**.
3. Assert on the **full response body**, not just the status. Two 404s with different bodies is the
   same oracle wearing a different number.
4. Mutation-prove by restoring the 422 and watching the test go red.

**Do this as its own unit on its own branch**, with the blast radius (eight public request classes)
stated up front, because it is a public-wire change.

**Status of the boxes:** both `#SEC-12` and `SEM-6` left **unticked** per §1.2 step 2.
