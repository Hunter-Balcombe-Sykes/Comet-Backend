# PLAN — W9 · Shop async connect (path (c), hybrid)

Branch: `audit-fix/connect-shop-async-2026-07-24`
Worktree: `/Users/joshuahunter/Herd/Side Street/backend-wt/connect-shop-async-2026-07-24`
Base: `origin/development` @ `58c70bc0` + W2 cherry-pick `e49f302b`
Ledger: `.superpowers/sdd/progress-2026-07-24-connect-shop.md`
Plan model: Opus 4.8 · Implement: Sonnet 4.6 · Review: separate Sonnet 4.6 · Final: Opus 4.8

---

## 1. Executive summary

`POST /api/platforms/shop/brands` keeps its synchronous provider detection — which is where
almost all of Shop's latency actually lives — writes a **fully-keyed, fully-queryable**
`site.shop_brands` row (truthful `brand_id` and `provider`, no sentinel), and defers only the
brand-profile fetch (`name` / `currency` / `favicon` / `logo`) to a new brand-keyed
`ShopBrandConnectJob`, returning `202 {status:'pending', …brand, statusUrl}` polled at
`GET /brands/{id}/connect/status`. The migration adds two NULLABLE, no-default columns to
`site.shop_brands` — `connect_status` (`'pending'|'failed'|NULL`) and `connect_error` — plus one
two-window `NOT VALID` → `VALIDATE` CHECK; no new index, so no `CONCURRENTLY` file.
**The headline risk is that the win is small and unevenly distributed:** only three of six provider
paths (Shopify, WooCommerce, Squarespace) defer any network work at all — BigCartel, generic and
client-assisted connects already hold their whole profile in memory at 202 time and therefore stay
synchronous `200`, so this endpoint returns a **provider-dependent status code**.
Everything lands dark behind `PARTNA_CONNECT_DEFERRED`; with `shop` absent, `addBrand()` is
byte-identical and dispatches nothing.

---

## 2. Blast radius

### Migration (1 new file)
| File | Why |
|---|---|
| `supabase/migrations/20260724150000_shop_brands_connect_status.sql` | **new.** `connect_status` + `connect_error` columns + the two-window CHECK. |

### Models / services (business logic — `Services/`, per CLAUDE.md)
| File | Why |
|---|---|
| `app/Models/Core/Site/ShopBrand.php` | `connect_status` / `connect_error` in `$fillable`; `CONNECT_STATUSES` constant (lockstep source of truth); optional keys on `toBrandArray()`. |
| `app/Services/Platforms/ShopBrandIdentity.php` | **new.** Sole synchronous `brand_id` derivation, delegating to the same scraper expressions `fetchBrand()` uses. |
| `app/Services/Platforms/ShopBrandProfiler.php` | **new.** The deferred half of today's `ShopController::brandProfileFor()` — two entry points (from a `$detected` array, from a stored `ShopBrand` row). |
| `app/Services/Platforms/ShopProviderDetector.php` | Carry the already-fetched Shopify `meta.json` forward on the detected array (zero extra HTTP). |
| `app/Services/Platforms/ShopifyScraper.php` | `probeMeta()` (returns what `probe()` throws away) + `brandIdFrom()` extracted from `fetchBrand()`. |
| `app/Services/Platforms/WooCommerceScraper.php` | `brandIdFor()` extracted from `fetchBrand()` **and** `brandFromClient()` (identical expression, two copies today). |
| `app/Services/Platforms/SquarespaceScraper.php` | `idFromOrigin()` private → public. |

### Jobs
| File | Why |
|---|---|
| `app/Jobs/Platforms/ShopBrandConnectJob.php` | **new.** Brand-keyed uniqueness, `FetchBudget`, write-time `assertPlatformAvailable`, single locked write, explicit `IntegrationConnectionCacheRefresher::refresh()`. |

### HTTP layer
| File | Why |
|---|---|
| `app/Http/Controllers/Api/Platforms/ShopController.php` | `use DefersBespokeConnect`; split `addBrand()`; new `connectStatus()` action; `setProducts()` fetch-outside-lock; `brandProfileFor()` moves out to the profiler. |
| `app/Http/Resources/Platforms/ShopBrandResource.php` | Emit `connectStatus` / `connectError` **only when non-null** (dark-merge byte-identity). |
| `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php` | Skip `connect_status = 'pending'` brands on the public wire. |
| `routes/api/platforms.php` | One new route inside the existing `foreach (['shop','shopify'])` group. |

### Tests
`tests/Pest.php` (SQLite mirror gains the two columns) · `tests/Feature/Platforms/ShopAsyncConnectTest.php` (**new**) ·
`tests/Unit/Platforms/ShopBrandIdentityTest.php` (**new**) · `tests/Unit/Jobs/ShopBrandConnectJobTest.php` (**new**) ·
`tests/Feature/Platforms/ShopSelectionLockTest.php` (**new**, unit 5) · `tests/Feature/Database/CheckConstraintsTest.php` ·
`tests/Feature/Database/ConstraintVocabularyLockstepTest.php` · `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` (only if the frozen shop shape needs a note).

