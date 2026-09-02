# Square Appointments: routing fix + Fresha-parity connector — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pasted or harvested Square Appointments link connects as the Square booking provider (not a custom link), and a connected Square page syncs its services, prices, durations and per-service "Book" deep links into the services pool with the team member preselected — the same job Fresha does today.

**Architecture:** Three layers, in dependency order. (1) The catalog gets a deep-link detector for `book.squareup.com/appointments/…` so the router scores it above booking's auto bar. (2) The ingest layer gets a `square_book` connector that reads Square's public buyer-widget JSON (no auth, one GET) and lands `service` records through a projector, with the team member carried in the connection URL's `team_member_id` param. (3) The bespoke Square controller grows Fresha's team/selection endpoints and the dashboard's Fresha team step becomes a shared booking team step.

**Tech Stack:** Laravel 12 (Comet-Backend, Pest tests, `php artisan catalog:compile` + `routing:corpus`), Next 16 dashboard in `partna-monorepo/apps/dashboard` (TypeScript, `npm run typecheck && npm run lint`).

## Global Constraints

- Backend work on a feature branch off `development` (Comet-Backend deploys from `development`). Dashboard work on `main` in the monorepo. Commit per task. Never force-push.
- Gate on `AccountCapabilities` (`can_use_booking`, `can_book_storewide`), never on `account_type`.
- No new abstractions beyond the files listed here. No reformatting of untouched code. Match the Booksy/Treatwell connector idiom exactly.
- After ANY change under `app/Catalog/Definitions/*`: `php artisan catalog:compile && php artisan routing:corpus`, commit `bootstrap/catalog/compiled.php` and `tests/fixtures/Routing/corpus-generated.php` with the change. `tests/Unit/Catalog/CatalogArtefactTest.php` fails otherwise.
- Do NOT build against Square's official Bookings API. It requires the seller to grant OAuth to a Square app, and seller-level booking creation additionally requires the seller to be on Appointments Plus or Premium. Everything in this plan reads Square's public, unauthenticated buyer surface.
- Do NOT add a reviews stream for Square. The booking page carries none; Google Business reviews already cover it.
- Do NOT change the Fresha flows except where Task 6 generalises the dashboard team step (Fresha keeps working byte-for-byte).

---

## 0. Evidence — read before touching anything

### 0.1 The bug on the `jessejensz` build (2026-09-01 22:58 UTC)

User `01a05f31-d686-7143-a16b-ddda737c691b`, handle `jessejensz`, `account_type=partna`, `sector=barber`, `status=unclaimed`. Built from Instagram `certifiedbarberboy` (bio website `linktr.ee/jessejenszhair`) + Google Business place `ChIJza0QVMRp1moRbAhUmX6J27M` (AKRO STUDIO, Elsternwick).

| Time (UTC) | Lane | What happened |
|---|---|---|
| 22:58:31 | Linktree unroll (`LinkInBioImporter`, origin `link_in_bio`) | `https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z` projected to `square.book`, detector `8991ee8ff4aeb1d5` (host-only `squareup.com` rule), **confidence 32**, verdict `note`, block_reason `below_threshold`. Written as a `content.items` link item `a8d653c6-8e6c-44b1-9320-723462dd4236` on the manual source. |
| 22:58:35 | Google Business booking seed (`GoogleBusinessAutoSync::seedBooking`) | `https://akro-studio.square.site/` written as the Square booking connection `01a05f32-1dc7-7388-97a2-4490338b35be` (surface `square.book`, routing_class `booking`, source `google-business`). It also provisioned `ingest.sources` row `f59af8c7-…` with `source_key=square` — the Square Online **menu** scraper — against a barbershop. |

Why 32: `LinkProjector::score()` (`app/Routing/LinkProjector.php:159-187`) starts a host-only match at 40, subtracts 8 for a deep path on a rule with no path pattern, adds `round((strength-50)/5)` = 0 for `ProfileLink`. Booking's bars are suggest 55 / auto 80 (`app/Routing/RoutingPolicy.php:24`), minus a 10-point indirect penalty for harvested links. Fresha's detector (`app/Catalog/Definitions/Fresha.php:63-67`) has a path pattern and lands at 75, which is why Fresha connects and Square is carded.

The Google lane never scores: `GoogleBusinessAutoSync::resolveBookingWrite()` (`app/Services/Platforms/GoogleBusinessAutoSync.php:384-420`) uses the legacy host regex (`WebsiteLinkHarvester`, `~(^|\.)(squareup\.com|square\.site)$~`), so any Square host becomes the Square connection.

Net effect on the live sitepage: the Book button sends visitors to the studio's generic Square Online site, and Jesse's own team-member booking page sits in the links pool as a plain card.

### 0.2 What Square exposes publicly (verified live 2026-09-02)

**Buyer widget JSON** — the primary data source:

```
GET https://app.squareup.com/appointments/api/buyer/widget/{merchant}?unit_token={unit}
Accept: application/json
```

- 200 `application/json`, 18 KB, with NO auth, NO cookie, NO browser user agent (a Guzzle UA works).
- Without the `Accept: application/json` header it 302s to the HTML booking page, which embeds the identical JSON (HTML-escaped) — usable as a fallback.
- `unit_token` is optional; omitted, it returns the merchant's default location.
- `team_member_id` as a query param does NOT filter the response (still 5 services, 4 staff) — filtering is client-side via `staff_ids`.
- Three rapid repeat calls all 200. Only cookie set is Cloudflare's `__cf_bm`, not required on the next call.

Top-level keys: `id, business, active_business_locations, enabled_business_locations_by_service_staff, services, modifier_lists, staff, fees, categories, seller_brand, business_location_id, unit_active, unit_token, giftcard_url, images, locations`.

Field shapes (from merchant `7rn54rnv21ng7n`, location `LAJZK7J54JGCW`):

```
business:      id, uid, merchant_token (MLSE36V5ANGCZ), name, booking_site_url, phone, email,
               timezone (Australia/Melbourne), currency_code (AUD), any_staff_booking_enabled,
               skip_obs_staff_selection, multiple_staff_booking_enabled, profile_image.url, …56 keys
active_business_locations[0]: unit_token, id, name, nickname, street_address, city_state_zip,
               booking_flow_url (https://app.squareup.com/appointments/book/{merchant}/{unit}/start),
               website_url, instagram_url, timezone, all_hours, categories_enabled
services[]:    id (= item_token, e.g. JGQS7AK63SUIASWDSCTRSGVK), name, description, description_html,
               ordinal, price_cents, price_type ("fixed"), price_description, time (seconds),
               transition_time, currency_code, category_token, image_tokens, staff_ids[], variations[]
variations[]:  id (= item_variation_token), name, ordinal, price_cents, price_type, service_time,
               staff_ids[], is_visible_in_default_booking, deposit_amount_cents
staff[]:       id (e.g. qgev4xbopoqbvs), first_name, last_name, short_name, long_name,
               employee_token (e.g. TM-qREuvGrHGnJ5Z), bio, profile_image.url
categories[]:  empty on this merchant; expect {id|token, name}
seller_brand:  colors.primary, logos.framed.url
```

**The `team_member_id` in a pasted URL equals `staff[].employee_token`.** Jesse: `id=qgev4xbopoqbvs`, `employee_token=TM-qREuvGrHGnJ5Z`. His services are the ones whose `staff_ids` contains `qgev4xbopoqbvs` (Beard Trim $80/30min, Haircut and Beard Trim $130/60min, Haircut and Style $100/45min). Other tokens look like `TM64y1EDvLId87mq` (no dash), so match on `^TM`.

**URL shapes (all returned 200):**

| URL | Meaning |
|---|---|
| `book.squareup.com/appointments/{merchant}` | merchant root, location chooser |
| `book.squareup.com/appointments/{merchant}/location/{unit}` | location root (services list) |
| `…/location/{unit}/services/{serviceId}` | service preselected |
| `…/location/{unit}/staff` and `…/staff/{staffId}` | staff list / staff preselected |
| `app.squareup.com/appointments/book/{merchant}/{unit}/start` | `booking_flow_url`; redirects to the location root |
| `square.site/book/{unit}/{slug}` and `{slug}.square.site/` | Square Online site; redirects to the bare `.square.site` root (NOT a booking page we can read) |

The booking app reads these query params: `service_id, variation_id, team_member_id, staff_id, category_id, date, start_time, quick_checkout, rebooking, waitlist`. Its availability endpoint (`POST …/appointments/api/buyer/availability`) returned 422 to an unauthenticated call — unproven, out of scope.

