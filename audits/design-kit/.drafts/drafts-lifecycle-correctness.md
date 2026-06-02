- [ ] **#LIFE-1** · P2 — Race condition in design kit write: concurrent saves can lose design customisations
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:writeDesignKit()`
    - **Affects:** Any professional who opens the site editor in two tabs or triggers rapid consecutive saves. At the scale target (200 brands), this is rare per professional but leads to undetected data loss when it does occur.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `writeDesignKit` update in a transaction and acquire a row‑level `lockForUpdate` on `site.design_kits` before applying the partial changes.
        - Use the canonical `lockForUpdate` + `UNIQUE` pattern (race‑safe wallet credit) — the design kit row is the “wallet” and the request’s partial kit is the delta.
    - **Technical:** The method reads the current column list from `information_schema`, filters the incoming JSON, then calls `->update($valid)` without any locking or transaction. Two concurrent requests that modify different columns will both succeed, but because each `UPDATE` reads the row at its own start, the final state is the last writer’s version — any columns changed by the first writer are overwritten. The fix is a `lockForUpdate` on the `site.design_kits` row inside a database transaction, so the read‑modify‑write is atomic.
    - **Plain English:** Imagine two people editing the same Google Doc at the same time. Normally, Google Documents shows both changes in real‑time. Our system doesn’t do that — each “save” overwrites the whole document. If two saves happen close together, the later one erases the earlier one without any warning. The fix is to lock the document while someone is writing, so changes arrive one at a time and none get silently lost.
    - **Evidence:**
        ```php
        $columns = DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all();
        // … filter $designKit against $columns …
        DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->update($valid);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-2** · P2 — Staff update request still validates legacy design‑kit columns that no longer exist in the database
    - **Where:** `app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php:rules()`
    - **Affects:** Staff who edit a professional’s design kit. The UI may present fields for padding, spacing, and tablet‑only sizing, and the server will accept them with a 200 response — but the values are silently discarded because the underlying columns were dropped by migration `20260529053028`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the validation rules for `padding_*`, `spacing_*`, and `*_tablet_*` columns from `StaffUpdateSiteRequest` so that the validator rejects (or ignores) those keys.
        - Align the staff request with the current schema by listing only the columns that actually exist in `site.design_kits` (the user‑facing `UpdateSiteRequest` is already correct).
    - **Technical:** The `writeDesignKit` method in `UserSiteController` filters incoming keys against `information_schema`, so legacy keys are silently dropped. However, the staff‑side form request still allows them, which means a staff member successfully submits now‑dead fields and receives no error. This is a schema‑code mismatch (category 11) that creates a misleading success path and wastes support time investigating “why didn’t my change take effect?”
    - **Plain English:** Imagine a filing cabinet that used to have drawers for “Padding” and “Spacing,” but you removed those drawers last week. The labels on the front of the cabinet still say “Padding” and “Spacing,” so someone tries to put a file in there — the file disappears into an empty hole. The fix is to remove the old labels so nobody tries to use drawers that no longer exist.
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest (excerpt) — rules for dropped columns
        'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_small' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_general' => ['sometimes', 'nullable', 'string', 'max:16'],
        'design_kit.padding_large' => ['sometimes', 'nullable', 'string', 'max:16'],
        // ... many more padding / spacing / tablet rules ...
        ```
    - `[DRAFT, confidence: 0.97]`

- [ ] **#LIFE-3** · P3 — Inline validation in `UserSiteController::updateBookingSettings` instead of a dedicated Form Request class
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:updateBookingSettings()`
    - **Affects:** The booking‑settings update endpoint. Currently a small, low‑traffic endpoint; but as the codebase grows, keeping validation scattered in controllers makes refactoring harder and harder to test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the validation rules into a dedicated Form Request class (e.g. `UpdateBookingSettingsRequest`) following the `a11feb2` refactor pattern.
        - Replace the inline `Validator::make()` with a parameter type‑hint on the method.
    - **Technical:** Partna’s architecture mandates Form Request objects for all validation so that rules are centralised and reusable. An inline `Validator::make` call in a controller duplicates logic and bypasses the automatic resolution and authorisation hooks that Form Requests can provide. This is a category‑7 violation (authorization & validation hygiene). Despite the endpoint’s simplicity, the pattern drift will accumulate technical debt as more ad‑hoc validation is added.
    - **Plain English:** Every room in the house is supposed to have a light switch by the door, but this one has a pull‑chain hanging from the ceiling — it still turns the light on, but it doesn’t match the rest of the house and a visitor won’t know to look for it. The fix is to install the same standard switch everywhere so nobody has to guess.
    - **Evidence:**
        ```php
        $validator = Validator::make($request->all(), [
            'booking_mode' => ['required', 'string', Rule::in($allowedModes)],
            'manual_booking_url' => ['nullable', 'url', 'max:2048'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        ```
    - `[DRAFT, confidence: 0.85]`