### Docs
`docs/frontend-contracts/2026-07-23-platform-connect-async.md` (replace the three Shop rows in
"What did NOT change" with a real §8) · `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §5 (tick W9) ·
the ledger.

### ⚠ Shared with the sibling Phase-3 branch `audit-fix/connect-async-impl-2026-07-24`

True sibling blast radius (merge-base `a49ba107`, 15 files) — verified by
`git diff --name-only $(git merge-base origin/development audit-fix/connect-async-impl-2026-07-24) audit-fix/connect-async-impl-2026-07-24`:

| File | Overlap | Predicted conflict |
|---|---|---|
| `app/Http/Controllers/Api/Platforms/Concerns/DefersBespokeConnect.php` | **identical blob** — `e49f302b` is a cherry-pick of `904d51c7` | **None.** Merges as a no-op whichever lands first. |
| `routes/api/platforms.php` | Sibling adds 6 lines in the **apple** group (`:~101+`); W9 adds 1 line in the **shop** `foreach` (`:82-98`) | **Low.** Non-adjacent hunks; git resolves. Only conflicts if a third branch reflows the file. |
| `tests/Feature/Platforms/GoldenMaster/IntegrationContractGoldenMasterTest.php` | Sibling changed 8 lines for Apple; W9 touches the shop block at `:469-490` **only if** the frozen shape changes | **Low**, and avoidable — see §3(d): `connectStatus` is emitted conditionally, so the frozen settled-brand shape is unchanged and this file may not need touching at all. |
| `app/Jobs/Platforms/ConnectFetchJob.php` | Sibling adds `FetchBudget` + write-time availability re-check | **None** — W9 must **not** touch this file (PREMISE-STALE 1: Shop gets its own job). |
| `app/Models/Core/Site/IntegrationConnection.php` | Sibling adds `scopeDueForRefresh` pending-exclusion + `scopeStrandedPending` | **None** — W9 must not touch it. See §7 R4 for the consequence. |
| `app/Console/Commands/CheckPlatformRefreshBacklogCommand.php` | Sibling folds stranded pending rows into the backlog alarm | **None**, deliberately — W9 does **not** add a Shop equivalent here. Deferred to a post-merge follow-up (§7 R4). |

**Nothing else overlaps.** In particular `PublicIntegrationConnectionResource.php`,
`ShopController.php`, `ShopBrandResource.php` and every scraper are untouched by the sibling.

---

## 3. Resolved sub-questions

### (a) Where does `brand_id` come from at 202 time? — **RESOLVED, per provider**

`brandProfileFor()` (`ShopController.php:658-673`) returns the whole profile including `id`. The
question is how much of it must run synchronously. Answer, read from each scraper's `fetchBrand()`:

| Provider | `id` expression | Source | HTTP beyond detection? |
|---|---|---|---|
| **bigcartel** | `"bigcartel-{$account}"` | `BigCartelScraper::fetchStore():42`, already on `$detected['store']` from `ShopProviderDetector:80` | **0** |
| **generic** | host slug `preg_replace('/[^A-Za-z0-9]+/','-', strtolower(host))` | `GenericShopScraper:72`, already on `$detected['page']['brand']` from `ShopProviderDetector:109-111` | **0** |
| **client-assisted (`storeApi`)** | same host-slug expression | `WooCommerceScraper::brandFromClient():254`, already on `$detected['clientBrand']` (`ShopController:707`) | **0** |
| **woocommerce** | same host-slug expression, **pure string** | `WooCommerceScraper::fetchBrand():82` | **0** (derivable without the fetch) |
| **squarespace** | `idFromOrigin()` = strip `www.`, `.`→`-` | `SquarespaceScraper::fetchBrand():64` → `:131-136`, **pure string** | **0** (derivable without the fetch) |
| **shopify** | `meta.json`'s `id`, else host slug | `ShopifyScraper::fetchBrand():38-41` | **0 — but only if the detector stops discarding it** |

**The one non-trivial case is Shopify, and it is solvable at zero cost.**
`ShopifyScraper::probe():20-25` already does `GET <origin>/meta.json` during detection and throws the
decoded body away, returning only `bool`. Adding `probeMeta(string $origin): ?array` (returning the
decoded array **only** when `isset($meta['id']) || isset($meta['name']) || isset($meta['myshopify_domain'])`,
so `probe()`'s truthiness is preserved exactly) and carrying it on the detected array as `'meta'`
makes the shop id — **and the shop currency**, `fetchBrand():52-53` — truthful at 202 time with **no
extra HTTP call**.

**Nothing is unresolvable. No provider needs a sentinel.**

**Divergence hazard — the reason this is a service, not an inline `match`.** Woo and Squarespace use
*different* slug expressions: for `https://www.example.com`, Woo yields `www-example-com`
(`WooCommerceScraper:82`) and Squarespace yields `example-com` (`SquarespaceScraper:135`). A
reimplementation in the controller that got one of them wrong would key the pending row on a
different `brand_id` than a later `fetchBrand()` — silently producing a duplicate row.
**Mitigation (binding on the implementer):** extract each expression into a scraper method that
`fetchBrand()` itself then calls, and have `ShopBrandIdentity` call the same method. One expression,
two callers, no copy. `ShopBrandIdentityTest` asserts equality against the real `fetchBrand()` output
under `Http::fake()` for all six paths.

**Consequence the design sketch did not anticipate — half the providers defer nothing.**
For bigcartel / generic / client-assisted, `brandProfileFor()` performs **zero** HTTP
(`ShopController:662-670`): the profile is already in the in-memory probe result. Deferring it would
mean serialising a whole `page` payload (including every scraped product) into a job to accomplish
nothing. **Therefore those three providers write the complete row synchronously and return the
current `200`.** Only shopify / woocommerce / squarespace take the pending path. See §7 R1 — this is
the plan's most consequential trade and Josh should read it.

**What is actually deferred**, per provider, after the meta/pageJson carry-forward:

| Provider | Deferred work | HTTP calls moved off the request |
|---|---|---|
| shopify | `fetchBrand()` → `meta.json` re-read + `GET /` homepage (name/favicon/logo) | 2 |
| woocommerce | `fetchBrand()` → `GET /wp-json` + `GET /` homepage | 2 |
| squarespace | `fetchBrand()` → `pageJson(productsUrl)` (name/currency/logo) | 1 |
| bigcartel / generic / client | — | **0** |

Each ride is bounded by `config('partna.http_fetch.timeout_seconds')` = 8 s
(`config/partna.php:1281`) inside W1's 20 s `connect_budget_seconds` (`:1307`).

### (b) The 5-brand cap — **does not move, does not change**

`ShopController.php:171-173` gates on `! $existing`, and `$existing` is looked up by `$id`, which is
assigned at `:163` — **before** `withConnectionLock()` opens at `:165`. Under path (c) detection stays
synchronous, so `$id` is still truthfully known at `:163`. The cap therefore keeps its exact current
position and predicate.

Proof it is still "ahead of the 202": under `DefersBespokeConnect`'s constraint (`:58-63`) the
dispatch must happen **after** the lock closure returns, so the shape becomes
`GenericPlatformController:176-192`'s idiom — the closure returns a sentinel, and a `null` sentinel
means the closure already produced a terminal response (the cap's `422` or `withConnectionLock`'s own
`423`), which is returned unchanged with nothing dispatched. This preserves the contract line already
published for the other platforms: *"The 5-account cap still returns a synchronous 422 … It is
checked before the 202"* (`docs/frontend-contracts/…:133-134`).

### (c) `updateOrCreate` semantics — **exact write specified**

Today's `updateOrCreate` (`:182-200`) always writes `name`/`currency`/`favicon`/`logo` from the freshly
fetched profile. Under path (c) the pending branch has none of them. Writing them as `null` would
blank a settled brand's profile on re-add. The write splits in two:

```
Attributes (unchanged):  ['connection_id' => $connection->id, 'brand_id' => $id]

Values, ALWAYS written (all truthful at 202 time):
    provider, url (= $detected['origin']), source_url (= $detected['sourceUrl']),
    discount_code (existing preserve logic, :175-178, unchanged),
    fetch_mode, is_individual => false, position (:179-180, unchanged)

Values, written ONLY on the SYNCHRONOUS branch (bigcartel/generic/client, or shop not deferred):
    name, currency, favicon, logo        ← from the in-hand profile
    connect_status => null, connect_error => null   ← settle/clear any prior failure

Values, written ONLY on the DEFERRED branch (shopify/woo/squarespace + flag on):
    connect_status => 'pending', connect_error => null
    name / currency / favicon / logo  ← OMITTED FROM THE ARRAY ENTIRELY
```

Omitting the four keys (rather than passing `null`) is what makes a re-add of a **settled** brand
non-destructive: `updateOrCreate` only writes the keys present, so an existing row keeps its name,
currency, favicon and logo for the whole pending window and the job overwrites them when it lands.
Products, `position`, `selection_mode`, `link_mode`, `referral_query` and `style_analysis` are already
untouched by this write today and stay untouched.

Currency caveat, closed: Shopify's currency is available synchronously from the carried `meta`
(§3a), so for Shopify `currency` is written on **both** branches. Woo's `fetchBrand()` returns
`currency => null` unconditionally (`:84`) so nothing is lost. Only Squarespace's currency is genuinely
deferred. This matters because `ShopCatalog::providerProducts()` passes `$brand['currency']` as the
Shopify per-product fallback (`ShopCatalog:62`) — with the carry-forward, a picker GET during the
pending window is not degraded for Shopify.

### (d) What `ShopBrandResource` emits for a pending brand — **and the public render verified**

**Dashboard.** `ShopBrandResource::toArray()` (`:25-43`) already emits `name`/`currency`/`favicon`/`logo`
with `?? null`, so a pending brand renders as a named-less card with `products: []`. Two keys are
**added conditionally** — present only when the column is non-null, mirroring `toBrandArray()`'s
existing optional-key convention for `fetchMode` / `individual` (`ShopBrand:117-122`):