**Square's official Bookings API** (for the record): OAuth required; free plan allows reads and buyer-level bookings; seller-level create/update/cancel needs Appointments Plus/Premium (403 otherwise). Not used here. Sources: https://developer.squareup.com/docs/bookings-api/what-it-is , https://developer.squareup.com/reference/square/bookings-api

### 0.3 What Fresha does today (the parity target)

- Connect (`POST /platforms/fresha/connect`, 202 + `/connect/status` poll), team roster (`GET /team` → `{url, team:[{employeeId, displayName, jobTitle, avatarUrl, rating}], suggestedEmployeeId}`), `GET /selection` → `{url, selection:{mode:'employee'|'storewide', employee, services, hiddenServiceIds}}`, `POST /selection {employeeId}`, `POST /selection/storewide`, `POST /service-visibility`, `DELETE /`.
- `FreshaStaffMatcher::match(User, list $team): ?string` fuzzy-matches the account holder's name to a team entry (`employeeId` + `displayName` keys). `FreshaAutoSelector` runs it on auto-routed links.
- Ingest: `FreshaConnector` (source `fresha`, streams `services` Catalogue/deletesOnExhaustive + `reviews`), 2-day cadence, `eagerOnConnect`, per-service deep link `https://www.fresha.com/a/{slug}/booking?employeeId=…&offerItemId=…`.
- Dashboard: `FreshaTeamStep` in `components/blocks/overlays/connection-sheet.tsx:162` (rendered at `:2347` after connect and in `components/blocks/pools/platform-sheet.tsx:269`), queries in `lib/queries/platforms.ts:780-826`.
- Sitepage: the services pool renders one `ItemCard` per service with `sv.url` behind "Book" (`apps/pages/src/pages/[...path].astro:560-575`); the Book page's platform tile links to `payload.url` (`ConnectionProfileUrl`, default branch).

### 0.4 Square today

- Connect exists: `POST /api/platforms/square/connect {url}` (`app/Http/Controllers/Api/Platforms/SquareController.php`), stores the URL verbatim, 409 while Fresha is connected, 403 unless `can_use_booking`. Public payload allowlists only `url`. An active Square connection alone makes the Book page present (`SitepageDataResolverService::PLATFORM_TO_PAGE`).
- `ConnectorRegistry['square']` is `SquareMenuConnector` (Square Online menus). `SourceProvisioner::sourceKeyFor('square.book')` returns the brand `square`, so a `square.book` connection provisions the MENU scraper when its URL is a `square.site` host, and nothing when it is `book.squareup.com`.
- `SquareBinding` (`app/Services/Platforms/Registry/Bindings/SquareBinding.php`): category Booking, `TileConnectionResource`, `SelectionPayload`, url connectInput, `HostMatch`, bespoke routes. Not refreshable, no fetch strategy.
- Dashboard: `lib/data/platforms.ts:104` `square: { feeds: null, accountNoun: "Booking page", refreshable: false }`; roster entry `lib/data/connect.ts:347-355`.

---

## File structure

**Comet-Backend**

| File | Responsibility |
|---|---|
| `app/Catalog/Definitions/Square.php` (modify) | two new deep-link detectors on `square.book` |
| `tests/Unit/Routing/SquareOrderDetectorTest.php` (modify) | pins the deep-link scores |
| `tests/Feature/Routing/LinkInBioImporterTest.php` (modify) | regression: a harvested Square deep link becomes the booking connection |
| `app/Ingest/SourceProvisioner.php` (modify) | surface-specific connector key; `square_book` identifier |
| `tests/Feature/Ingest/SourceProvisionerTest.php` (modify) | provisioning cases |
| `app/Services/Platforms/SquareBookingPage.php` (create) | pure reader of the widget JSON: URL parse, team, services, deep links |
| `tests/Unit/Platforms/SquareBookingPageTest.php` (create) | parser cases on a fixture |
| `app/Ingest/Connectors/SquareBookingConnector.php` (create) | source `square_book`, `services` stream |
| `app/Ingest/Projection/SquareServiceProjector.php` (create) | service record → `service` kind |
| `app/Ingest/ConnectorRegistry.php`, `app/Ingest/Projection/ProjectorRegistry.php` (modify) | registrations |
| `tests/Feature/Ingest/SquareBookingConnectorTest.php` (create) | pull + projector cases |
| `app/Services/Platforms/SquareBookingClient.php` (create) | Http-facade fetch of the widget for controller/job use |
| `app/Jobs/Platforms/SquareAutoSelectJob.php` (create) | name-match the owner to `staff[]` and stamp `team_member_id` |
| `app/Http/Controllers/Api/Platforms/SquareController.php`, `routes/api/platforms.php` (modify) | team / selection / storewide endpoints |
| `app/Services/Platforms/Registry/Bindings/SquareBinding.php` (modify) | refresh cadence |
| `tests/Feature/Platforms/SquareTeamSelectionTest.php` (create) | endpoint cases |

**partna-monorepo/apps/dashboard**

| File | Responsibility |
|---|---|
| `lib/queries/platforms.ts` (modify) | platform-parameterised team/selection queries |
| `components/blocks/overlays/connection-sheet.tsx` (modify) | `BookingTeamStep({platform})`; render for `square` |
| `components/blocks/pools/platform-sheet.tsx` (modify) | team section for `square` |
| `lib/data/platforms.ts`, `lib/data/connect.ts` (modify) | rules + roster copy |

---

### Task 1: Catalog deep-link detector for `square.book`

**Files:**
- Modify: `app/Catalog/Definitions/Square.php:48-51`
- Modify: `tests/Unit/Routing/SquareOrderDetectorTest.php`
- Modify: `tests/Feature/Routing/LinkInBioImporterTest.php`
- Regenerate: `bootstrap/catalog/compiled.php`, `tests/fixtures/Routing/corpus-generated.php`

**Interfaces:**
- Consumes: `Detector::url()->subdomain()->path()->strength()` (`app/Catalog/DetectorBuilder.php:49-91`), `EvidenceStrength::DeepLinkWithSlug` (=70).
- Produces: `square.book` projections with confidence 99 for `book.squareup.com/appointments/…` and `app.squareup.com/appointments/book/…` URLs. No identifier capture — the surface stays `IdentifierKind::Url`, so the reconciler keeps using the canonical URL as `resource_id`/`payload.url` (`app/Routing/SourceReconciler.php:604-623`, `app/Routing/ConnectionPayload.php:57`).

- [ ] **Step 1: Write the failing detector tests**

Append to `tests/Unit/Routing/SquareOrderDetectorTest.php`:

```php
it('places a Square Appointments deep link on square.book above the auto bar', function () {
    $projection = squareProjection('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z');

    expect($projection->matched())->toBeTrue($projection->reason ?? '');
    expect($projection->surfaceKey)->toBe('square.book');
    expect($projection->confidence - RoutingPolicy::indirectPenalty())
        ->toBeGreaterThanOrEqual(RoutingPolicy::autoThreshold('booking'));
    expect($projection->margin)->toBeGreaterThanOrEqual(RoutingPolicy::minMargin());
});

it('places the app.squareup.com booking_flow_url shape on square.book above the auto bar', function () {
    $projection = squareProjection('https://app.squareup.com/appointments/book/7rn54rnv21ng7n/LAJZK7J54JGCW/start');

    expect($projection->surfaceKey)->toBe('square.book');
    expect($projection->confidence - RoutingPolicy::indirectPenalty())
        ->toBeGreaterThanOrEqual(RoutingPolicy::autoThreshold('booking'));
});

it('places a merchant root with no location on square.book', function () {
    $projection = squareProjection('https://book.squareup.com/appointments/7rn54rnv21ng7n');

    expect($projection->surfaceKey)->toBe('square.book');
    expect($projection->confidence)->toBeGreaterThanOrEqual(RoutingPolicy::suggestThreshold('booking'));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Routing/SquareOrderDetectorTest.php`
Expected: the three new cases FAIL on the confidence assertion (current score is 32 / 40).

- [ ] **Step 3: Add the detectors**

In `app/Catalog/Definitions/Square.php`, replace the `->detect(...)` block of the `square.book` surface (lines 48-51) with:

