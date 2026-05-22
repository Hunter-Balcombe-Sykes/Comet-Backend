- [ ] **API-1** · P3 — Staff site-update endpoint accepts retired design fields that the professional endpoint has prohibited
    - **Where:** app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php (rules section) vs app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php (rules section)
    - **Affects:** Staff users can persist values to retired design keys (e.g. `settings.design.typography.heading_font`) that the brand’s own API rejects. The professional UI ignores these fields, so the data becomes invisible orphan state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `prohibited` rules to `StaffUpdateSiteRequest` for keys that `UpdateSiteRequest` marks prohibited (`heading_font`, `body_font`, `font_file_name`, `font_file_path`, `font_file_url`, and legacy colour keys).
        - Add the unified design shape (e.g. `settings.design.font_family`, `settings.design.colors.accent`) to `StaffUpdateSiteRequest` so staff can edit the brand’s design through the canonical schema.
    - **Technical:** The professional `UpdateSiteRequest` prohibits half a dozen legacy design keys (e.g. `heading_font`) and enforces new normalised enums (`font_family`, `colors.accent`). The staff `StaffUpdateSiteRequest` still white-lists those legacy keys as writeable while missing the new keys entirely. This asymmetry means a staff edit can create a diverging design record that the self‑serve professional UI never displays or overwrites.
    - **Plain English:** The staff toolbox lets you paint with colours the brand’s own dashboard no longer shows — the two sides end up looking at different walls, and the extra paint sits there forever unseen.
    - **Evidence:**
        ```php
        // UpdateSiteRequest (professional) — prohibits legacy fields
        'settings.design.typography.heading_font' => ['prohibited'],
        'settings.design.typography.body_font' => ['prohibited'],
        'settings.design.typography.font_file_name' => ['prohibited'],
        'settings.design.typography.font_file_path' => ['prohibited'],
        'settings.design.typography.font_file_url' => ['prohibited'],
        // StaffUpdateSiteRequest (staff) — still allows them
        'settings.design.typography.heading_font' => ['sometimes', 'nullable', 'string', 'max:255'],
        'settings.design.typography.body_font' => ['sometimes', 'nullable', 'string', 'max:255'],
        'settings.design.typography.font_file_name' => ['prohibited'],
        'settings.design.typography.font_file_path' => ['prohibited'],
        'settings.design.typography.font_file_url' => ['prohibited'],
        // Staff site request also lacks the new unified design fields:
        // 'settings.design.font_family' is absent from StaffUpdateSiteRequest
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **API-2** · P3 — Staff site-update endpoint missing the new unified brand‑design schema
    - **Where:** app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php
    - **Affects:** Staff users cannot edit the canonical brand design properties (font family, accent colour, theme mode, radius/spacing/border enums) through the staff API.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add the new unified design rules from `UpdateSiteRequest` to `StaffUpdateSiteRequest`: `settings.design.colors.accent`, `settings.design.corner_radius`, `settings.design.border_thickness`, `settings.design.section_spacing`, `settings.design.theme_mode`, `settings.design.font_family`.
        - Prohibit the now‑derived colour keys that the professional endpoint prohibits (`colors.background`, `colors.text`, `colors.border`).
    - **Technical:** The professional `UpdateSiteRequest` already enforces the normalised design tokens introduced by the theme‑mode migration. `StaffUpdateSiteRequest` still operates on the old free‑key colour and typography fields and completely lacks the new keys, so a staff member viewing a brand’s design cannot change the current design values — they can only modify legacy keys that are ignored by the render pipeline.
    - **Plain English:** Staff can open a brand’s settings toolbox but only see old, useless dials — the real knobs that control the look and feel aren’t there for them.
    - **Evidence:**
        ```php
        // UpdateSiteRequest includes the new unified design rules:
        'settings.design.colors' => ['sometimes', 'array'],
        'settings.design.colors.accent' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
        'settings.design.corner_radius' => ['sometimes', 'nullable', 'string', Rule::in(['square', 'default', 'pill'])],
        'settings.design.border_thickness' => ['sometimes', 'nullable', 'string', Rule::in(['hairline', 'default', 'bold'])],
        'settings.design.section_spacing' => ['sometimes', 'nullable', 'string', Rule::in(['tight', 'default', 'spacious'])],
        'settings.design.theme_mode' => ['sometimes', 'nullable', 'string', Rule::in(['light', 'dark'])],
        'settings.design.font_family' => ['sometimes', 'nullable', 'string', Rule::in([...])],

        // StaffUpdateSiteRequest has none of the above — the entire
        // unified design shape is absent.
        ```
    - `[DRAFT, confidence: 0.9]`