```
connectStatus: 'pending' | 'failed'      (absent when settled)
connectError:  "<displayable sentence>"  (absent unless status is 'failed')
```

Conditional emission is **load-bearing for the dark-merge proof**: a settled brand's body — and
therefore every `GET /brands`, `PATCH /brands/{id}`, `PUT …/selection` and non-deferred `addBrand`
body — stays byte-identical, and `IntegrationContractGoldenMasterTest:475-490` keeps passing untouched.

**Public sitepage — verified, two distinct facts:**

1. **A pending brand cannot, by itself, cause a Shop page to appear.**
   `SitepageDataResolverService::presentPageIds()` documents and enforces that `'shop'` additionally
   requires a chosen `ShopProduct` (`:163-165`), and `ShopPagePresenceTest`'s header states the same
   fix explicitly. A pending brand has zero products. **No change needed.**
2. **But if the user *already* has a brand with products, the Shop page is present and the pending
   brand joins the public brand map.** `PublicIntegrationConnectionResource::filterPayload()` maps
   **every** `shopBrands` row (`:211-221`) with no products/name filter, so a nameless, logo-less,
   empty brand would ship on the CDN-cached public wire for the pending window — and permanently if
   the connect fails.

   → **This is a required unit.** Add `->reject(fn ($b) => $b->connect_status === 'pending')` inside
   `filterPayload()`'s shop branch. Chosen there rather than on the eager load
   (`PublicIntegrationController:88`) because the Resource is the single public consumer and the guard
   then cannot be bypassed by a caller that loads the relation differently.

   **`'failed'` is deliberately NOT filtered.** A failed brand's content is *identical* to what
   today's synchronous path already produces when the homepage fetch fails —
   `ShopifyScraper::fetchBrand():47` degrades `$html` to `''` and `metaContent()` yields `null` — so a
   null-named brand on the public wire is pre-existing behaviour, not something W9 introduces.
   Filtering it would be a silent behaviour change dressed up as a fix.

`connectStatus` never reaches the public wire regardless: `SHOP_BRAND_ALLOWLIST` (`:163`) is an
`array_intersect_key` allowlist and the key is not in it.

`ShopPayload` needs no change — its docblock (`:17-21`) commits to preserving every brand key
verbatim, and `primaryWithProducts():66-75` selects on `! empty($brand['products'])`, so a pending
brand is already invisible to the COMPAT `/selection` endpoint.

### (e) `GET /brands/{id}/products` during the pending window — **succeeds, fully**

Trace: `brandProducts():361` → `brandMap():727-738` → `ShopBrand::toBrandArray():86-125` →
`ShopCatalog::providerProducts():40-63`.

`providerProducts()` dispatches on exactly four brand keys — `fetchMode`, `provider`, `url`,
`sourceUrl` (plus `currency` as a Shopify fallback). **Path (c) writes all four truthfully at 202
time** (§3c), so:

- `isset($map[$id])` is true → **no 404**.
- Shopify → `fetchProducts($brand['url'], $brand['currency'])` — works; currency truthful via the meta carry-forward.
- WooCommerce → `fetchProducts($brand['url'])` — works.
- Squarespace → `fetchProducts($brand['sourceUrl'])` — works; `source_url` is the discovered
  products-collection URL from `ShopProviderDetector:103-104`.
- BigCartel / generic / client — synchronous branch, never pending.

This is the constraint the brief called out as the reason a stub is not acceptable
(`ShopController:54-55`), and path (c) satisfies it by construction rather than by special-casing.
The catalog cache is additionally pre-warmed at 202 time for generic/client (`:204-206`, unchanged),
so the follow-on GET is instant on exactly the paths where it was instant before.

**Correction to the design sketch:** the sketch's *"warm the picker catalog when the detector already
returned products"* bullet is a **no-op in the job**. `$detectedProducts` is non-null only for the
generic and client paths (`ShopController:670`, `:663`) — both of which are synchronous under this
plan. The warm belongs entirely to the synchronous branch, where it already lives, and the job must
not carry it.

### (f) `setProducts()` fetch-outside / write-inside — **restructure specified**

Today (`:380-436`) everything runs inside `withConnectionLock`'s 10 s `Cache::lock(…, 10)->block(5, …)`
(`ManagesIntegrationConnection:284-291`), including the up-to-20 s
`$this->budget->open(… providerProducts …)` at `:396-398` and the `DB::connection('pgsql')->transaction()`
delete+reinsert at `:410-420`. The lock's 10 s TTL can therefore expire while the transaction is open —
a second writer can acquire it and interleave.

Restructure:

```
1. OUTSIDE the lock:
     $connection = $this->connectionFor($user);
     $brand = ShopBrand::where(connection_id, brand_id)->with('products')->first();
     if (! $brand) return $this->error('Brand not found.', 404);          // unchanged body/status
     $catalog = Cache::get($this->catalogKey($id))
                ?? $this->budget->open($seconds, fn () => $this->providerProducts($brand->toBrandArray()));

2. INSIDE withConnectionLock():
     re-read $brand (a concurrent removeBrand/forget may have deleted it) → 404 if gone
     build $selected from $catalog                       // pure array work
     DB transaction: delete + reinsert ShopProduct rows  // unchanged
     selection_mode flip, writeConnection(), refresher->refresh(), Cache::forget()
```

Two properties to preserve, both verified:

- **Error behaviour is byte-identical.** `providerProducts()` can throw `HttpException` (502) from a
  scraper; `Cache::lock()->block()`'s closure form releases on throw
  (`ManagesIntegrationConnection:271-272` docblock), so the 502 propagates identically whether the
  throw happens inside or outside. Nothing observable changes.
- **The pre-lock read is duplicated deliberately.** It exists only to produce the 404 and to give
  `providerProducts()` a brand shape; the in-lock re-read is the authoritative one. Cheap, and it
  closes the delete-between-read-and-write race that a single pre-lock read would open.

This unit is **independent of the async work** and can land before or after it.

### (g) Failure semantics — **specified; no cleanup owed**

**What writes `connect_status = 'failed'`:**

| Trigger | `connect_error` |
|---|---|
| `assertPlatformAvailable` re-check fails at job write time (staff disabled `integration.shop` mid-flight) | `"This integration is currently unavailable."` |
| Lock timeout on the job's write (`block(5)` exhausted) | `"We couldn't save your connection just then — please try again."` (`DefersBespokeConnect::STALE_CONNECT_ERROR`, `:38`) |
| Unhandled throwable → `failed()` callback | `"We could not load that account. Please try again."` (`DefersBespokeConnect::UNKNOWN_CONNECT_ERROR`, `:40`) |

Reusing the two shared sentences (rather than inventing Shop-specific ones) is deliberate: the
published contract already tells the frontend those exact two strings are infrastructure, not vendor
misses (`docs/frontend-contracts/…:252-255`).

**Note the profile fetch itself essentially cannot fail.** `ShopifyScraper::fetchBrand()`,
`WooCommerceScraper::fetchBrand()` and `SquarespaceScraper::fetchBrand()` all use the null-returning
`json()` / `tryFetch()` helpers and degrade to `name => null` rather than throwing
(`ShopifyScraper:43-47` is the explicit WS-B1 guard). So the overwhelmingly common terminal state is
**settled with a partially-null profile**, exactly as today. `'failed'` is reserved for *our*
failures, which is what makes the two shared sentences the right ones.