```php
                ->detect(
                    Detector::url('squareup.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('square.site')->strength(EvidenceStrength::ProfileLink),
                    // 2026-09-02: the Square Appointments deep link Square's own
                    // "Book now" buttons and share sheets hand out. On the
                    // host-only rule above it scored 32 (40 − 8 deep-path), under
                    // booking's suggest bar of 55, so jessejensz's team-member
                    // link was carded as a custom link while the studio's bare
                    // square.site root took the Square slot. subdomain 20 + path
                    // 35 + DeepLinkWithSlug 4 on the 40 base = 99 — auto even
                    // after the 10-point indirect penalty. No captures(): the
                    // surface is IdentifierKind::Url and the canonical URL (which
                    // keeps team_member_id) is the identity.
                    Detector::url('squareup.com')
                        ->subdomain('#^book$#')
                        ->path('#^/appointments/(?<merchant>[a-z0-9]{8,32})(?:/location/(?<location>[A-Z0-9]{8,32}))?(?:/(?:services|staff)(?:/[A-Za-z0-9]+)?)?/?$#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                    // The booking_flow_url shape the buyer widget JSON carries.
                    Detector::url('squareup.com')
                        ->subdomain('#^app$#')
                        ->path('#^/appointments/book/(?<merchant>[a-z0-9]{8,32})/(?<location>[A-Z0-9]{8,32})(?:/start)?/?$#i')
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
```

- [ ] **Step 4: Compile the catalog and regenerate the corpus**

Run: `php artisan catalog:compile && php artisan routing:corpus`
Expected: both succeed; `git status` shows `bootstrap/catalog/compiled.php` and `tests/fixtures/Routing/corpus-generated.php` modified.

- [ ] **Step 5: Run the detector and catalog suites**

Run: `php artisan test tests/Unit/Routing/SquareOrderDetectorTest.php tests/Unit/Catalog tests/Feature/Platforms/CatalogClassificationSweepTest.php tests/Feature/Platforms/CatalogBackedClassificationTest.php`
Expected: PASS. (The existing `keeps squareup.com on the booking surface` case still passes: the bare-host URL has no subdomain, so the new rules skip and the host rule claims it.)

- [ ] **Step 6: Write the failing end-to-end import test**

Append to `tests/Feature/Routing/LinkInBioImporterTest.php`:

```php
it('connects a harvested Square Appointments deep link as the booking provider, not a card', function () {
    Queue::fake();
    $pro = createTenant('bio-square');
    bioPage('<html><body>
        <a href="https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z">Book</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/bio-square', 'link_in_bio');

    $row = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('surface_key', 'square.book')->whereNull('deleted_at')->first();
    expect($row)->not->toBeNull()
        ->and($row->routing_class)->toBe('booking')
        ->and($row->is_active)->toBeTrue()
        ->and($row->payload['url'])->toContain('book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW')
        ->and($row->payload['url'])->toContain('team_member_id=TM-qREuvGrHGnJ5Z');

    expect(DB::table('routing.link_observations')->where('surface_key', 'square.book')->value('verdict'))->toBe('place');
});
```

`createTenant()` lives in `tests/Pest.php`. Confirm it creates an `account_type=partna` user (so `can_use_booking` is true); if it does not, create the user with `account_type => 'partna'` the way `squareUser()` in `tests/Feature/Platforms/SquareConnectionTest.php:12-23` does.

- [ ] **Step 7: Run it**

Run: `php artisan test tests/Feature/Routing/LinkInBioImporterTest.php --filter="Square Appointments"`
Expected: PASS (the detector change alone is the fix; this test pins the whole lane).

- [ ] **Step 8: Commit**

```bash
git add app/Catalog/Definitions/Square.php bootstrap/catalog/compiled.php tests/fixtures/Routing/corpus-generated.php tests/Unit/Routing/SquareOrderDetectorTest.php tests/Feature/Routing/LinkInBioImporterTest.php
git commit -m "Catalog: score Square Appointments deep links above booking's auto bar

A book.squareup.com/appointments/… link scored 32 on the host-only rule and
was carded as a custom link (jessejensz build, 2026-09-01). Two path-anchored
detectors on square.book land it at 99."
```

---

### Task 2: A connector of its own for `square.book`

**Files:**
- Modify: `app/Ingest/SourceProvisioner.php` — `sourceKeyFor()` (~line 300), `identifierFor()` match arm (~line 273-375), new private `squareBookingUrl()`
- Modify: `tests/Feature/Ingest/SourceProvisionerTest.php`

**Interfaces:**
- Consumes: `ConnectorRegistry::has(string)`.
- Produces: `sourceKeyFor('square.book') === 'square_book'` once Task 4 registers the connector (until then it still falls back to `square`, so the change is inert). `identifierFor('square_book', $conn)` returns the cleaned booking URL `https://book.squareup.com/appointments/{merchant}[/location/{unit}][?team_member_id=TM…]` or null for a `square.site` root.

- [ ] **Step 1: Write the failing provisioner tests**

Read the top of `tests/Feature/Ingest/SourceProvisionerTest.php` for its `makeConnection()` helper and the exact `status` strings it asserts (`created` / `skipped`), then append:

```php
it('provisions the Square Appointments connector for a square.book deep link, keeping the team member', function () {
    $userId = provisionerUser();  // use whatever user helper this file already uses
    $conn = makeConnection($userId, ['platform' => 'square', 'surface_key' => 'square.book', 'payload' => [
        'url' => 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&color=000000&team_member_id=TM-qREuvGrHGnJ5Z',
    ]]);

    $result = app(SourceProvisioner::class)->sync($conn);

    expect($result['source_key'])->toBe('square_book');
    $row = DB::table('ingest.sources')->where('connection_id', $conn->id)->first();
    expect($row->source_key)->toBe('square_book')
        ->and($row->identifier)->toBe('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TM-qREuvGrHGnJ5Z');
});

it('provisions nothing for a square.book connection whose url is a bare square.site root', function () {
    $userId = provisionerUser();
    $conn = makeConnection($userId, ['platform' => 'square', 'surface_key' => 'square.book', 'payload' => ['url' => 'https://akro-studio.square.site/']]);

    $result = app(SourceProvisioner::class)->sync($conn);

    expect($result['status'])->toBe('skipped')->and($result['reason'])->toBe('no_identifier');
    expect(DB::table('ingest.sources')->where('connection_id', $conn->id)->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Ingest/SourceProvisionerTest.php --filter="square"`
Expected: FAIL — `source_key` is `square` (first case) and the second case currently provisions the menu scraper.

- [ ] **Step 3: Implement**

Replace `sourceKeyFor()` in `app/Ingest/SourceProvisioner.php`:

```php
    public static function sourceKeyFor(string $surfaceKey): ?string
    {
        // A surface may own a connector of its own (square.book → square_book)
        // when its brand's connector serves a DIFFERENT surface (square →
        // SquareMenuConnector serves square.order). Surface-specific first,
        // brand second; the brand fallback is unchanged for every other key.
        if (str_contains($surfaceKey, '.')) {
            $bySurface = str_replace('.', '_', $surfaceKey);
            if (ConnectorRegistry::has($bySurface)) {
                return $bySurface;
            }
        }

        $brand = strstr($surfaceKey, '.', true);
        if ($brand === false || $brand === '') {
            return null;
        }

        return ConnectorRegistry::has($brand) ? $brand : null;
    }
```

Add an arm to the `match ($sourceKey)` in `identifierFor()`, next to the existing `'square' =>` arm:

```php
            // Square Appointments (2026-09-02): the booking page URL itself,
            // cleaned. A bare square.site root carries no merchant id and is
            // NOT a booking page we can read — null, which also ends the menu
            // scrape a booking connection used to provision for that host.
            'square_book' => $this->squareBookingUrl($payload['url'] ?? null)
                ?? $this->squareBookingUrl($resource),
```

Add the helper beside `booksyPath()`:

```php
    /**
     * The Square Appointments booking URL reduced to what identifies the
     * page: merchant, optional location, and the team_member_id the owner's
     * link carries. Presentation params (buttonTextColor, color, locale,
     * referrer) are dropped so a re-paste with different button colours is
     * the same source.
     */
    private function squareBookingUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('~^https?://~i', $value)) {
            return null;
        }
        $parts = parse_url(trim($value));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['book.squareup.com', 'app.squareup.com', 'squareup.com', 'www.squareup.com'], true)) {
            return null;
        }
        if (preg_match('~^/appointments/(?:book/)?([a-z0-9]{8,32})(?:/(?:location/)?([A-Z0-9]{8,32}))?~i', (string) ($parts['path'] ?? ''), $m) !== 1) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $teamMember = isset($query['team_member_id']) && is_string($query['team_member_id'])
            && preg_match('/^TM[A-Za-z0-9_-]{4,64}$/', $query['team_member_id']) === 1
            ? $query['team_member_id'] : null;

        $url = 'https://book.squareup.com/appointments/'.strtolower($m[1]);
        if (isset($m[2]) && $m[2] !== '') {
            $url .= '/location/'.strtoupper($m[2]);
        }

        return $teamMember === null ? $url : $url.'?team_member_id='.rawurlencode($teamMember);
    }
```

