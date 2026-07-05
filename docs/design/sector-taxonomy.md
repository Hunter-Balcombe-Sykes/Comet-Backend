# Sector taxonomy

The **sector** is a user-facing industry classification a professional picks
for their profile. It lives on `core.users.sector` (provenance in
`core.users.sector_source`) and is served/edited through two endpoints and
optionally filled by the Google Business precedence sync.

Source of truth: `app/Services/Profile/SectorTaxonomy.php`. This doc explains the
list and the Google-mapping approach; the code is authoritative for the exact
entries.

## Shape

Each sector entry is `{ slug, label, group, bucket }`:

- **slug** — stable kebab-case identifier stored in `users.sector`. Never
  renamed once shipped (it's persisted per user); add new slugs, don't mutate
  existing ones.
- **label** — the human string shown in the picker.
- **group** — the section header the picker renders under. Groups map 1:1 to the
  ten style buckets below.
- **bucket** — one of the ten `App\Services\Design\Presets\CategoryStylePresets`
  bucket constants. Metadata only today (nothing styles off the chosen sector
  yet), but every row carries one so the sector speaks the same vocabulary as
  the Google/Instagram category factors. This is what keeps
  `fromGoogleCategory()` honest — it maps a raw Google category to a sector
  whose bucket agrees with what `GoogleBusinessTypeFactor` would independently
  pick for the same input.

## Groups → buckets

| Group (picker section) | CategoryStylePresets bucket |
|---|---|
| Food & Drink | `FOOD_DRINK` |
| Beauty & Personal Care | `BEAUTY_PERSONAL_CARE` |
| Health & Fitness | `HEALTH_FITNESS` |
| Professional Services | `PROFESSIONAL_SERVICES` |
| Retail & Shopping | `RETAIL_SHOPPING` |
| Home & Trade Services | `HOME_SERVICES` |
| Hospitality & Events | `HOSPITALITY` |
| Automotive | `AUTOMOTIVE` |
| Creative & Entertainment | `CREATIVE_ENTERTAINMENT` |
| Education & Coaching | `EDUCATION_COACHING` |
| Other | `PROFESSIONAL_SERVICES` (neutral fallback) |

The list is deliberately curated (~70 entries) — broad enough that most solo
professionals find themselves, tight enough to stay a picker rather than the
full Google place-type enum.

## API

- `GET /api/profile/sector-options` → `{ groups: [{ group, options: [{ slug, label }] }] }`.
  Backed by `SectorTaxonomy::all()`. Static content; the user context only keeps
  it behind the authenticated group.
- `PUT /api/profile/sector` with `{ sector: <slug|null> }`. Validated by
  `UpdateSectorRequest` (`Rule::in` over the taxonomy slugs; `null` clears).
  Sets `users.sector` and always stamps `users.sector_source = 'manual'` so the
  precedence rule below knows the value was user-chosen.

## Google Business mapping

`SectorTaxonomy::fromGoogleCategory(?string $category): ?string` maps a raw
Google Places `primaryTypeDisplayName` (e.g. `"Italian restaurant"`,
`"Barber shop"`) to the closest curated sector slug, or `null` when nothing
matches.

It reuses `CategoryStylePresets::classify()` — the same **ordered
keyword ⇒ target** substring matcher `GoogleBusinessTypeFactor` uses — over a
`KEYWORD_SECTORS` map. Two rules matter:

1. **First substring match wins** (case-insensitive). So the keyword order is
   **specific-before-generic**: `'barber'` must precede `'bar'`, or
   `"Barber shop"` would classify as a bar. `KEYWORD_SECTORS` mirrors
   `GoogleBusinessTypeFactor::KEYWORD_BUCKETS`' ordering exactly, re-pointed from
   a style bucket to the closest sector slug — keeping the two classifiers in
   lock-step (same input → agreeing bucket).
2. **No match → `null`**, and callers leave the stored sector untouched.

### Precedence (who wins)

Applied by `App\Services\Platforms\IdentitySync::applyFromGooglePayload()` when a
google-business connection is saved (connect or refresh). The account-type
signal is read in exactly one place —
`AccountCapabilities::for($user)->google_business_full_sync` (true for Business
Partna):

- **business** (`google_business_full_sync = true`) → Google **overwrites** a
  differing `users.sector` and stamps `sector_source = 'google-business'`.
- **partna** (`google_business_full_sync = false`) → Google fills `users.sector`
  **only when it is currently blank**; a manually-chosen sector is never
  clobbered.

The same per-field business-overwrite / partna-fill-empty rule governs the
workplace identity columns (`name`, `address`, `phone`, `website`, `category`,
`latitude`, `longitude`, `opening_hours`) and the mirrored
`users.public_contact_number`. `contact_email` is **never** written from Google
(Places returns none). See the class docblock for the full contract.

## Changing the list

- Add slugs; never rename or repurpose an existing slug (it's persisted).
- Every new entry needs a `bucket`.
- If the new sector corresponds to a Google category keyword, add it to
  `KEYWORD_SECTORS` in specific-before-generic order and keep it consistent with
  `GoogleBusinessTypeFactor::KEYWORD_BUCKETS`.