**Stale-pending backstop — ported from `GenericPlatformController::connectStatus():236-253`, NOT from
Instagram.** The poll action returns a **synthetic** `{"status":"failed","error":STALE_CONNECT_ERROR}`
when `connect_status === 'pending'` and `updated_at < now()->subMinutes(5)`. Synthetic means **no
write** — a merely-slow (not dead) worker can still land its real settle afterwards and the next poll
reports `ready`. Five minutes comfortably exceeds the job's 45 s timeout plus its two backoffs
(5 s, 20 s), matching `ConnectFetchJob:51-60`. A `NULL` `updated_at` is treated as still-in-flight,
never stranded (`ShopBrand` has `updated_at` in `$casts:54` and `updateOrCreate` always sets it).

**Is a failed pending row cleaned up? No — deliberately.** Unlike the other six platforms, a
Shop `'failed'` row is **not** a broken row: `brand_id`, `provider`, `url`, `source_url`, `fetch_mode`
and `position` are all truthful, `GET /brands/{id}/products` works, and the user can select products
and publish. Only the display profile is missing — the same state today's synchronous path reaches
when a homepage fetch fails. Deleting it would destroy a usable brand and its position. **Retry is
re-POSTing the same URL**, which `updateOrCreate` resolves onto the same row (§3c) and re-runs.
No reaper, no cron, no orphan sweep.

### (h) Poll route and shape — **house style matched**

```
GET /api/platforms/shop/brands/{id}/connect/status
GET /api/platforms/shopify/brands/{id}/connect/status     ← the legacy alias, same foreach
```

Registered inside the existing `foreach (['shop','shopify'] as $shopAlias)` group
(`routes/api/platforms.php:78-99`) with the same `->where('id', '[A-Za-z0-9._-]+')` constraint every
other `brands/{id}` route uses (`:84-88`). Every real `brand_id` matches it: a Shopify numeric shop
id, `bigcartel-<account>`, and both host-slug forms are `[A-Za-z0-9-]`-only.

**No `?account=` query param.** Shop's sub-resource is path-addressed, so `perAccount` in
`DefersBespokeConnect` is irrelevant here — Shop uses **its own** poll action (NEW BLOCKER 5), reusing
only `shouldDeferConnect()` from the trait.

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"status":"pending"}` | Job still running — keep polling. |
| `200` | `{"status":"ready","id":"<brandId>","brand":{…}}` | Done. `brand` is the same object `addBrand` returns synchronously today. |
| `200` | `{"status":"failed","error":"<sentence>","brand":{…}}` | Terminal. Show `error`; the brand is present and usable. |
| `404` | `{"message":"Brand not found."}` | Not found, or not the caller's. Never 403 — matches `updateBrand():237` / `brandProducts():366`. |

Two deliberate divergences from the six, both to be written into the contract:

- **`brand`, not `connection`.** The six poll a connection row; Shop polls a *sub-resource*. `GET
  /brands` returns `{brands:[…]}` and `addBrand` returns a bare brand object, so `brand` is the shape
  the Shop contract already speaks.
- **`failed` carries `brand`.** For the six, `failed` means there is nothing to render. For Shop the
  brand is fully usable (§3g), so withholding it would force the client into a needless refetch.

**202 body** — the full `ShopBrandResource` object plus the envelope, envelope-wins spread order
(`[...$brandResource, ...$body]`, matching `DefersBespokeConnect:99` and its rationale at `:94-98`):

```json
{
  "id": "<brandId>", "provider": "shopify", "url": "https://store.example.com",
  "name": null, "currency": "AUD", "favicon": null, "logo": null,
  "discountCode": "", "selectionMode": "manual", "linkMode": "product",
  "referralQuery": "", "individual": false, "products": [],
  "connectStatus": "pending",
  "status": "pending",
  "statusUrl": "https://api.partna.au/api/platforms/shop/brands/<brandId>/connect/status"
}
```

Shop **deliberately carries the full partial**, where the six carry only an `identify()` subset. The
reason the six strip nulls (`GenericPlatformController:206-212`: explicit nulls imply "confirmed
absent") does not apply — `ShopBrandResource` emits those keys as `null` unconditionally, so `GET
/brands` shows them as null during the pending window regardless; stripping them from the 202 only
would create an inconsistency between two views of the same row. And unlike the six, this stub **is**
a renderable card: it has a URL, a provider, and a working product picker.

---

## 4. Units

Sequential. The suite must be green after each. Sizes per `fix-flow.md` §1a.

---

### Unit 1 — Migration + model + SQLite mirror — **S** — 🚩 blocker gate (DB schema)

**Scope.** The two columns and the CHECK, plus everything that must move in lockstep. No behaviour
change: nothing reads or writes the columns yet.

**Files.**
- `supabase/migrations/20260724150000_shop_brands_connect_status.sql` (new — full SQL in §6)
- `app/Models/Core/Site/ShopBrand.php` — add `'connect_status'`, `'connect_error'` to `$fillable`
  (after `'fetch_mode'`); add
  `public const CONNECT_STATUSES = ['pending', 'failed'];` as the app-side lockstep source of truth.
- `tests/Pest.php` (~`:625-646`) — add `connect_status TEXT NULL, connect_error TEXT NULL` to the
  `site.shop_brands` mirror. **Without this every shop test fails on an unknown column.**
- `tests/Feature/Database/CheckConstraintsTest.php` — add
  `assertCheckConstraintExists('site','shop_brands','shop_brands_connect_status_check')` in the
  existing `── site.shop_brands` block (`:247-261`); Postgres-only, skips on SQLite.
- `tests/Feature/Database/ConstraintVocabularyLockstepTest.php` — add a case in the existing
  `site.shop_brands` block (`:209-235`) comparing `lockstepExtractInList($sql,'connect_status')`
  against `ShopBrand::CONNECT_STATUSES` and a hardcoded `['pending','failed']`.

**Tests it must add.** The two Database cases above, plus one content-proxy Feature assertion: a row
written with `connect_status => 'pending'` reads back `'pending'`, and a row with no status reads
back `null` (proves the column is nullable-with-no-default under both engines).

**Acceptance.** `scripts/guard-no-unsafe-migrations.php` passes (Checks 3 and 8 in particular:
`NOT VALID` present, `VALIDATE` in a separate transaction). Full suite green. No production code reads
the columns yet.

---

### Unit 2 — Synchronous brand-identity seam (behaviour-neutral refactor) — **M**

**Scope.** Make `brand_id` derivable without the profile fetch, with **one** expression per provider
shared between the sync derivation and `fetchBrand()`. `addBrand()` stays fully synchronous and its
response stays byte-identical — this unit only moves code.

**Files.**
- `app/Services/Platforms/ShopifyScraper.php` — extract `brandIdFrom(?array $meta, string $origin): string`
  from `fetchBrand():38-41` and have `fetchBrand()` call it; add `probeMeta(string $origin): ?array`
  returning the decoded meta **only** when `isset($meta['id']) || isset($meta['name']) || isset($meta['myshopify_domain'])`;
  reimplement `probe()` as `probeMeta($origin) !== null` so its semantics are provably unchanged.
- `app/Services/Platforms/WooCommerceScraper.php` — extract `brandIdFor(string $origin): string` from
  `fetchBrand():82`; call it from `fetchBrand()` **and** from `brandFromClient():254` (identical
  expression, two copies today).
- `app/Services/Platforms/SquarespaceScraper.php` — `idFromOrigin()` `private` → `public`.
- `app/Services/Platforms/ShopProviderDetector.php` — shopify branch (`:93-95`) calls `probeMeta()` and
  puts `'meta' => $meta` on the detected array; every other branch adds `'meta' => null` so the shape
  is uniform. Update the `@return` docblock (`:47-49`, `:68`).
- `app/Services/Platforms/ShopBrandIdentity.php` (**new**) — `for(array $detected): string`, a `match`
  on `provider` reading `$detected['store']['id']`, `$detected['page']['brand']['id']`,
  `$detected['clientBrand']['id']`, or calling the three scraper methods above.
- `app/Services/Platforms/ShopBrandProfiler.php` (**new**) — `forDetected(array $detected): array`
  (today's `brandProfileFor()` body, moved verbatim) and `forRow(ShopBrand $brand): array` (shopify/woo
  → `fetchBrand($brand->url)`, squarespace → `fetchBrand($brand->source_url ?? $brand->url)`; any other
  provider is unreachable and throws `LogicException`).
- `app/Http/Controllers/Api/Platforms/ShopController.php` — delete `brandProfileFor():658-673`; inject
  the two new services; `addBrand()` calls `$this->profiler->forDetected($detected)`.

**Tests.**
- `tests/Unit/Platforms/ShopBrandIdentityTest.php` (**new**) — **the load-bearing test.** For each of
  the six paths, under `Http::fake()`, assert `ShopBrandIdentity::for($detected) === $profiler->forDetected($detected)[0]['id']`.
  Must include the `https://www.example.com` case that distinguishes Woo's `www-example-com` from
  Squarespace's `example-com`, and the Shopify case where `meta.json` has **no** `id` (host-slug
  fallback).