- [ ] **Step 4: Run the provisioner suite**

Run: `php artisan test tests/Feature/Ingest/SourceProvisionerTest.php`
Expected: the two new cases still FAIL on `source_key` until Task 4 registers `square_book` (that is expected — mark them `->skip('until Task 4')` for this commit only if the suite must be green per-commit, and un-skip in Task 4). Every existing case PASSES. The existing case at `:554` ("A square BOOKING link is not a scrapeable menu") keeps passing — its URL `squareup.com/appointments/book/abc` has a 3-char slug, so `squareBookingUrl` returns null and the row stays skipped.

- [ ] **Step 5: Commit**

```bash
git add app/Ingest/SourceProvisioner.php tests/Feature/Ingest/SourceProvisionerTest.php
git commit -m "SourceProvisioner: let a surface own its connector; square_book identifier

square.book used to resolve to the brand's connector (SquareMenuConnector)
and scrape a square.site root as a menu. The surface-specific key wins when
registered; a booking URL cleans to merchant/location/team_member_id."
```

---

### Task 3: `SquareBookingPage` — pure reader of the widget JSON

**Files:**
- Create: `app/Services/Platforms/SquareBookingPage.php`
- Create: `tests/Unit/Platforms/SquareBookingPageTest.php`
- Create: `tests/fixtures/square/widget-akro.json` (a trimmed copy of the real document — build it from the field shapes in §0.2; keep 2 staff and 3 services, one service with 2 variations)

