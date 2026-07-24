# API Contract & Resource Leakage Audit — 2026-07-24

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage — raw model fields bleeding through, over-fetching, inconsistent pagination (chunks: user-api, public-staff-api, payload-services)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Services/Site/ItemSlugAllocator.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **#API-1** · P3 — `PublicIntegrationController` calls `->resolve()` on Resources, bypassing the Resource response pipeline
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:181-192
    - **Affects:** `GET /api/public/profiles/{handle}/platforms` — no current behavioral difference (verified: neither `PublicIntegrationConnectionResource` nor any sibling Platforms Resource defines `withResponse()` or `additional()`), but any future addition of either is silently dropped because `->resolve()` returns the raw `toArray()` result instead of routing through `toResponse()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return `PublicIntegrationConnectionResource::collection($rows->values())` directly (drop `->resolve()`) for the non-shop branch and let the framework's response pipeline run.
        - For the shop branch, thread `$shopLinkMode`/`$productRanks` via `additional()` metadata or a resource-collection subclass instead of calling `->resolve()` on each item, then wrap with `response()->json(['data' => ['platforms' => ...]])` only at the point the shape genuinely needs manual assembly (mixed shop/non-shop keys).
        - If the manual re-wrap in `$this->success([...])` is kept for now (grouping by platform key isn't natively expressible by a single Resource collection), leave a comment noting `->resolve()` is a deliberate, audited exception — so a future reviewer doesn't assume it's an oversight.
    - **Technical:** `PublicIntegrationConnectionResource::collection($rows->values())->resolve()` and `(new PublicIntegrationConnectionResource($row))->...->resolve()` both call `JsonResource::resolve()`, which runs `toArray($request)` and returns a plain array — it does not go through `toResponse()`, so `withResponse()` hooks, `additional()` merged meta, and resource-level header customization are all skipped. Today this is inert (grepped every `Platforms` Resource; none defines those hooks), so there is no live bug — this is a latent contract gap: the moment someone adds `additional()` to `PublicIntegrationConnectionResource` for another purpose, this controller silently won't pick it up, and the failure will look like "the field just isn't there" rather than an obvious break.
    - **Plain English:** Think of a Resource class as a gift-wrapping service. Calling `resolve()` grabs the unwrapped item off the workbench instead of letting the wrapping service finish its job. Right now nothing extra was ordered — no bow, no gift card — so the customer doesn't notice. But if someone later asks the wrapping service to add a special sticker, it'll get left on the workbench and never reach the customer, and nobody will know why.
    - **Evidence:**
        ```php
        $platforms = $connections
            ->map(fn ($rows, $platform) => $platform === 'shop'
                // Thread the globals into each shop connection resource — collection()
                // can't forward the overrides, so map the rows explicitly.
                ? $rows->values()
                    ->map(fn ($row) => (new PublicIntegrationConnectionResource($row))
                        ->withShopLinkMode($shopLinkMode)
                        ->withProductRanks($productRanks)
                        ->resolve())
                    ->all()
                : PublicIntegrationConnectionResource::collection($rows->values())->resolve())
            ->toArray();
        ```

- [ ] **#API-2** · P3 — `PublicMenuController` composes the response as a hand-rolled array instead of a Resource/composer class
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicMenuController.php:106-181
    - **Affects:** `GET /api/public/profiles/{handle}/menu` — no field-audience leak today (every emitted field is deliberately public menu data: name, description, price, platform links), but the controller itself owns ~75 lines of nested array construction with no shared, testable composition unit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the categories/items mapping into a dedicated service (e.g. `PublicMenuPayloadComposer`), mirroring the precedent already set by `MenuPayloadComposer` for the authenticated dashboard surface (`app/Services/Platforms/MenuPayloadComposer.php`), rather than leaving it inline in the controller action.
        - Share `numberOrNull`/`textOrNull` from one place instead of the two now-duplicated private copies (one in `MenuPayloadComposer`, one here) — the in-code comment ("kept local, duplication over a shared dependency") shows this was a deliberate call, not an oversight, but it's worth revisiting now that item-slug fields have been added to both copies independently (`c8a49175`, `990a956f`, `dec76b3a`) and will need to be kept in sync by hand on every future field addition.
        - If a full Resource class is preferred over a composer service, model it on the same nested-array shape so the public/dashboard "mirrors X exactly" comments littered through both files remain true.
    - **Technical:** `Menu::with([...])->first()` is mapped manually into `categories`/`items` arrays inside the controller action, duplicating field-selection and formatting logic (`textOrNull`, `numberOrNull`, price formatting, `MenuItemDeepLinks::forItem`) that the dashboard surface already carries in `MenuPayloadComposer::categories()`/`platforms()`. This isn't a raw-Eloquent leak — every field is explicitly enumerated, so no new model column can "silently" reach the wire the way an unguarded `$model->toArray()` would — but it is a maintainability gap: the 2026-07-24 slug work (`c8a49175`, `dec76b3a`, `990a956f`) had to touch this hand-built array directly rather than a single shared composition point, and the next new menu field will require the same two-file edit.
    - **Plain English:** Picture a restaurant printing menu cards by hand for each format (in-store vs. online) instead of using one master template that both formats pull from. Nothing on either card is wrong today, but every time a dish or price type is added, someone has to remember to update both hand-written versions identically — and that's exactly the kind of step a template exists to remove.
    - **Evidence:**
        ```php
        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                // Stable persisted id — survives scrapes via MenuFetchJob's name-keyed
                // id reuse, so the frontend can key category state off it.
                'id' => (string) $cat->id,
                'popularityRank' => $categoryRanks[(string) $cat->id] ?? null,
                'items' => $cat->items->map(fn ($item) => [
        ```
        ```php
        return $this->success([
            'data' => [
                'storeName' => $menu->store_name,
                'currency' => $currency,
                'categories' => $categories,
            ],
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — PublicSite platforms/menu response hygiene:** #API-1, #API-2
    - **Why grouped:** both touch the same PublicSite response-construction pattern (Resource pipeline vs. hand-rolled arrays) in sibling controllers (`PublicIntegrationController`, `PublicMenuController`); one reviewer pass covers both with shared context.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). No escalation needed — both are low-risk, non-security hygiene changes with no schema or auth surface.

## Standalone — do NOT bundle

None.