- A `probe()`-parity case: a `meta.json` that decodes to `{}` must leave `probe()` false and
  `probeMeta()` null.

**Acceptance.** Zero behaviour change. `ShopRelationalStorageTest`, `ShopUrlValidationTest`,
`ShopPayloadFeatureTest`, `ShopGlobalSettingsTest`, `ShopSyncFailureObservabilityTest`,
`IntegrationContractGoldenMasterTest` all pass **unmodified**. `addBrand`'s body byte-identical.

---

### Unit 3 — `ShopBrandConnectJob` (inert) — **M**

**Scope.** The job, dispatched by nothing yet.

**File.** `app/Jobs/Platforms/ShopBrandConnectJob.php` (new).

```
implements ShouldBeUnique, ShouldQueue
__construct(public readonly string $brandRowId)   // the ShopBrand uuid PK — no bulky payloads
onQueue(config('partna.queues.platform_connect'))
tries = 3 · backoff = [5, 20] · timeout = 45 · maxExceptions = 2 · middleware() = []
uniqueId()  = "shop-brand:{$this->brandRowId}"    ← NEW BLOCKER 4 fix
uniqueFor   = 120                                  ← matches ConnectFetchJob:65
```

`handle(ShopBrandProfiler $profiler, IntegrationConnectionCacheRefresher $refresher, FetchBudget $budget)`:

1. `ShopBrand::with('connection')->find($brandRowId)`; `null` → return (user disconnected while queued).
2. `$connection === null` (soft-deleted parent) → return.
3. **Fetch outside the lock**, inside `$budget->open(config('partna.http_fetch.connect_budget_seconds', 20), …)`
   — same shape the sibling branch applied to `ConnectFetchJob` (`310d6478`), independently derived
   here since W9 must not touch that file. `FetchBudget` is a *scoped* binding and must not nest
   (`FetchBudget:45-50`); nothing else in this path opens one.
4. **Write-time availability re-check**, before the write: `$user = $connection->user;`
   `if ($user === null || ! FeatureAvailability::for($user)->allows('integration.shop'))` →
   terminal `'failed'` + the unavailable sentence, return. (The trait's `assertPlatformAvailable()`
   is `private` and `abort(503)`-shaped — meaningless in a worker — so the underlying rule is called
   directly, exactly as `ConnectFetchJob` does on the sibling branch.)
5. **Single locked write** on `CacheKeyGenerator::platformConnectionLock('shop', $connection->user_id)`,
   `Cache::lock($key, 10)->block(5, …)` — the *same* key `withConnectionLock()` uses
   (`ManagesIntegrationConnection:284`), so the job can never race a dashboard brand edit. Writes
   `name`, `currency`, `favicon`, `logo`, `connect_status => null`, `connect_error => null`.
   `LockTimeoutException` → terminal `'failed'` + `STALE_CONNECT_ERROR` + `report()` +
   `Log::warning('shop.brand_connect_job.lock_timeout', …)`, **never** `release()` (verbatim the
   reasoning at `ConnectFetchJob:179-214` — on the sync driver `release()` is a silent no-op).
6. **Explicit** `$refresher->refresh($connection)` after the write. The observer gates on
   `wasChanged('payload')` and Shop's payload is the frozen `MARKER` (`ShopController:81`), and it
   watches `IntegrationConnection`, never `ShopBrand` — so nothing else will ever purge this.
7. `failed(Throwable $e)` → `report()` + `Log::error` + terminal `'failed'` + `UNKNOWN_CONNECT_ERROR`.

Terminal writes use `forceFill(...)->saveQuietly()` (parity with `ConnectFetchJob:243-250`): no content
changed, so no purge is owed.

**Tests.** `tests/Unit/Jobs/ShopBrandConnectJobTest.php` (**new**):
- settles a pending row: profile written, `connect_status` → `null`.
- **does not recompute `brand_id`** — a profiler returning a *different* `id` must leave `brand_id`
  unchanged and create no second row (guards the Shopify meta-drift case).
- deleted brand row / soft-deleted connection → no-op, no throw.
- staff-disabled `integration.shop` → `'failed'`, unavailable sentence, **profile not written**.
- lock held by another writer → `'failed'` + `STALE_CONNECT_ERROR`, no infinite pending.
- `failed()` → `'failed'` + `UNKNOWN_CONNECT_ERROR`.
- `Bus::assertDispatched(CloudflareCachePurgeJob::class)` on the success path (proves the explicit
  refresher call — `IntegrationConnectionCacheRefresher:36`).
- `uniqueId()` differs for two brands under one connection (the NEW BLOCKER 4 regression).

**Acceptance.** Nothing dispatches the job; suite green.

---

### Unit 4 — Deferred `addBrand()` + poll endpoint + resource keys — **L** — the core

**Scope.** Wire the flag, split the provider paths, add the poll action and route, and add the two
conditional resource keys and the public filter.

**Files.**
- `ShopController.php`:
  - `use DefersBespokeConnect;` (from `e49f302b`) — **only** `shouldDeferConnect('shop')` is used.
    `deferredConnectResponse()` and `bespokeConnectStatus()` are **not** usable (blockers 4 and 5) and
    must not be forced to fit.
  - `addBrand()` — derive `$id` via `ShopBrandIdentity`; decide
    `$deferred = $this->shouldDeferConnect('shop') && in_array($detected['provider'], self::DEFERRABLE_PROVIDERS, true)`
    where `DEFERRABLE_PROVIDERS = [shopify, woocommerce, squarespace]` **and** `$detected['clientBrand']`
    is absent; on the sync path call the profiler as today; on the deferred path skip it. Restructure
    the lock closure to return a sentinel so the dispatch happens **after** the lock releases
    (`DefersBespokeConnect:58-63`), returning the closure's own terminal response when the sentinel is
    null (`GenericPlatformController:176-192` idiom). Write per §3c. Build the 202 per §3h.
  - `connectStatus(Request $request, string $id): JsonResponse` (**new**) — resolve the brand via the
    existing `connectionFor()` + `where('brand_id', $id)` pattern; 404 `'Brand not found.'`; the four
    states of §3h including the 5-minute synthetic staleness check.