**Interfaces:**
- Produces (all static, all pure):
  - `parseUrl(string $url): array{merchant:?string, unit:?string, teamMember:?string}`
  - `widgetUrl(string $merchant, ?string $unit): string`
  - `team(array $doc): list<array{employeeId:string, staffId:string, displayName:string, jobTitle:?string, avatarUrl:?string, bio:?string}>` — `employeeId` IS `employee_token` (the `TM…` value), so the dashboard's picker and the URL param speak the same id.
  - `staffIdFor(array $doc, string $employeeToken): ?string` — the short `staff[].id` used in `staff_ids`.
  - `services(array $doc, ?string $staffId): list<array{service_id:string, name:string, description:?string, price:?float, price_qualifier:'exact'|'from', currency:?string, duration_seconds:?int, category:?string, position:int, category_position:?int}>` — one entry per `services[]` item; when `$staffId` is given only variations whose `staff_ids` contain it count, and a service with none is dropped. `price` = the cheapest counted variation's `price_cents/100`; `price_qualifier` = `'from'` when counted variations carry more than one distinct price; `duration_seconds` = that cheapest variation's `service_time` (fallback: the service's `time`). `currency` = `services[].currency_code` else `business.currency_code`.
  - `unitToken(array $doc): ?string` — `$doc['unit_token']`.
  - `bookingDeepLink(string $merchant, string $unit, string $serviceId, ?string $teamMember): string` → `https://book.squareup.com/appointments/{merchant}/location/{unit}/services/{serviceId}` + `?team_member_id={teamMember}` when given.
  - `business(array $doc): array{name:?string, phone:?string, email:?string, timezone:?string, currency:?string, websiteUrl:?string, instagramUrl:?string, logoUrl:?string}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Services\Platforms\SquareBookingPage;

function squareWidgetDoc(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/square/widget-akro.json'), true);
}

it('parses merchant, location and team member out of every known URL shape', function (string $url, ?string $merchant, ?string $unit, ?string $tm) {
    expect(SquareBookingPage::parseUrl($url))->toBe(['merchant' => $merchant, 'unit' => $unit, 'teamMember' => $tm]);
})->with([
    ['https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services?buttonTextColor=ffffff&team_member_id=TM-qREuvGrHGnJ5Z', '7rn54rnv21ng7n', 'LAJZK7J54JGCW', 'TM-qREuvGrHGnJ5Z'],
    ['https://book.squareup.com/appointments/7rn54rnv21ng7n', '7rn54rnv21ng7n', null, null],
    ['https://app.squareup.com/appointments/book/7rn54rnv21ng7n/LAJZK7J54JGCW/start', '7rn54rnv21ng7n', 'LAJZK7J54JGCW', null],
    ['https://akro-studio.square.site/', null, null, null],
]);

it('lists the team with employee_token as the id the URL param uses', function () {
    $team = SquareBookingPage::team(squareWidgetDoc());
    $jesse = collect($team)->firstWhere('employeeId', 'TM-qREuvGrHGnJ5Z');
    expect($jesse['staffId'])->toBe('qgev4xbopoqbvs')
        ->and($jesse['displayName'])->toBe('Jesse Jensz')
        ->and($jesse['avatarUrl'])->toStartWith('https://');
});

it('narrows services to the ones a staff member can be booked for, cheapest variation first', function () {
    $doc = squareWidgetDoc();
    $mine = SquareBookingPage::services($doc, 'qgev4xbopoqbvs');
    $names = array_column($mine, 'name');
    expect($names)->toContain('Beard Trim')->not->toContain('Buzz Cut');
    $beard = collect($mine)->firstWhere('name', 'Beard Trim');
    expect($beard['price'])->toBe(80.0)
        ->and($beard['price_qualifier'])->toBe('exact')
        ->and($beard['duration_seconds'])->toBe(1800)
        ->and($beard['currency'])->toBe('AUD')
        ->and($beard['service_id'])->toBe('JGQS7AK63SUIASWDSCTRSGVK');
});

it('lands the whole menu with "from" pricing when no staff member is given', function () {
    $all = SquareBookingPage::services(squareWidgetDoc(), null);
    $beard = collect($all)->firstWhere('name', 'Beard Trim');
    expect(count($all))->toBe(3)
        ->and($beard['price'])->toBe(40.0)
        ->and($beard['price_qualifier'])->toBe('from');
});

it('builds the per-service deep link with the team member preselected', function () {
    expect(SquareBookingPage::bookingDeepLink('7rn54rnv21ng7n', 'LAJZK7J54JGCW', 'JGQS7AK63SUIASWDSCTRSGVK', 'TM-qREuvGrHGnJ5Z'))
        ->toBe('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services/JGQS7AK63SUIASWDSCTRSGVK?team_member_id=TM-qREuvGrHGnJ5Z');
});
```

Fixture: `services` must include Beard Trim (`JGQS7AK63SUIASWDSCTRSGVK`, two variations: $80 / 1800s with `staff_ids:["qgev4xbopoqbvs"]`, and $40 / 1800s with `staff_ids:["w0uwg0t2tw5rmf"]`), Haircut and Style ($100, 2700s, Jesse only), Buzz Cut ($40, 1800s, `staff_ids:["w0uwg0t2tw5rmf","7wz95fq66u07sw"]`); `staff` Jesse (`qgev4xbopoqbvs` / `TM-qREuvGrHGnJ5Z`) and Chad (`w0uwg0t2tw5rmf` / `TM64y1EDvLId87mq`); `business.currency_code: "AUD"`; `unit_token: "LAJZK7J54JGCW"`.

- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Unit/Platforms/SquareBookingPageTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Platforms;

/**
 * Pure reader of Square Appointments' buyer widget JSON — what
 * `GET https://app.squareup.com/appointments/api/buyer/widget/{merchant}?unit_token={unit}`
 * returns with `Accept: application/json`, and the same document the booking
 * page embeds. Verified live 2026-09-02 (merchant 7rn54rnv21ng7n): no auth, no
 * cookie, no browser UA; without the Accept header the endpoint 302s to HTML.
 *
 * Shared by SquareBookingConnector (ingest, via Io) and SquareController /
 * SquareAutoSelectJob (via SquareBookingClient) so the two never drift on
 * field names. Square's internal buyer API, not a published contract — the
 * connector reports Unavailable, never throws, when the shape moves.
 */
final class SquareBookingPage
{
    public const WIDGET_URL = 'https://app.squareup.com/appointments/api/buyer/widget/';

    public const BOOK_URL = 'https://book.squareup.com/appointments/';

    /** @return array{merchant: ?string, unit: ?string, teamMember: ?string} */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url(trim($url));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        $merchant = null;
        $unit = null;
        if (preg_match('~^/appointments/(?:book/)?([a-z0-9]{8,32})(?:/(?:location/)?([A-Z0-9]{8,32}))?~i', $path, $m) === 1) {
            $merchant = strtolower($m[1]);
            $unit = isset($m[2]) && $m[2] !== '' ? strtoupper($m[2]) : null;
        }
        $tm = $query['team_member_id'] ?? null;

        return [
            'merchant' => $merchant,
            'unit' => $unit,
            'teamMember' => is_string($tm) && preg_match('/^TM[A-Za-z0-9_-]{4,64}$/', $tm) === 1 ? $tm : null,
        ];
    }

    public static function widgetUrl(string $merchant, ?string $unit): string
    {
        return self::WIDGET_URL.rawurlencode($merchant).($unit === null ? '' : '?unit_token='.rawurlencode($unit));
    }

    public static function bookingDeepLink(string $merchant, string $unit, string $serviceId, ?string $teamMember): string
    {
        $url = self::BOOK_URL.rawurlencode($merchant).'/location/'.rawurlencode($unit).'/services/'.rawurlencode($serviceId);

        return $teamMember === null ? $url : $url.'?team_member_id='.rawurlencode($teamMember);
    }

    public static function unitToken(array $doc): ?string
    {
        return is_string($doc['unit_token'] ?? null) && $doc['unit_token'] !== '' ? $doc['unit_token'] : null;
    }

    /** @return list<array{employeeId:string, staffId:string, displayName:string, jobTitle:?string, avatarUrl:?string, bio:?string}> */
    public static function team(array $doc): array
    {
        $out = [];
        foreach (is_array($doc['staff'] ?? null) ? $doc['staff'] : [] as $s) {
            if (! is_array($s)) {
                continue;
            }
            $token = is_string($s['employee_token'] ?? null) ? $s['employee_token'] : '';
            $id = is_string($s['id'] ?? null) ? $s['id'] : '';
            if ($token === '' || $id === '') {
                continue;
            }
            $name = trim((string) ($s['long_name'] ?? ''))
                ?: trim((string) ($s['short_name'] ?? ''))
                ?: trim(trim((string) ($s['first_name'] ?? '')).' '.trim((string) ($s['last_name'] ?? '')));
            $out[] = [
                'employeeId' => $token,
                'staffId' => $id,
                'displayName' => $name,
                'jobTitle' => null,
                'avatarUrl' => is_string(data_get($s, 'profile_image.url')) ? data_get($s, 'profile_image.url') : null,
                'bio' => is_string($s['bio'] ?? null) && trim($s['bio']) !== '' ? trim($s['bio']) : null,
            ];
        }

        return $out;
    }

    public static function staffIdFor(array $doc, string $employeeToken): ?string
    {
        foreach (self::team($doc) as $member) {
            if ($member['employeeId'] === $employeeToken) {
                return $member['staffId'];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function services(array $doc, ?string $staffId): array
    {
        $currencyDefault = is_string(data_get($doc, 'business.currency_code')) ? data_get($doc, 'business.currency_code') : null;
        $categories = [];
        foreach (is_array($doc['categories'] ?? null) ? $doc['categories'] : [] as $i => $c) {
            if (is_array($c) && is_string($c['name'] ?? null)) {
                $categories[(string) ($c['id'] ?? $c['token'] ?? '')] = ['name' => $c['name'], 'position' => $i];
            }
        }

        $out = [];
        $position = 0;
        foreach (is_array($doc['services'] ?? null) ? $doc['services'] : [] as $svc) {
            if (! is_array($svc)) {
                continue;
            }
            $id = is_string($svc['id'] ?? null) ? $svc['id'] : (is_string($svc['item_token'] ?? null) ? $svc['item_token'] : '');
            $name = is_string($svc['name'] ?? null) ? trim($svc['name']) : '';
            if ($id === '' || $name === '') {
                continue;
            }

            $variations = is_array($svc['variations'] ?? null) && $svc['variations'] !== []
                ? $svc['variations']
                : [['price_cents' => $svc['price_cents'] ?? null, 'service_time' => $svc['time'] ?? null, 'staff_ids' => $svc['staff_ids'] ?? []]];
            $counted = [];
            foreach ($variations as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $staffIds = is_array($v['staff_ids'] ?? null) ? $v['staff_ids'] : [];
                if ($staffId !== null && ! in_array($staffId, $staffIds, true)) {
                    continue;
                }
                if (($v['is_visible_in_default_booking'] ?? true) === false) {
                    continue;
                }
                $counted[] = [
                    'price' => is_numeric($v['price_cents'] ?? null) ? ((int) $v['price_cents']) / 100 : null,
                    'seconds' => is_numeric($v['service_time'] ?? null) ? (int) $v['service_time'] : (is_numeric($svc['time'] ?? null) ? (int) $svc['time'] : null),
                ];
            }
            if ($counted === []) {
                continue;
            }
            usort($counted, static fn ($a, $b) => ($a['price'] ?? PHP_FLOAT_MAX) <=> ($b['price'] ?? PHP_FLOAT_MAX));
            $prices = array_values(array_unique(array_filter(array_column($counted, 'price'), static fn ($p) => $p !== null)));

            $categoryKey = is_string($svc['category_token'] ?? null) ? $svc['category_token'] : null;
            $category = $categoryKey !== null ? ($categories[$categoryKey] ?? null) : null;
            $description = is_string($svc['description'] ?? null) ? trim($svc['description']) : '';

            $out[] = array_filter([
                'service_id' => $id,
                'name' => $name,
                'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
                'price' => $counted[0]['price'],
                'price_qualifier' => count($prices) > 1 ? 'from' : 'exact',
                'currency' => is_string($svc['currency_code'] ?? null) ? $svc['currency_code'] : $currencyDefault,
                'duration_seconds' => $counted[0]['seconds'],
                'category' => $category['name'] ?? null,
                'category_position' => $category['position'] ?? null,
                'position' => $position++,
            ], static fn ($v) => $v !== null);
        }

        return $out;
    }

    /** @return array{name:?string, phone:?string, email:?string, timezone:?string, currency:?string, websiteUrl:?string, instagramUrl:?string, logoUrl:?string} */
    public static function business(array $doc): array
    {
        $loc = is_array($doc['active_business_locations'][0] ?? null) ? $doc['active_business_locations'][0] : [];
        $str = static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $str(data_get($doc, 'business.name')),
            'phone' => $str(data_get($doc, 'business.phone')),
            'email' => $str(data_get($doc, 'business.email')),
            'timezone' => $str(data_get($doc, 'business.timezone')),
            'currency' => $str(data_get($doc, 'business.currency_code')),
            'websiteUrl' => $str($loc['website_url'] ?? null),
            'instagramUrl' => $str($loc['instagram_url'] ?? null),
            'logoUrl' => $str(data_get($doc, 'business.profile_image.url')) ?? $str(data_get($doc, 'seller_brand.logos.framed.url')),
        ];
    }
}
```

Note: `position` counts only emitted services, so a staff-filtered list is contiguous. `price_qualifier` is always set (exact/from) — the projector reads it.

- [ ] **Step 4: Run** — `php artisan test tests/Unit/Platforms/SquareBookingPageTest.php` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/SquareBookingPage.php tests/Unit/Platforms/SquareBookingPageTest.php tests/fixtures/square/widget-akro.json
git commit -m "SquareBookingPage: pure reader of Square Appointments' buyer widget JSON"
```

---

### Task 4: `SquareBookingConnector` + `SquareServiceProjector`

**Files:**
- Create: `app/Ingest/Connectors/SquareBookingConnector.php`
- Create: `app/Ingest/Projection/SquareServiceProjector.php`
- Modify: `app/Ingest/ConnectorRegistry.php:62-93` (add `'square_book' => SquareBookingConnector::class` after `'square'`)
- Modify: `app/Ingest/Projection/ProjectorRegistry.php:26-60` (add `'square_book' => ['services' => SquareServiceProjector::class]`)
- Create: `tests/Feature/Ingest/SquareBookingConnectorTest.php`
- Un-skip the two Task 2 provisioner cases if they were skipped.

**Interfaces:**
- Consumes: `SquareBookingPage::*` (Task 3); `Io::get(string $url, array $headers)` → `['status','body','headers']`; messages `Record(stream, key, doc)`, `Covered('services', Coverage::exhaustive())`, `Note(code, text)`, `Unavailable(text, ?status)`.
- Produces: `service` records with keys `service_id, name, description?, price?, price_qualifier, currency?, duration_seconds?, category?, category_position?, position, url`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Ingest\Connectors\SquareBookingConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SquareServiceProjector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function squareBookIo(array $response): Io
{
    return new class($response) implements Io
    {
        public array $gets = [];

        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            $this->gets[] = ['url' => $url, 'headers' => $headers];

            return $this->response;
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function squareBookPage(): array
{
    return ['status' => 200, 'headers' => [], 'body' => file_get_contents(dirname(__DIR__, 2).'/fixtures/square/widget-akro.json')];
}

function squareBookPull(string $identifier): Pull
{
    return new Pull(identifier: $identifier, stream: SquareBookingConnector::manifest()->stream('services'), config: []);
}

const JESSE_URL = 'https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TM-qREuvGrHGnJ5Z';

it('asks the widget endpoint for JSON and lands only the team member\'s services with deep links', function () {
    $io = squareBookIo(squareBookPage());
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull(JESSE_URL), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->gets[0]['url'])->toBe('https://app.squareup.com/appointments/api/buyer/widget/7rn54rnv21ng7n?unit_token=LAJZK7J54JGCW')
        ->and($io->gets[0]['headers']['Accept'] ?? null)->toBe('application/json');
    expect(array_column(array_map(fn ($r) => $r->doc, $records), 'name'))->toBe(['Beard Trim', 'Haircut and Style']);
    expect($records[0]->key)->toBe('JGQS7AK63SUIASWDSCTRSGVK')
        ->and($records[0]->doc['price'])->toBe(80.0)
        ->and($records[0]->doc['duration_seconds'])->toBe(1800)
        ->and($records[0]->doc['url'])->toBe('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services/JGQS7AK63SUIASWDSCTRSGVK?team_member_id=TM-qREuvGrHGnJ5Z');
    expect(collect($messages)->first(fn ($m) => $m instanceof Covered))->not->toBeNull();
});

it('lands the whole menu when the url names no team member', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW'), squareBookIo(squareBookPage())), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($records)->toHaveCount(3)
        ->and($records[0]->doc['price_qualifier'])->toBe('from')
        ->and($records[0]->doc['url'])->not->toContain('team_member_id');
});

it('notes an unknown team member and falls back to the whole menu', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull('https://book.squareup.com/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW?team_member_id=TMnobody000'), squareBookIo(squareBookPage())), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Note)?->code)->toBe('team_member_not_found');
    expect(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(3);
});

