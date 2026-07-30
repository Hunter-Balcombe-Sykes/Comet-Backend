# tests/Authz/ — authorization matrix

Fires every param-bearing `api/` route cross-tenant and cross-privilege. A
response that is not a rejection is a finding.

Design: `docs/superpowers/specs/2026-07-30-authz-test-harness-design.md`

## `composer test` does NOT run this

The default suite is the SQLite lane. This lane needs a real Postgres with
`supabase/migrations/` applied — firing 89 routes at hand-built stand-in tables
would 500 on "no such table", not 404, and prove nothing.

Locally, use a container of your own. **Do not share `partna_test` on port 5432
with another session**: `apply-migrations.sh` drops and rebuilds the whole
schema, so two lanes resetting concurrently destroy each other.

```bash
docker run -d --name partna-pg-authz \
  -e POSTGRES_PASSWORD=postgres -e POSTGRES_USER=postgres -e POSTGRES_DB=partna_test \
  -p 5434:5432 postgres:16

PGHOST=127.0.0.1 PGPORT=5434 PGUSER=postgres PGPASSWORD=postgres \
PGDATABASE=partna_test scripts/db/apply-migrations.sh

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5434 DB_DATABASE=partna_test \
       DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable
composer test:authz
```

`apply-migrations.sh` calls `psql` from PATH. If you have no local client, proxy
it into the container.

In CI this runs inside the `schema-tests` job with `AUTHZ_LANE_REQUIRED=1`,
which turns a missing database into a red build instead of a silent skip.

## Every file needs `uses(AuthzTestCase::class)`

Pest auto-loads only the ROOT `tests/Pest.php`. A directory-level
`tests/Authz/Pest.php` carrying `uses(...)->in('Authz')` **never executes** —
verified 2026-07-30 — and every test then runs against
`PHPUnit\Framework\TestCase` with no application booted. Declare the binding at
the top of each file.

## I added a route and the build went red

| Failure | Fix |
|---|---|
| "could not be resolved to a model … no `fixture:` mapping" | Read your controller; add `fixture: { <param>: <Model FQCN> }` to `expectations.yaml` |
| "no seeded identity-B row for `<Model>`" | Add it to `Fixtures::seedOwnedBy()` |
| "inconclusive — validation rejected the request" (422) | Add a minimal `body:` that clears the FormRequest. No route currently declares `expect: 422`; doing so is declaring the route untested, so only ever as a last resort with a reason naming the gap |
| "cross-tenant access" (2xx) | **A bug in your controller**, not in the test |
| "403 leaks existence" | Return 404 for resources that do not belong to the caller — unless it is a genuine role/capability gate, in which case declare `expect: 403` with a reason |

## expectations.yaml

Three declarations, in descending order of strength:

- `fixture: { p: <Model FQCN> }` — substitute identity B's real row. The strong
  form: a true cross-tenant probe.
- `fixture: { p: unknown }` — the param is not a model PK. The platform
  controllers resolve `{id}` as a `resource_id` inside rows already scoped to
  the caller, so no B-owned PK exists. Probed with an id matching nothing:
  proves the scoped lookup 404s rather than 500ing or leaking. **Weaker**, and
  declared explicitly so the weakness is visible in review.
- `expect: exempt` + `reason:` — the param is not a resource identifier at all.

Keys may be method-scoped (`"PUT api/foo/{id}"`) and the method-scoped entry
wins. This matters: `GET api/content/items/{item}/overrides` correctly answers
404 while `PUT` on the identical path answers 422 because validation runs first.

**A `reason:` is mandatory on every exemption and every non-404 expectation, and
must name what the param actually identifies.** Exempting a route to make the
build green defeats the entire lane.

## Write routes carry a `body:`

Every param-bearing write route reaches authorization. Nine were previously
declared `expect: 422` — validation rejected an empty body before the ownership
check ran, so ownership was unproven on them. Each now carries the minimal
`body:` that clears its FormRequest, and answers the strict default:

| Route | Body | Observed |
|---|---|---|
| `PUT api/site/sections/{section}/items/{item}` | `{state: excluded}` | 404 |
| `POST api/content/identity/candidates/{candidate}/rule` | `{verdict: different}` | 404 |
| `PUT api/content/items/{item}/overrides` | `{facet, column, value: null}` | 404 |
| `PATCH api/services/{service}/category` | `{category_ids: []}` | 404 |
| `POST api/platforms/{shop,shopify}/brands/{id}/catalog` | `{products: [{}]}` | 404 |
| `PUT api/platforms/{shop,shopify}/brands/{id}/selection` | `{productIds: []}` | 404 |
| `PATCH api/platforms/menu/categories/{category}` | `{name: "authz"}` | **403** |

The menu `PATCH` is the one that does not become a 404: with validation cleared
it reaches `denyWithoutCapability()` and is refused by the food-business
capability gate, exactly as its `DELETE` sibling already was. It is declared
`expect: 403` alongside that sibling, with the same reason.

Bodies are **minimal by rule** — the fewest fields that clear validation, never
a realistic payload, and never referencing identity B's data. A body carrying
B's ids would pass validation for the wrong reason and prove nothing.

Verified 2026-07-30 with a negative control: each route answers 422 on an empty
body (proving the URI matches and the validator runs) and 404/403 on the body
above (proving the rejection comes from the ownership check downstream of it).
Two spellings need care —
`SetShopProductsRequest` validates **`productIds`**, camelCase, and `products`
is `required|array`, which an *empty* array fails.

## Param-free collection endpoints

`CrossTenantTest` substitutes identity B's id into a path, so it can only reach
routes with a `{param}`. The other 132 param-free `GET` routes in the user and
platforms groups ask a different question — *does A's own listing contain B's
rows?* — and `CollectionLeakageTest` sweeps them for foreign ids.

Read its result narrowly. **128 of the 132 responses contain no ids at all**
(identity A owns only a bare site, because `Fixtures::seedOwnedBy()` runs for B),
so for most routes the assertion is a tripwire, not a proof of correct scoping.
Its first test is a **non-vacuity guard**: it gives A a real page and section and
proves detection fires, so the sweep cannot stay green by every endpoint
returning nothing.

If you want a real isolation proof for a specific endpoint, write it in
`tests/Feature/Security/TenantIsolation/` — that is where the hand-written,
positive-controlled cases live, and it already covers the pages/sections index.

**Beware the phantom.** The coverage lens cannot see
`tests/Feature/Security/PolicyEnforcement/` or `TenantIsolation/`, so it reports
"no cross-tenant test for X" for endpoints that are already tested. That class is
recorded under "Phantom coverage gaps" in the 2026-07-28 sweep's
`CONSOLIDATED.md`. Check those two directories before building anything from such
a finding — and note that `.drafts/` is untracked pre-adjudication output, not the
adjudicated record.

## Fixtures persist after a run

Identities are committed outside the per-test transaction so they survive each
test's rollback, and every row is find-or-create so a re-run reuses them. For a
clean slate, recreate the container.