- `routes/api/platforms.php` — one line inside the existing `foreach` group:
  `Route::get('/brands/{id}/connect/status', [ShopController::class, 'connectStatus'])->where('id', '[A-Za-z0-9._-]+');`
- `ShopBrand::toBrandArray()` — append `connectStatus` / `connectError` **only when non-null**,
  alongside the existing `fetchMode` / `individual` optional keys (`:117-122`).
- `ShopBrandResource::toArray()` — same conditional pass-through.
- `PublicIntegrationConnectionResource::filterPayload()` — reject `connect_status === 'pending'` in the
  shop branch (§3d).

**Tests.** `tests/Feature/Platforms/ShopAsyncConnectTest.php` (**new**) — see §5.

**Acceptance.**
- **Dark-merge proof:** with `partna.connect.deferred` empty, `addBrand()` returns its current status
  code and a body asserted **key-for-key and value-for-value** against the pre-change shape, and
  `Bus::assertNothingDispatched()` (or `Queue::assertNothingPushed()`) holds.
- With `shop` deferred, Shopify/Woo/Squarespace return `202`; BigCartel/generic/client still return
  `200` with the complete brand.
- `GET /brands/{id}/products` succeeds during the pending window.
- Full suite green, `IntegrationContractGoldenMasterTest` **unmodified**.

---

### Unit 5 — `setProducts()` fetch-outside-lock — **S/M** (independent of units 2–4)

**Scope.** §3f exactly. No contract change.

**Files.** `ShopController::setProducts():380-436`.

**Tests.** `tests/Feature/Platforms/ShopSelectionLockTest.php` (**new**):
- **Structural proof:** the `ShopCatalog`/scraper mock, when invoked, attempts
  `Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', $user->id), 10)->get()` and records
  the result. It must **succeed** — proving the connection lock was not held during the vendor fetch.
  Under the old code this returns false. (Follow `FreshaConnectLockTest` / `InstagramControllerLockTest`
  for the established in-repo idiom.)
- Regression: a warm catalog still short-circuits the scrape; a cold catalog still scrapes; the
  response body is unchanged; a scraper `HttpException` still surfaces as it does today.
- A brand deleted between the pre-lock read and the locked write yields `404`, not a 500.

**Acceptance.** Suite green; `ShopRelationalStorageTest`'s `setProducts` cases pass unmodified.

---

### Unit 6 — Frontend contract + design-doc tick — **S** (docs only)

- `docs/frontend-contracts/2026-07-23-platform-connect-async.md`: replace the three Shop rows in
  "What did NOT change" (`:339-342`) with a real **§8 Shop** section in the house style of §1–§7 —
  the 202 body, the poll table, the 404 rule, and **an explicit callout that this endpoint's status
  code is provider-dependent** (§7 R1) so a client is never surprised by a `200`. Note that
  `GET /brands`, `GET /selection`, `PUT …/selection`, `POST /products` remain unchanged.
- `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` §5: tick W9, and correct §3.5's
  two-blocker framing to record the three verified findings (PREMISE-STALE 1, NEW BLOCKER 4, NEW
  BLOCKER 5) and the provider-split outcome.
- Ledger: realign the unit table.

---

### Unit 7 — Apply the migration to dev Supabase + realign — **S** (ops)

`db push` to dev is unsafe (repo/dev drift — see CLAUDE.md and the memory note), so apply the single
file surgically via Supabase MCP `apply_migration` or `psql -f`, then record it in
`supabase_migrations.schema_migrations`. Verify with `list_migrations`. **Dev only.** Then a
`fresh-reset.sh` from-zero sanity check locally, since the new file is CHECK-bearing.

---

### Process (not a unit)
Independent whole-branch review on **Opus 4.8** after Unit 6; one fix subagent for the complete
findings list. `COMPOSER_PROCESS_TIMEOUT=0 composer test` and `php artisan pint --dirty` before that
review — **never** alongside a running implementer. `ConnectResolverYoutubeTest` is a known load
flake. **Do not merge or push.**

---

## 5. Test plan

Mapped 1:1 against the brief's `## Tests`, plus everything (a)–(h) surfaced.
**⚠ = content-proxy assertion, standing in for a constraint SQLite does not enforce.**

