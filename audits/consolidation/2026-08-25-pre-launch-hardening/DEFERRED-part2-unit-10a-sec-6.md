# DEFERRED — PART 2 unit 10a · `#SEC-6` (`ShortLinkExpander` caches an unredacted expanded URL)

**Finding:** `#SEC-6` · P2, `audits/sweeps/2026-08-24-unified-actions-security/CONSOLIDATED.md:213`
⚠️ **Not** the `#SEC-6` at `overnight-run:222` (that one is about `SafeUrlFetcher`'s byte cap).

**DEFER trigger:** §1.2 **trigger 2** — the fix as decided changes what lands in the identifier column
of a **live UNIQUE partial index**. Also **trigger 4-adjacent**: the independent review returned FAIL
with a must-fix defect, and the remedy is a design decision rather than a correction.

**Written:** 2026-08-26. The implementation was completed, independently reviewed, **FAILED review, and
was reverted.** The rejected work is preserved beside this file as
`DEFERRED-part2-unit-10a-sec-6.rejected.patch` — it is worth keeping, because ~80% of it is reusable
under any of the three options below. The source checkbox is left **unticked**.

---

## 1. The defect is real — verified three ways

The decided fix was: run the expanded URL through `SecretParams::redactUrl()` inside
`ShortLinkExpander::resolveFinal()` before it is cached and returned.

**That defeats `IriCanonicalizer`'s value-shape secret detection.**

`SecretParams::isSecret($key, $value)` returns true on **either** of two grounds:

```php
foreach (self::segments($key) as $segment) {           // (1) by KEY segment
    if (in_array($segment, self::SECRET_SEGMENTS, true)) { return true; }
}
if ($value !== '' && preg_match(self::JWT_PATTERN, $value) === 1) { return true; }   // (2) by VALUE shape
foreach (self::SECRET_VALUE_PREFIXES as $prefix) {                                    // (2) cont.
    if (str_starts_with($value, $prefix)) { return true; }
}
```

`JWT_PATTERN` is `/^eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\./`; `SECRET_VALUE_PREFIXES` is 18 vendor
prefixes (`sk_live_`, `ghp_`, `AKIA`, `AIza`, `xoxb-`, `shpat_`, …).

`IriCanonicalizer::filterQuery()` (`app/Routing/IriCanonicalizer.php:418`) **DROPS** — not redacts — any
param `isSecret()` matches:

```php
if (SecretParams::isSecret((string) $key, (string) $value)) {
    continue;                      // dropped from `canonical` entirely
}
```

`redactUrl()` **keeps the key and replaces the value** with the literal `[redacted]` (deliberately — its
docblock explains that dropping the pair would destroy `raw_url`'s reason to exist for
`routing:reproject`). But `[redacted]` matches **neither** the JWT pattern **nor** any vendor prefix.

**So a param with an innocuous KEY and a secret-SHAPED VALUE flips from dropped to kept.** Demonstrated
live by the reviewer against the real classes:

```
IN:                        https://example.com/dest?code=sk_live_abcdef1234567890&other=1
canonical(RAW):            https://example.com/dest?other=1                          ← code DROPPED, correct
canonical(REDACTED first): https://example.com/dest?code=%5Bredacted%5D&other=1       ← code KEPT, polluted
```

Note the key-named case (`?token=…`) is unaffected — `isSecret` still matches by key segment after
redaction, so it is still dropped. **It is precisely the case where a secret hides behind a harmless key
name that breaks** — arguably the case that matters most.

### Why that reaches a UNIQUE index

`SourceReconciler::reconcile()` (`app/Routing/SourceReconciler.php:84`):

```php
$identifier = $placement->identifier ?? $iri->canonical ?? SecretParams::redactUrl($iri->raw) ?? '';
```

`$iri->canonical` is a **live** fallback, not a theoretical one: `LinkProjector::matchOne()`
(`app/Routing/LinkProjector.php:181-186`) returns `identifier: null` whenever a matched detector's
`identifier_capture` is null — i.e. every host/path-only detector with no identity segment.

`identifier` backs `idx_source_intents_live`, `UNIQUE ("user_id","surface_key","identifier")`
(`supabase/migrations/20260727120000_routing_schema.sql:89`).

**Two consequences:**
1. The stored identifier for a link reached *via short-link expansion* now differs from the identifier
   for the *same destination pasted directly*.
2. Because every redacted value collapses to the identical literal `[redacted]`, two genuinely different
   destinations differing only in that param **collapse onto the same fabricated identifier** — a false
   collision on a live UNIQUE index, not a cosmetic difference.

**The comment shipped in the rejected patch — "Identity is unaffected: SourceReconciler's identifier
column comes from `Iri::canonical`, which `IriCanonicalizer` strips independently via its own
`filterQuery()`" — is wrong for this class of param.** It is right for the key-named class only.

---

## 2. What the attempt DID get right — reuse this, do not redo it

Preserved in `DEFERRED-part2-unit-10a-sec-6.rejected.patch`:

- **The `''` trap, found and correctly closed.** `redactWith()` returns `''` on two paths: input over
  `MAX_LENGTH` (8KB), and a PCRE engine error (`preg_replace_callback(...) ?? ''`, fail-closed).
  `CacheLockService::rememberLockedNullable` treats only literal `null` as the sentinel, so a `''` is an
  ordinary value and would be cached at `SUCCESS_TTL_SECONDS` (**24h**) — and `expandIfShort()`'s
  pre-existing `$expanded !== ''` guard would then silently return the UN-expanded URL for a day. The
  patch guards it by only promoting a non-empty redaction to `$final`. The reviewer verified this
  independently with a real >8192-char candidate: the cache correctly held the null sentinel on the 1h
  `nullTtl` path. **Keep this regardless of which option below is chosen.**
- **Double-redaction is idempotent** — verified against 4 constructed inputs including ones already
  containing `[redacted]` and `%5Bredacted%5D`. The pair-scanning regex's value class `[^&#\s]*` does not
  exclude brackets, so there is no interaction bug. `LinkObserver::record()` calling
  `minimiseUrl($iri->raw)` on an already-redacted string is safe.
- **The test file**, which is sound apart from needing a case for the value-shape scenario above.

---

## 3. Three ways to close this properly — a decision is needed

### Option A — recognise the redaction placeholder in `isSecret()` *(recommended, smallest)*
Teach `SecretParams::isSecret()` that its own placeholder is secret-shaped, so `filterQuery()` drops it
exactly as it drops the raw value. `canonical` then becomes **byte-identical** between the redacted and
raw paths, and the identity defect disappears.

- **Pro:** ~2 lines. Fixes the collision at its root. No change to `ShortLinkExpander`'s design, so the
  rejected patch applies almost as-is.
- **Con:** it makes a shared security predicate partly about "has this already been redacted?" rather
  than purely "is this a secret?" — a reviewer could reasonably object to the coupling. Needs the full
  routing corpus re-run (`RoutingCorpusTest`, `RoutingCoverageMatrixTest`, `TombstoneResurrectionTest`),
  because `isSecret` is consumed across the whole routing lane, not just here.
- **Verify before adopting:** that no caller depends on a literal `[redacted]` value SURVIVING
  `filterQuery()`.

### Option B — don't cache secret-bearing expansions at all
If `redactUrl($candidate) !== $candidate`, return the real URL for this request but skip the 24h cache
write, so the token never sits in Redis.

- **Pro:** identity is untouched (the real URL flows through), and it satisfies the finding's literal
  complaint — the token is not persisted for 24h.
- **Con:** `rememberLockedNullable` has no "compute but do not cache" path, so it needs a bypass; and
  those links re-fetch on every request, which partly defeats the stampede protection `CCH-2` added.

### Option C — separate the cached value from the returned value
Cache the redacted form, return the real one.

- **Con:** incoherent on a cache HIT, which would hand back the redacted form anyway. **Not recommended
  — listed so nobody re-derives it and thinks it is new.**

**Recommendation: Option A**, with the routing corpus re-run as the gate. Option B is the fallback if a
reviewer rejects the coupling in A.

---

## 4. Residual that survives ALL options — worth a decision of its own

`RoutingController::store()`'s Note branch writes `$result['canonicalUrl'] ?? SecretParams::redactUrl($url) ?? ''`
as the custom-link card's href, and `canonicalUrl` traces back to `$iri->canonical`. So **a card seeded
from a short link whose destination is a genuinely signed one-time URL will not resolve for a visitor.**

This is a real security-over-usability trade-off, and it is inherent to redacting anywhere upstream of
card creation. The reviewer judged it acceptable. **If it is adopted, it must be documented at the write
site or in `ShortLinkExpander`'s class docblock — not only in a report nobody will read later.**

---

## 5. Also worth knowing

- **The `''` guard is code-correct but test-uncovered.** The reviewer's second, harder mutation —
  loosening `is_string($redacted) && $redacted !== ''` to `is_string($redacted)` — left **all 13 tests
  green**. Whichever option is chosen, add a test that exercises the `MAX_LENGTH` / PCRE-failure path,
  or the guard will silently rot.
- **Severity is unchanged and modest.** A short link resolving to a token-bearing URL keeps that token in
  Redis for 24h. Prod carries no traffic and no users today.
- TTLs must stay untouched (the file says so twice), and `rememberLockedNullable` must NOT be "upgraded"
  to `rememberLocked` — feeding a null through it poisons the stale twin.

---

## 6. What I did

- Implemented, reviewed independently, **FAILED**, and **reverted** — `git checkout --` on
  `app/Routing/ShortLinkExpander.php` and `tests/Feature/Routing/ShortLinkExpanderTest.php` only.
- Saved the rejected work as `DEFERRED-part2-unit-10a-sec-6.rejected.patch`.
- Left `unified-actions-security/CONSOLIDATED.md:213` **`- [ ]` unticked**.
- Did **not** attempt a second implementation round: the remedy is a design choice between A and B, not
  a defect to correct, and it lands on the routing identity hot path.