it('reports unavailable when the endpoint answers with the HTML page instead of JSON', function () {
    $messages = iterator_to_array((new SquareBookingConnector)->pull(squareBookPull(JESSE_URL), squareBookIo(['status' => 200, 'headers' => [], 'body' => '<!doctype html><html></html>'])), false);

    expect($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('projects a service record into the service kind with offer, duration and link', function () {
    $projector = new SquareServiceProjector;
    $view = new RecordView([
        'service_id' => 'JGQS7AK63SUIASWDSCTRSGVK', 'name' => 'Beard Trim', 'price' => 80.0, 'price_qualifier' => 'exact',
        'currency' => 'AUD', 'duration_seconds' => 1800, 'url' => 'https://book.squareup.com/appointments/x/location/y/services/z',
    ], 'JGQS7AK63SUIASWDSCTRSGVK');

    $item = $projector->project($view);

    expect($item['kind'])->toBe('service')
        ->and($item['headline'])->toBe('Beard Trim')
        ->and($item['offers'][0])->toMatchArray(['channel' => 'square', 'qualifier' => 'exact', 'amount_minor' => 8000, 'currency' => 'AUD'])
        ->and($item['facets']['f_duration']['seconds'])->toBe(1800)
        ->and($item['facets']['f_link']['url'])->toContain('/services/z');
    expect(ProjectorRegistry::for('square_book', 'services'))->toBeInstanceOf(SquareServiceProjector::class);
});
```

Check `Note`'s public property name (`code`?) and `ProjectorRegistry::for()`'s real name in `app/Ingest/Message/Note.php` / `app/Ingest/Projection/ProjectorRegistry.php` before running; adjust the two assertions to the real API.

- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Ingest/SquareBookingConnectorTest.php` → FAIL (classes missing).

- [ ] **Step 3: Implement the connector**

```php
<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Services\Platforms\SquareBookingPage;

/**
 * Square Appointments (2026-09-02). One free GET of the buyer widget JSON
 * (SquareBookingPage's docblock has the contract) lands the booking page's
 * services as the `service` kind, narrowed to the team member the
 * connection URL's team_member_id names — that param IS the selection, so
 * there is no selection_ref: change the URL, and the provisioner re-dates
 * the source. Catalogue-with-deletes, Fresha's semantics: the document is
 * the whole menu. Square Online MENUS are a different surface
 * (square.order) on the brand connector (SquareMenuConnector).
 */
class SquareBookingConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('square_book'),
            identifierKind: 'url',
            hosts: ['app.squareup.com', 'book.squareup.com'],
            streams: [
                'services' => new StreamSpec(
                    name: 'services',
                    target: 'service',
                    profile: SourceProfile::Catalogue,
                    requires: ['name'],
                    volatile: [],
                    orderField: null,
                    deletesOnExhaustive: true,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $parsed = SquareBookingPage::parseUrl($pull->identifier);
        if ($parsed['merchant'] === null) {
            yield new Unavailable('square booking url carries no merchant id');

            return;
        }

        $response = $io->get(SquareBookingPage::widgetUrl($parsed['merchant'], $parsed['unit']), ['Accept' => 'application/json']);
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("square widget returned {$response['status']}", $response['status']);

            return;
        }

        $doc = json_decode((string) $response['body'], true);
        if (! is_array($doc) || ! is_array($doc['services'] ?? null)) {
            yield new Unavailable('square widget response carried no services[] — shape may have changed', $response['status']);

            return;
        }

        $teamMember = $parsed['teamMember'];
        $staffId = null;
        if ($teamMember !== null) {
            $staffId = SquareBookingPage::staffIdFor($doc, $teamMember);
            if ($staffId === null) {
                yield new Note('team_member_not_found', "team member {$teamMember} is not on this booking page; landing the whole menu");
                $teamMember = null;
            }
        }

        $unit = $parsed['unit'] ?? SquareBookingPage::unitToken($doc);
        $items = SquareBookingPage::services($doc, $staffId);
        if ($items === []) {
            yield new Note('empty_menu', 'No bookable services on the Square booking page');

            return;
        }

        foreach ($items as $item) {
            $item['url'] = $unit === null
                ? $pull->identifier
                : SquareBookingPage::bookingDeepLink($parsed['merchant'], $unit, $item['service_id'], $teamMember);
            yield new Record('services', $item['service_id'], $item);
        }

        yield new Covered('services', Coverage::exhaustive());
    }
}
```

- [ ] **Step 4: Implement the projector** (copy of `TreatwellServiceProjector` with the qualifier read from the record):

```php
<?php

namespace App\Ingest\Projection;

use App\Ingest\Connectors\FreshaConnector;

/**
 * Square Appointments service → the `service` kind (2026-09-02). Price
 * arrives in cents already converted to a float by SquareBookingPage; the
 * qualifier ('exact' | 'from') is the connector's call because only it sees
 * the variations. Categories carry vendor tokens but the projector keeps the
 * id-less tag rule the Treatwell projector documents.
 */
class SquareServiceProjector implements Projector
{
    public static function version(): int
    {
        return 1;
    }

    public static function kind(): string
    {
        return 'service';
    }

    public function project(RecordView $view): ?array
    {
        $name = $view->string('name');
        if ($name === null) {
            return null;
        }

        $price = $view->float('price');
        $currency = $view->string('currency');
        $currency = is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
        $qualifier = $view->string('price_qualifier') === 'from' ? 'from' : 'exact';
        $offer = $price === null ? null : [
            'channel' => 'square',
            'qualifier' => $price === 0.0 ? 'free' : $qualifier,
            'amount_minor' => (int) round($price * 100),
            'currency' => $currency,
        ];

        $seconds = $view->int('duration_seconds');
        $category = $view->string('category');

        return [
            'kind' => self::kind(),
            'headline' => $name,
            'facets' => array_filter([
                'f_text' => $view->string('description') === null ? null : ['body' => mb_substr((string) $view->string('description'), 0, FreshaConnector::MAX_TEXT_LENGTH)],
                'f_link' => $view->string('url') === null ? null : ['url' => $view->string('url')],
                'f_duration' => $seconds === null || $seconds <= 0 ? null : ['seconds' => $seconds],
            ]),
            'tags' => $category === null ? [] : [['tag' => $category, 'tag_type' => 'category']],
            'offers' => $offer === null ? [] : [$offer],
        ];
    }
}
```

Confirm the `offers[].qualifier` vocabulary accepts `'from'` by grepping `'from'` in `app/Services/Content` / the offers landing code; the sitepage already renders `qualifier === 'from'` as "From $X" (`apps/pages/src/pages/[...path].astro:545-551`).

- [ ] **Step 5: Register**

`app/Ingest/ConnectorRegistry.php`: add `use App\Ingest\Connectors\SquareBookingConnector;` and `'square_book' => SquareBookingConnector::class,` directly after the `'square' => SquareMenuConnector::class,` line.
`app/Ingest/Projection/ProjectorRegistry.php`: add `'square_book' => ['services' => SquareServiceProjector::class],` in alphabetical position.

- [ ] **Step 6: Run** — `php artisan test tests/Feature/Ingest/SquareBookingConnectorTest.php tests/Unit/Ingest/ConnectorRegistryTest.php tests/Feature/Ingest/SourceProvisionerTest.php` → PASS (including the two Task 2 cases, now un-skipped).

- [ ] **Step 7: Commit**

```bash
git add app/Ingest/Connectors/SquareBookingConnector.php app/Ingest/Projection/SquareServiceProjector.php app/Ingest/ConnectorRegistry.php app/Ingest/Projection/ProjectorRegistry.php tests/Feature/Ingest/SquareBookingConnectorTest.php tests/Feature/Ingest/SourceProvisionerTest.php
git commit -m "Ingest: square_book connector lands a Square Appointments page's services

One unauthenticated GET of Square's buyer widget JSON; services narrowed to
the team member the connection URL names; per-service deep links."
```

---

### Task 5: Team, selection and auto-select for Square (backend)

**Files:**
- Create: `app/Services/Platforms/SquareBookingClient.php`
- Create: `app/Jobs/Platforms/SquareAutoSelectJob.php`
- Modify: `app/Http/Controllers/Api/Platforms/SquareController.php`
- Modify: `routes/api/platforms.php:64-73`
- Modify: `app/Services/Platforms/Registry/Bindings/SquareBinding.php`
- Modify: `app/Routing/SourceReconciler.php` (the `fresha.book` auto-connect block around `:205-240`), `app/Services/Platforms/LinkRouter.php` (`seedBooking`, after `$outcome`), `app/Services/Platforms/GoogleBusinessAutoSync.php` (`seedBooking`, after `$findings`) — dispatch the job for Square where Fresha dispatches `dispatchAutoBookingConnect`.
- Create: `tests/Feature/Platforms/SquareTeamSelectionTest.php`

**Interfaces:**
- `SquareBookingClient::widget(string $merchant, ?string $unit): array` — `Http::withHeaders(['Accept' => 'application/json'])->timeout((float) config('partna.http_fetch.connect_budget_seconds', 20))->get(SquareBookingPage::widgetUrl(...))`; throws `\RuntimeException` with a user-safe message on non-200 / non-JSON / missing `services[]`. Route the URL through the same `SafeUrl` guard `FreshaScraper` uses (grep `SafeUrlException` in `app/Services/Platforms/FreshaScraper.php`).
- Endpoints (all under the existing `{$base}/square` group, same middleware as Fresha's):
  - `GET /team` → `{url, team: SquareBookingPage::team($doc), suggestedEmployeeId: parsed teamMember ?? FreshaStaffMatcher::match($user, $team)}`. 404 when no URL saved; 422 when the saved URL parses to no merchant (a `square.site` root): message `That Square link is a store page, not a booking page — paste the "Book now" link from Square.`
  - `GET /selection` (exists; extend) → `{url, selection: {mode: teamMember ? 'employee' : 'storewide', employee: {employeeId, displayName, avatarUrl} | null}, autoSelected?, matchTier?}`. `employee` comes from `payload.selection.employee` when stored, else `{employeeId: teamMember}`.
  - `POST /selection {employeeId}` (rules: `required|string|max:80|regex:/^TM[A-Za-z0-9_-]{4,64}$/`) → fetch widget, 404 `That team member was not found on the saved Square page.` if absent; rewrite `payload.url` to `https://book.squareup.com/appointments/{merchant}/location/{unit}?team_member_id={employeeId}` (unit from the URL else `unitToken($doc)`), write `payload.selection = {mode:'employee', employee: {employeeId, displayName, avatarUrl}}` via `writeConnection`, under `withConnectionLock`. The provisioner's identifier changes → `ingest.sources` re-dates → eager run lands the employee's services.
  - `POST /selection/storewide` → 403 unless `can_book_storewide`; rewrite the URL without `team_member_id`; `selection = {mode:'storewide', employee:null}`.
- `SquareAutoSelectJob(string $userId)`: read the square connection; if the URL already has `team_member_id`, or `can_book_storewide` is true, return; else fetch the widget, `FreshaStaffMatcher::matchWithTier($user, SquareBookingPage::team($doc))`; on a hit rewrite the URL as `/selection` does and store `autoSelected: true, matchTier`. Log `square.auto_selection` with `user_id, mode, match_tier`. Every failure is caught and reported; nothing else changes. Dispatch `->afterCommit()` from: `SquareController::connect` (after `writeConnection`), `LinkRouter::seedBooking` (when `$outcome->outcome === 'seeded' && $platform === Platform::Square->value && $ctx->autoConnectBooking`), `GoogleBusinessAutoSync::seedBooking` (same shape as its Fresha branch), and the `SourceReconciler` `fresha.book` block extended to `square.book`.
- `SquareBinding`: add `->refreshable()->refreshEvery((int) config('partna.refresh.intervals.square', 2 * 86400))`. Check how `RefreshController` refreshes a platform that has an ingest connector but no `FetchStrategy` (Booksy/Treatwell are derived descriptors in the same position) and mirror it; if refresh needs a strategy, leave `refreshable()` off and note it.

- [ ] **Step 1: Write the failing endpoint tests** — mirror `tests/Feature/Platforms/SquareConnectionTest.php` (its `squareUser()` helper) and `Http::fake(['app.squareup.com/*' => Http::response(file_get_contents(fixture), 200, ['Content-Type' => 'application/json'])])`:
  - `team` returns Jesse with `employeeId TM-qREuvGrHGnJ5Z` and `suggestedEmployeeId` = the URL's team member.
  - `team` suggests by name when the URL has none (user `display_name` "Jesse Jensz").
  - `team` 422s for a `square.site` root.
  - `POST /selection` rewrites the stored URL and 404s for an unknown id.
  - `POST /selection/storewide` 403s for a partna and strips the param for a business.
  - `SquareAutoSelectJob` stamps `team_member_id` for a partna whose name matches exactly one staff member, and leaves the URL alone when two match or the account can book storewide.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the client, controller methods, routes, job, binding, and the three dispatch sites. Keep `SquareController::connect`'s XOR lock and 403 exactly as they are.
- [ ] **Step 4: Run** — `php artisan test --filter=Square` plus `tests/Feature/Platforms/BookingXorConnectRaceTest.php tests/Feature/Platforms/PublicIntegrationAllowlistTest.php tests/Feature/Platforms/Registry/RegistryConnectCoverageTest.php` (the public allowlist must still emit only `url` for square — `selection` stays private).
- [ ] **Step 5: `vendor/bin/pint --dirty` (or the repo's lint command) and commit.**

```bash
git commit -m "Square: team roster, team-member selection and auto-select, Fresha's shape"
```

---

### Task 6: Dashboard — one booking team step for Fresha and Square

**Files:**
- Modify: `partna-monorepo/apps/dashboard/lib/queries/platforms.ts:774-826`
- Modify: `partna-monorepo/apps/dashboard/components/blocks/overlays/connection-sheet.tsx:162` and `:2347`
- Modify: `partna-monorepo/apps/dashboard/components/blocks/pools/platform-sheet.tsx:269`
- Modify: `partna-monorepo/apps/dashboard/lib/data/platforms.ts:104`, `lib/data/connect.ts:347-355`

- [ ] **Step 1: Queries.** Add `export type BookingPlatform = "fresha" | "square";` and platform-parameterised functions; keep the Fresha-named exports as one-line wrappers so no other call site changes:

```ts
export async function fetchBookingTeam(platform: BookingPlatform): Promise<FreshaTeam> {
  const body = await api<{ team?: FreshaTeamMember[]; suggestedEmployeeId?: string | null }>(`/platforms/${platform}/team`);
  return { team: body.team ?? [], suggestedEmployeeId: body.suggestedEmployeeId ?? null };
}
export const fetchFreshaTeam = () => fetchBookingTeam("fresha");

export async function fetchBookingSelection(platform: BookingPlatform): Promise<FreshaSelection | null> {
  const { selection } = await api<{ selection: FreshaSelection | null }>(`/platforms/${platform}/selection`);
  return selection;
}
export const fetchFreshaSelection = () => fetchBookingSelection("fresha");

export function saveBookingSelection(platform: BookingPlatform, employeeId: string) {
  return api(`/platforms/${platform}/selection`, { method: "POST", body: { employeeId } });
}
export const saveFreshaSelection = (employeeId: string) => saveBookingSelection("fresha", employeeId);

export function saveBookingStorewide(platform: BookingPlatform) {
  return api(`/platforms/${platform}/selection/storewide`, { method: "POST" });
}
export const saveFreshaStorewide = () => saveBookingStorewide("fresha");
```

- [ ] **Step 2: Component.** Rename `FreshaTeamStep` to `BookingTeamStep({ platform }: { platform: BookingPlatform })`, replacing its four Fresha calls with the parameterised ones, and export `FreshaTeamStep = () => <BookingTeamStep platform="fresha" />` so existing imports keep working. Copy stays platform-neutral ("Who do bookings go to?" already is). At `connection-sheet.tsx:2347` render `<BookingTeamStep platform={selected} />` when `selected === "fresha" || selected === "square"`. At `platform-sheet.tsx:269` render the Team member section for `platform.key === "fresha" || platform.key === "square"` with `<BookingTeamStep platform={platform.key} />`.
- [ ] **Step 3: Data.** `lib/data/platforms.ts:104` → `square: { feeds: "Services", accountNoun: "Booking page", refreshable: true, refreshEvery: 2 * DAY, multiAccount: false }` (set `refreshable` to whatever Task 5 landed on the backend). `lib/data/connect.ts:352` facts → `[{ icon: "menu", text: "Shows your services and prices" }, { icon: "book", text: "Adds a Book button linking to your Square page" }]`, and `input: url("Booking link", "book.squareup.com/appointments/…")`.
- [ ] **Step 4: Verify.** `cd ~/Developer/partna-monorepo && npm run typecheck && npm run lint`. Then, with the dev server (launch config `dashboard`), connect a Square link on a test account, confirm the team step lists the staff with photos, pick one, and confirm the Services page fills after the eager run.
- [ ] **Step 5: Commit.**

```bash
git commit -m "Dashboard: booking team step serves Square as well as Fresha"
```

---

### Task 7: Repair `jessejensz` and deploy

Backend deploys from `development` (Laravel Cloud). Dashboard deploys on push to `main` (Vercel). Order: backend first (Tasks 1-5), then dashboard (Task 6).

- [ ] **Step 1: After the backend deploy, on dev:** remove the wrong Square row and the stray menu source, then re-import the Linktree so the deep link routes through the fixed catalog.

```bash
cloud env:logs partna development --minutes 10   # baseline first
```

Via tinker (`php artisan tinker` on the environment, or laravel-boost's tinker):

```php
$u = '01a05f31-d686-7143-a16b-ddda737c691b';
\App\Models\Core\Site\IntegrationConnection::where('id', '01a05f32-1dc7-7388-97a2-4490338b35be')->first()?->delete();   // akro-studio.square.site as "booking"
DB::table('content.items')->where('id', 'a8d653c6-8e6c-44b1-9320-723462dd4236')->update(['removed_at' => now()]);           // the custom link card
\App\Jobs\Platforms\LinkInBioScanJob::dispatchSync($u, 'https://linktr.ee/jessejenszhair');   // check the job's real constructor first
```

- [ ] **Step 2: Verify** in `site.platform_connections`: one active `square.book` row with `payload.url` containing `team_member_id=TM-qREuvGrHGnJ5Z`; in `ingest.sources`: a `square_book` row and NO `square` row for this user; in `content.items`: three `service` items (Beard Trim, Haircut and Beard Trim, Haircut and Style) with `f_link.url` deep links. Load `jessejensz.partna.au` and confirm the Book page lists them and each "Book" opens Square with Jesse preselected.

---

## Open questions for the owner (answer before Task 5; Tasks 1-4 are unaffected)

1. **Google Business seeding a bare `square.site` root as the Square booking connection.** Today `resolveBookingWrite()` treats any Square host as Square Appointments. A `square.site` root is a Square Online site with no merchant id; the connector can read nothing from it, and it took the slot Jesse's real link should have had. Options: (a) leave it, relying on link order; (b) route a bare `square.site` root to the branded `direct.book` card instead of the Square slot (one branch in `resolveBookingWrite`, mirroring the `direct.book` fallback already there); (c) probe the root's HTML once for a `book.squareup.com/appointments/{merchant}` link and store that instead. Recommendation: (b) now, (c) as a follow-up.
2. **Variations.** One pool item per service with "From $X" (this plan) versus one item per variation. Recommendation: per service; Fresha does the same.
3. **No name match and no `team_member_id` on a partna account.** Land the whole menu (Fresha's storewide fallback) — confirm.
4. **Refresh button.** Whether Square should be manually refreshable in the dashboard depends on how `RefreshController` treats connector-only platforms (Task 5 note).

## Out of scope

- Square's official Bookings API / OAuth, availability search, or booking inside Partna.
- A reviews stream for Square (none exists; Google Business covers it).
- Renaming `FreshaStaffMatcher` to something platform-neutral (reuse as-is).
- Per-service hide for Square (the generic pool curation in `site.section_items` already applies to every pool; Fresha's `hiddenServiceIds` blob is Fresha-only).
- Square Online menu scraping (`square.order`) — untouched.

## If you get stuck

Write `docs/2026-09-02-square-blocked.md` with the task number, the failing command and its output, and stop. Do not widen scope to get past a failure.

---

## Appendix A: raw evidence

**Routing observation (the smoking gun)** — `routing.link_observations` id `6a956122-77ea-41bb-ad27-5337ee4d3b9a`:
`source=link_in_bio, surface_key=square.book, detector_id=8991ee8ff4aeb1d5, confidence=32, margin=32, verdict=note, block_reason=below_threshold, evidence={path:"/appointments/7rn54rnv21ng7n/location/LAJZK7J54JGCW/services", subdomain:"book", query_keys:[buttonTextColor,color,team_member_id]}, canonical_url keeps team_member_id (locale/referrer stripped), observed_at 2026-09-01T22:58:31Z, catalog_digest sha256:2170e7f9…`

The same import saw `https://akro-studio.square.site/` at confidence 40 (`tenant_scoped`, below_threshold) via `website_import`; the Google lane wrote it anyway.

**Rows to clean up** (dev DB, user `01a05f31-d686-7143-a16b-ddda737c691b`): connection `01a05f32-1dc7-7388-97a2-4490338b35be` (square.book, url `https://akro-studio.square.site/`, source google-business); ingest source `f59af8c7-8405-4525-9fc6-230271f42348` (source_key `square`, identifier `https://akro-studio.square.site`); content item `a8d653c6-8e6c-44b1-9320-723462dd4236` (kind link, manual source `b147d9e8-c01f-4a41-9789-30c4d39733bb`, url = the full deep link).

**Scoring arithmetic** (`app/Routing/LinkProjector.php:159-187`): base 40; subdomain match +20; path match +35, or −8 when a host-only rule meets a deep path; each required query param +15; `+ round((strength − 50) / 5)` (ProfileLink 50 → 0, DeepLinkWithSlug 70 → +4). `PlacementPolicy` subtracts 10 for any non-paste origin, then: ≥ auto (booking 80) and margin ≥ 10 → Place; ≥ suggest (55) → Place on indirect origins, Choose on paste; else Note (a card).

**Fresha reference files:** `app/Ingest/Connectors/FreshaConnector.php`, `app/Services/Platforms/FreshaAutoSelector.php`, `FreshaStaffMatcher.php`, `Strategies/Fetch/FreshaConnectFetch.php`, `app/Http/Controllers/Api/Platforms/FreshaController.php`, `app/Services/Platforms/Registry/Bindings/FreshaBinding.php`, `app/Ingest/Projection/TreatwellServiceProjector.php` (the duration-aware service projector), `app/Ingest/Connectors/BooksyConnector.php` (the closest connector idiom), `tests/Feature/Ingest/BooksyConnectorTest.php` (the fake-Io test idiom).
