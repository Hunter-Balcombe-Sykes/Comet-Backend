# API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination

Hunt **raw Eloquent models returned from API endpoints**, **Resource classes that expose fields not intended for the caller**, **over-fetching** (eager loading more than the Resource uses), **missing or inconsistent pagination**, and **response shape inconsistencies** across similar endpoints. API contract bugs leak internal structure and bloat responses — both are hard to fix after clients are built against them.

Partna returns all API responses through **Eloquent API Resource classes** (`app/Http/Resources/`). Raw model returns are a policy violation. The platform has five distinct API surfaces (User, PublicSite, Staff, Internal, Platforms) — a Resource used across surfaces may expose fields appropriate for Staff that are wrong for User or PublicSite callers. The clearest audience-confusion risk is the **PublicSite** surface: `IndividualProfileController` resolves a site by handle for unauthenticated visitors and must never expose PII (email, phone, internal flags, moderation state) or internal metadata. A single shared Resource class used on both authenticated and public endpoints is a finding.

**Skeleton system contract instability:** The `GET /api/public/profiles/{handle}` payload is mid-reshape as part of the skeleton-system migration (spec §8). The in-progress changes drop `themeMode`, `accent`, `fontFamily` from the styling block and add `designKit` (partial — only stored non-null values) and `skeletonId` (one of `skeleton-1..skeleton-4`). Until this lands, the contract is unstable. Flag any `IndividualProfileResource` or `PublicSite`-surface Resource that still exposes `themeMode`, `accent`, `fontFamily`, or reads from `settings.design.*`, and any Resource that should be emitting `designKit`/`skeletonId` but is not.

## Use the lens prefix `API` for findings

Number them `API-1`, `API-2`, … sequentially. **P1 for confirmed PII/sensitive field leakage to wrong audience. P2 for raw model returns or over-fetched sensitive data. P3 for shape inconsistency, missing pagination, or over-fetching non-sensitive data.**

## Findings categories

### (1) Raw model returns — policy violation

- `return response()->json($model)` — bypasses Resource transformation entirely.
- `return $model->toArray()` in a controller — same bypass.
- `return $collection` where `$collection` is an Eloquent Collection, not a Resource collection.
- `JsonResource::withoutWrapping()` misuse — confirm it's intentional and the caller expects the unwrapped shape.
- `->toArray($request)` called manually in a controller instead of returning the Resource — transformation runs but wrapping/meta is lost.

### (2) Audience-confused Resource classes

- A single Resource class used on both a User endpoint and a Staff endpoint where Staff fields (e.g. `admin_notes`, `internal_flags`, `moderation_state`, `is_flagged`) are included unconditionally.
- A single Resource class used on both an authenticated and a public endpoint where authenticated-only fields (e.g. `email`, `phone`, `account_type`, internal capability flags) are always emitted. The PublicSite surface is highest-risk: enumeration via 403 leaks resource existence (CLAUDE.md: public endpoints must return 404 for missing/inaccessible resources, never 403).
- `when($this->relationLoaded('sensitiveRelation'), ...)` — correct pattern, but confirm the "false" branch doesn't default to `null` being serialised (leaks field name).
- User Resource returned to a Staff actor (or vice versa) where the Resource was designed for the owning actor — moderation fields, audit flags, deletion state, capability overrides.
- `UserStaffResource` fields leaking into `UserPublicResource` or `IndividualProfileResource` — these are distinct classes but may share a parent or overlap in `toArray`.

### (3) Over-fetching — eager loading more than the Resource uses

- `with(['relation1', 'relation2'])` in a controller where the Resource only accesses `relation1` — `relation2` is fetched but discarded, wasting DB round-trips.
- `with(['services'])` on a paginated endpoint where only `services_count` is shown — use `withCount` instead.
- Nested eager loads (`with(['site.media', 'site.blocks'])`) where the Resource only reads `site.skeleton_id` — the extra joins bloat the query.
- `load()` called inside a Resource's `toArray()` — triggers a query per-item in a collection (N+1 disguised as a Resource method).

### (4) Missing or inconsistent pagination

- Index endpoints (`GET /things`) that return all records without `paginate()` — unbounded response size.
- Endpoints that paginate with different `per_page` defaults (`15` vs `25` vs `100`) for similar resources — client inconsistency.
- Cursor-based vs offset-based pagination used on the same collection type across different endpoints — clients can't reuse pagination logic.
- Pagination metadata missing from the response envelope (`meta.current_page`, `meta.last_page`, `links.next`) — clients can't know if more pages exist.
- `all()` used inside a Resource's relationship — lazy-loads all related records without pagination.

### (5) Response shape inconsistencies

- `data` wrapper present on some endpoints and absent on others for the same resource type.
- Error responses with different shapes (`{'error': '...'}` vs `{'message': '...'}` vs `{'errors': {...}}`) across controllers.
- Timestamps returned as ISO 8601 on some endpoints and Unix epoch on others.
- UUID fields sometimes returned as strings, sometimes omitted when null vs returned as `null`.
- Relationship fields returned as a nested object on one endpoint and as a flat ID on another for the same relationship.

### (6) Missing fields that clients will need

- Resource classes missing `created_at` / `updated_at` on resources where clients need to cache-bust or display "last updated".
- Status fields omitted from Resources on state-machine models — clients can't render the correct UI without the current state.
- Cursor / token fields missing from paginated responses — clients can't request the next page.
- `IndividualProfileResource` (or its successor for the skeleton system) missing `skeletonId` — the frontend `partna-pages` dispatcher requires it to pick the correct skeleton; omission causes a runtime fallback or crash.
- `designKit` absent or null-collapsed in the public profile payload — `partna-pages` must receive the partial object (non-null stored values only) and apply code-side defaults; a completely absent `designKit` key breaks the merge.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Quote the controller return statement OR the Resource `toArray` method showing the leaking/missing field.
- Name the audience the endpoint serves and why the field is wrong for that audience.
- Name the canonical fix: dedicated Resource class per audience, `$this->when(...)`, `$this->whenLoaded(...)`, `paginate(25)`, `withCount(...)`, consistent error envelope.

## Suggested per-domain scope groups

### Group A — Resource classes (primary evidence)
```
--scope app/Http/Resources
```

### Group B — Controllers (raw return detection)
```
--scope app/Http/Controllers/Api/User
--scope app/Http/Controllers/Api/PublicSite
--scope app/Http/Controllers/Api/Staff
```

### Group C — Service methods that return collections
```
--scope app/Services/Analytics
--scope app/Services/PublicSite
--scope app/Services/Site
```

## Exhaustiveness directive

Every controller action that returns data is a candidate. Every Resource class used on more than one endpoint surface (User vs Staff vs PublicSite) must be examined for field audience confusion. Do not assume `$this->when(...)` is present without reading the `toArray` implementation. Pay special attention to `IndividualProfileResource` and `UserPublicResource` — these are the highest-risk classes for PII leakage to unauthenticated visitors.