| # | Assertion | From | Unit | File |
|---|---|---|---|---|
| T1 | **Dark-merge:** `shop` absent from `PARTNA_CONNECT_DEFERRED` → `addBrand()` returns its current status code, a body compared key-for-key to the frozen shape, and `Bus::assertNothingDispatched()` | brief | 4 | `ShopAsyncConnectTest` |
| T2 ⚠ | Pending row satisfies every `NOT NULL`: `brand_id`, `provider`, `is_individual`, `position`, `selection_mode`, `link_mode`, `referral_query` all non-null **with the right values** (SQLite's mirror at `tests/Pest.php:625-646` makes every column nullable, so this asserts content, not the constraint) | brief | 4 | `ShopAsyncConnectTest` |
| T3 ⚠ | Pending row satisfies every `CHECK`: `selection_mode ∈ {manual,latest}`, `link_mode ∈ {product,checkout}`, `connect_status ∈ {pending,failed,NULL}` (SQLite enforces no CHECK) | brief | 1, 4 | `ShopAsyncConnectTest` + `CheckConstraintsTest` (Postgres-only) |
| T4 | `GET /brands/{id}/products` returns `200` with products during the pending window, for each deferrable provider | brief + (e) | 4 | `ShopAsyncConnectTest` |
| T5 | Status transition reaches a terminal state: `pending` → job → poll reports `ready` with the full brand | brief | 3, 4 | `ShopBrandConnectJobTest`, `ShopAsyncConnectTest` |
| T6 | `IntegrationConnectionCacheRefresher::refresh()` fires on the job's content write (`Bus::assertDispatched(CloudflareCachePurgeJob::class)`) | brief | 3 | `ShopBrandConnectJobTest` |
| T7 ⚠ | Duplicate paste of the same store URL updates the **one** row rather than creating a second (`UNIQUE (connection_id, brand_id)` is absent from the SQLite mirror, so this proves the `updateOrCreate` key instead — assert `ShopBrand::count() === 1`) | brief | 4 | `ShopAsyncConnectTest` |
| T8 | **Per-provider id parity** — `ShopBrandIdentity::for($detected)` equals `ShopBrandProfiler::forDetected($detected)[0]['id']` for all six paths, incl. `www.` (Woo vs Squarespace divergence) and Shopify-without-`meta.id` | (a) | 2 | `ShopBrandIdentityTest` |
| T9 | `probe()` semantics unchanged by `probeMeta()`: `{}` meta → `probe()` false | (a) | 2 | `ShopBrandIdentityTest` |
| T10 | 6th store still `422 "You can connect up to 5 stores."`, synchronously, **nothing dispatched** | (b) | 4 | `ShopAsyncConnectTest` |
| T11 | Re-adding an already-**settled** brand while deferred does **not** blank `name`/`currency`/`favicon`/`logo`, and preserves `position`, `discount_code`, `selection_mode` and its `ShopProduct` rows | (c) | 4 | `ShopAsyncConnectTest` |
| T12 | Settled brand's `GET /brands` body has **no** `connectStatus`/`connectError` key (dark-merge byte-identity of the resource) | (d) | 4 | `ShopAsyncConnectTest` |
| T13 | Public payload (`PublicIntegrationConnectionResource`) **omits** a `pending` brand and **includes** a `failed` one; `connectStatus` never appears on the public wire | (d) | 4 | `ShopAsyncConnectTest` |
| T14 | `presentPageIds()` still excludes Shop when the only brand is pending (zero products) — regression guard on `ShopPagePresenceTest`'s invariant | (d) | 4 | `ShopAsyncConnectTest` |
| T15 | `setProducts()`' vendor fetch runs with the connection lock **free** (structural lock-acquisition probe) | (f) | 5 | `ShopSelectionLockTest` |
| T16 | `setProducts()` regressions: warm-cache short-circuit, cold-cache scrape, unchanged body, `HttpException` still surfaces, brand-deleted-mid-flight → 404 | (f) | 5 | `ShopSelectionLockTest` |
| T17 | Staff-disabled `integration.shop` between 202 and job → `'failed'` + unavailable sentence, profile **not** written | (g) | 3 | `ShopBrandConnectJobTest` |
| T18 | Lock timeout in the job → `'failed'` + `STALE_CONNECT_ERROR`, never left pending | (g) | 3 | `ShopBrandConnectJobTest` |
| T19 | Stale-pending backstop: a row `pending` with `updated_at` 6 minutes old polls `failed` + `STALE_CONNECT_ERROR`, **and the DB row is not written** (synthetic) | (g) | 4 | `ShopAsyncConnectTest` |
| T20 | A `'failed'` brand is retained, still returns products, and re-POSTing its URL retries onto the same row | (g) | 4 | `ShopAsyncConnectTest` |
| T21 | Poll 404s (`'Brand not found.'`) for an unknown id and for another user's brand — never 403 | (h) | 4 | `ShopAsyncConnectTest` |
| T22 | Poll registered under **both** `/shop` and `/shopify` prefixes | (h) | 4 | `ShopAsyncConnectTest` |
| T23 | `uniqueId()` differs for two brands under one connection (NEW BLOCKER 4) | ledger | 3 | `ShopBrandConnectJobTest` |
| T24 | Job never recomputes `brand_id` — a profiler returning a different `id` leaves the row keyed as written and creates no second row | (a) | 3 | `ShopBrandConnectJobTest` |
| T25 | Non-deferrable providers (bigcartel, generic, client-assisted) return `200` with a **complete** brand even with `shop` deferred, and dispatch nothing | §7 R1 | 4 | `ShopAsyncConnectTest` |
| T26 ⚠ | `connect_status` column round-trips `'pending'` / `null`; new-column nullability proven by content | brief | 1 | `ShopRelationalStorageTest` — **not** `ShopAsyncConnectTest`. Unit 1 must not create the file Unit 4 owns, so this assertion lives with the existing relational-storage cases. |
| T27 | Lockstep: migration's `connect_status` `IN (…)` list == `ShopBrand::CONNECT_STATUSES` == `['pending','failed']` | house rule | 1 | `ConstraintVocabularyLockstepTest` |
| T28 | `shop_brands_connect_status_check` exists and is **validated** (Postgres-only; skipped on SQLite) | house rule | 1 | `CheckConstraintsTest` |

Six of the twenty-eight (T2, T3, T7, T26, and partially T13/T14) are content-proxies. Every write in
units 1, 3 and 4 must additionally be eyeballed against the DDL in
`supabase/migrations/20260704160000_shop_brands_products.sql:26-46` — a green SQLite run proves
nothing about `NOT NULL`, `CHECK` or `UNIQUE`.

---

## 6. Migration

`supabase/migrations/20260724150000_shop_brands_connect_status.sql`

No index is added — the poll reads by `(connection_id, brand_id)`, already covered by the table's
`UNIQUE (connection_id, brand_id)` (`20260704160000:36`), and any future stranded-pending sweep is a
seq scan over a tiny table. **Therefore no `CONCURRENTLY` file is needed** and CONVENTIONS.md §1 /
guard Check 6 do not apply.

```sql
-- W9 — per-brand deferred-connect state for site.shop_brands.
--
-- Shop is the only platform where ONE site.platform_connections row fans out to
-- many content rows (MAX_BRANDS = 5), and its payload is frozen to the FOUND-25
-- marker {"storage":"relational"} — so the connection row's last_refresh_status
-- cannot express "brand A pending, brand B ready." The status has to live on the
-- brand.
--
--   connect_status  NULL     = settled / ready (the overwhelming majority, and
--                              the state EVERY pre-existing row is in — hence
--                              NULL rather than a 'ready' sentinel, and hence
--                              no DB default, per the house rule for new columns)
--                   'pending' = ShopBrandConnectJob is in flight for this brand
--                   'failed'  = the job reached a terminal failure. NOTE this
--                              does NOT mean the brand is unusable: brand_id,
--                              provider, url and source_url are all truthful at
--                              202 time under design path (c), so the picker and
--                              the public render both work. Only the display
--                              profile (name/currency/favicon/logo) is missing —
--                              exactly the state today's SYNCHRONOUS path already
--                              reaches when a store's homepage fetch fails.
--   connect_error   the displayable sentence the poll endpoint returns verbatim.
--                   Only ever one of the two shared infrastructure strings from
--                   DefersBespokeConnect; never scraper internals.
--
-- App-side vocabulary source of truth: App\Models\Core\Site\ShopBrand::CONNECT_STATUSES.
-- Kept in lockstep by tests/Feature/Database/ConstraintVocabularyLockstepTest.php.
--
-- site.shop_brands is not a HOT_TABLE, but the NOT VALID -> VALIDATE split
-- (CONVENTIONS.md §2) still applies, so this copies the two-window shape of
-- 20260720100200_shop_brands_mode_checks.sql verbatim.
--
-- Dev census (glncumufgaqcmqhzwrxm): every existing row predates the column and
-- is therefore NULL — VALIDATE is a trivially clean pass.

-- Window 1: add the columns and the CHECK in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands
    ADD COLUMN IF NOT EXISTS connect_status text,
    ADD COLUMN IF NOT EXISTS connect_error  text;

-- Drop-then-add so a replay of this file is idempotent (same reasoning as
-- Window 3 of 20260701190000, which MigrationTransactionBoundaryTest pins).
ALTER TABLE site.shop_brands
    DROP CONSTRAINT IF EXISTS shop_brands_connect_status_check;

ALTER TABLE site.shop_brands
    ADD CONSTRAINT shop_brands_connect_status_check
    CHECK (connect_status IS NULL OR connect_status IN ('pending', 'failed')) NOT VALID;

COMMIT;

-- Window 2: validate in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands VALIDATE CONSTRAINT shop_brands_connect_status_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.shop_brands
--     DROP CONSTRAINT IF EXISTS shop_brands_connect_status_check;
-- ALTER TABLE site.shop_brands
--     DROP COLUMN IF EXISTS connect_status,
--     DROP COLUMN IF EXISTS connect_error;
-- COMMIT;
```

SQLite mirror to add in `tests/Pest.php` (~`:643`, after `referral_query`):

```
connect_status TEXT NULL,
connect_error TEXT NULL,
```

---

## 7. Risks and open decisions for Josh

### R1 — 🔴 The win is small, and for half the providers it is **zero**. Read this before signing off.

Path (c) defers only `brandProfileFor()`. Reading each scraper (§3a) shows what that actually is:

| Provider | HTTP moved off the request | Realistic time saved |
|---|---|---|
| shopify | 2 (`meta.json` + homepage) | ≤ 16 s, typically ~1–3 s |
| woocommerce | 2 (`wp-json` + homepage) | ≤ 16 s, typically ~1–3 s |
| squarespace | 1 (`pageJson`) | ≤ 8 s |
| **bigcartel** | **0** | **none** |
| **generic** | **0** | **none** |
| **client-assisted** | **0** | **none** |

The probe cascade — up to four sequential `SafeUrlFetcher` calls
(`ShopProviderDetector:70-119`) — is what dominates Shop's latency, and path (c) keeps **all of it**
synchronous by design, because that is what makes `brand_id` and `provider` truthful. W1 already
capped the whole thing at 20 s. So W9 buys: a shorter *typical* response for three of six providers,
inside a ceiling that is already bounded.

Against that: one migration, one new job, one new route, two new services, a contract change, and an
endpoint whose **status code now depends on which provider was detected** (R2).

**My recommendation: build it, but re-rank it below anything with a user waiting on it.** The
ledger already flagged that "W9's modest win is not overstated later" — this is the concrete
quantification of that flag. If the pilot is close, W1 alone is a defensible stopping point and
§6 decision 6 of the design doc ("W1 alone is the near-term answer") remains correct.

### R2 — 🟠 Provider-dependent status code on one endpoint. New, and needs an explicit decision.

`POST /brands` will return `202` or `200` **for the same request shape**, decided by what the probe
found. That is compatible with the published rule *"Branch on the status code, never on the platform
slug"* (`docs/frontend-contracts/…:310`), but it is a new *kind* of variance — within one endpoint,
per request — and no other endpoint in the system does it.

The alternative is **always** return 202 and always dispatch, even for the three providers with
nothing to defer. That buys a uniform wire at the cost of adding a queue round-trip and a pending
window to connects that are currently complete on arrival — i.e. making three providers *slower* to
make the contract tidier.

**My recommendation: keep the provider-dependent code**, and document it loudly (Unit 6). Returning
`202 {status:'pending'}` for a row that is already complete is a lie, and the frontend rule already
covers it. **Josh's call.**

### R3 — 🟠 The `DefersBespokeConnect` trait is used for exactly one method.

W9 consumes only `shouldDeferConnect()`. `deferredConnectResponse()` hardcodes `ConnectFetchJob`
(its own docblock, `:75-76`) and `bespokeConnectStatus()` reads `last_refresh_status` off the
connection row (`:131`) — neither can express per-brand state (NEW BLOCKERs 4, 5). W9 therefore
duplicates the trait's 5-minute staleness logic and its two error sentences in Shop's own poll
action.

That is a third copy of the staleness check (`GenericPlatformController:246-252`,
`DefersBespokeConnect:139-141`, and now Shop). Design §7 Risk R3 already accepted the second copy as
the cheaper trade. **I recommend accepting the third rather than generalising the trait mid-flight** —
generalising it now would mean editing a file the sibling branch also carries, turning a guaranteed
no-op merge into a real conflict for a cosmetic win. Worth a follow-up ticket after both branches land.

### R4 — 🟠 A stranded pending **brand** is invisible to the backlog alarm.

The sibling branch's `7330baad` added `IntegrationConnection::scopeStrandedPending()` and folded
stranded rows back into `CheckPlatformRefreshBacklogCommand`, so a dead worker is *seen*. Shop's
pending state lives on `site.shop_brands`, which that query does not touch — so a stranded Shop brand
produces no alarm at all. The user still gets a terminal answer (the 5-minute poll backstop, T19), but
operations gets no signal.

Adding a `ShopBrand::scopeStrandedPending()` fold to that command **from this branch would collide
head-on with the sibling's edit to the same method.** I have deliberately excluded it.
**Recommendation: a small follow-up unit after both branches merge.** Flagging it so it is a decision,
not an oversight.

### R5 — 🟡 A second cache purge per connect.

`addBrand()` calls `IntegrationConnectionCacheRefresher::refresh()` unconditionally today (`:211`),
and the job must call it again on settle (the observer cannot fire — `ShopController:47-52`). So a
deferred connect dispatches two `CloudflareCachePurgeJob`s instead of one. Suppressing the first for
pending writes would shrink the diff's blast radius in the wrong direction — it would make
`addBrand()` behave differently under the flag in a way the dark-merge test could not see. Keep both;
the cost is one extra purge per deferred connect.

### R6 — 🟡 `ShopFetch` will happily sync a pending brand.

The 6-hourly `ShopFetch::fetch()` selects every non-individual brand (`:42-45`) with no status filter,
so a brand that is `pending` (or stranded) when the cron fires gets its products synced. **This is
harmless and I recommend leaving it alone:** under path (c) a pending brand's `provider`/`url`/
`source_url` are all truthful, so `syncLatest()` does exactly the right thing. Adding a filter would
change scheduled-refresh behaviour for no benefit. Recorded so a later sweep does not re-flag it.

### R7 — 🟡 The picker catalog cache key is not user-scoped.

`CacheKeyGenerator::shopifyBrandCatalog($brandId)` (`:295-298`) keys only on `brand_id`, so two users
connecting the same store share one catalog entry. **Pre-existing, not introduced by W9**, and benign
(the content is public and identical). Noted because W9 touches the warm path and a reviewer will see
it.

### R8 — 🟡 Merge-order sensitivity with the sibling branch.

Only `routes/api/platforms.php` and (possibly) the golden-master test are genuinely shared, both in
non-adjacent regions (§2). **Recommendation: land the sibling Phase-3 branch first** — it is further
along and its `DefersBespokeConnect` blob is identical to our cherry-pick, so W9 then rebases onto a
tree where the trait already exists and the only conflict surface is a one-line route addition.

### Open decisions requiring Josh's word

1. **R1 — proceed at all?** The quantified win is smaller than the design doc implied.
2. **R2 — provider-dependent status code, or always-202?** I recommend provider-dependent.
3. **R4 — accept the stranded-brand observability gap until a post-merge follow-up?** I recommend yes.
4. **§3h — `brand` vs `connection` as the ready-state key, and `failed` carrying `brand`.** Both diverge
   from the six on purpose; both are frontend-visible.
5. **Should `setProducts()` (Unit 5) also become async?** The mission line mentions "`setProducts()`'s
   cache-miss fetch", but the Shop-specific constraints only require the lock restructure. **I recommend
   restructure only** — the cache is warm on the overwhelmingly common path (the picker was just open),
   and a 202 there would need a *second* per-selection pending state with no equivalent win. Flagged so
   the narrower reading is a decision rather than a silent omission.

### Nothing marked PREMISE-STALE beyond the ledger's three

The ledger's PREMISE-STALE 1, NEW BLOCKER 4 and NEW BLOCKER 5 were re-verified against
`ConnectFetchJob.php:76-79`, `:92-113` and `DefersBespokeConnect.php:114-165` and all hold. All twelve
`ShopController` anchors in the brief's Step 0 table were re-checked against the working tree and all
hold. Two **corrections to the given design sketch**, both recorded above rather than silently
absorbed: the job's catalog-warm bullet is a no-op (§3e), and the ledger's "0–1 further HTTP" cost
split understates the deferred work at up to 2 calls for Shopify and WooCommerce (§3a).
