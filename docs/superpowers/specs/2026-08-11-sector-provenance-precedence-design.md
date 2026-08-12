# Sector Provenance Precedence

**Date:** 2026-08-11
**Status:** Design approved (rev 4, post third independent review), implementation pending
**Origin:** `jesshairstylist` — Instagram returned the category "Artist" for a Prahran hairdresser, and the taxonomy deliberately declined to map it (commit `e47265d2c`, "a sticky guess blocks Google"). The decline was correct given the precedence rules as they stood; this spec changes those rules so the decline is no longer necessary.
**Scope:** `core.users.sector` and its provenance column `core.users.sector_source` — the precedence rules governing writes to them, and the Instagram classification path that feeds them. `IdentitySync::applyWorkplaceFields` and the `field_sources` map are **out of scope** and unchanged.

**Revision note.** Revs 1 and 2 were each reviewed by two independent agents (premise verification + adversarial design); rev 3 by one, narrowly scoped to its new material. Each revision folds in the prior round's findings. Sections carrying a material correction are marked **[rev2]**, **[rev3]** or **[rev4]**.

Three claims have been outright false and are corrected in place rather than quietly dropped:

1. Rev 1's "nothing self-heals an unclaimed build" — §1.5. There is an unattended writer.
2. Rev 2's tier ordering, which put handle text above the category keyword pass — §3.3.
3. **Rev 1's tier-*major* resolution, which survived three reviews and inverted category primacy — §3.3.** Verified by execution: eight of nine currently-pinned compound inputs regress, three into a food→non-food demotion that the rest of this spec exists to prevent. Rev 4 fixes it by deleting the restructure entirely: tier 1 is now a call to the unmodified `fromInstagramCategory`.

The pattern is worth naming, because it is the same one that produced commit `30e3d3abb` — the bug this spec exists to undo. A plausible reordering of precedence logic, proposed as a fix, changed behaviour nobody was checking. The countermeasure adopted in rev 4 is structural, not procedural: delegate to the existing function so the regression is unrepresentable.

---

## 1. Problem

`core.users.sector_source` is a single column doing two jobs: **provenance** (who wrote this value)
and **authority** (may anyone overwrite it). Three call sites read it as authority, and all three
read it differently.

### 1.1 The business branch

`IdentitySync::applySector` (`app/Services/Platforms/IdentitySync.php:229`), inside `if ($overwrite)`
opened at `:222`:

```php
if ($user->sector_source !== null && $user->sector_source !== self::GOOGLE_SOURCE && $user->sector !== null) {
    return;
}
```

This guard originally protected only `'manual'` — the 2026-07-15 fix for Google reverting a user's
own pick. Commit `30e3d3abb` (2026-07-20) widened it to *every* non-Google source, reasoning that
Google "silently clobbered" an Instagram-seeded sector during the pre-account chain reaction.

That widening is the root cause. On a Business account Google is the authoritative identity source
by design (`AccountCapabilities.php:60`, `google_business_full_sync: $isBusiness`); Google replacing
an Instagram scrape is the better source correcting a guess. The widening conflated "another robot
guessed" with "the human chose".

`30e3d3abb` also *relocated* the guard: before it, the `manual` check sat **outside**
`if ($overwrite)` and protected both account types; after, manual protection on a partna account
rests on `isBlank()` alone, so a `('', 'manual')` row is overwritable by Google today.

**[rev3]** Rev 2 claimed the rewrite "restores the shared behaviour". **It does not** — §3.1's blank
short-circuit returns `true` for `''` before any rank comparison, so `('', 'manual')` stays
overwritable. Severity is low (`UpdateSectorRequest.php:19-20` normalises `''` → null and
`SectorController.php:31` nulls the source, so the row is unreachable through the API), but the
claim was false and is withdrawn rather than papered over.

### 1.2 The partna branch, which is the one that actually bites

`config/partna.php:1092-1095` pairs account types to pre-account build sources:

```php
'sources' => [
    'partna'   => ['instagram'],
    'business' => ['google_business'],
],
```

The type is caller-supplied and validated against that map (`CreatePreAccountBuildRequest.php:17`,
`PreAccountBuildService.php:76-82`, defaulting to `AccountType::Partna` at `:238`), so an
`instagram` build can only pair with `partna`. `$overwrite` is true only for Business, so an
Instagram-built user never reaches line 229. Google hits `:244` instead:

```php
// partna: fill only when nothing is set yet
if ($this->isBlank($user->sector)) {
```

`e47265d2c` cites `applySector:229` as the lock. For the account type Instagram builds actually
create, the lock is line 244 — a different guard with a different rationale. And 244 is stricter
than its own contract: the class docblock says partna means Google "never clobbers a value **the
user set by hand**", which `isBlank()` cannot distinguish from a scraper's.

### 1.3 The third variant

`InstagramIdentitySync::applySector` (`app/Services/Platforms/InstagramIdentitySync.php:72-79`)
applies a *third* rule — protects `'manual'` only, then fills if blank. Three call sites, three
readings of one column.

### 1.4 What it costs

Because an Instagram write is irreversible, the taxonomy grew a defensive policy ("vague ⇒ null",
`SectorTaxonomy.php:204-211`) that exists solely to work around the precedence rules. The taxonomy
is absorbing the cost of a bug in a guard.

### 1.5 What does and does not self-heal **[rev2, corrected rev3]**

Rev 1 claimed "nothing self-heals a pre-account site". False. There are four entry points to the
Instagram sector write, and one needs no human:

| Entry | Trigger | Human? |
|-------|---------|--------|
| `Api/Platforms/InstagramController.php:124` | user connects Instagram | yes (auth) |
| `Api/Platforms/RefreshController.php:187` | dashboard refresh button | yes (auth, `user.api`) |
| **`GoogleBusinessAutoSync.php:698`** (`dispatchInstagram`) | **automatic** | **no** |
| `PreAccount/Generators/InstagramSourceGenerator.php:106` | pre-account build | no |

The first three reach `InstagramConnectJob`, which calls `InstagramConnectionSeeder::seed()`
(declared at `InstagramConnectionSeeder.php:80`) from `InstagramConnectJob.php:169`; `seed()` calls
`InstagramIdentitySync::applyIdentity` at `:229`.

`dispatchInstagram` runs on **unclaimed** builds — `InstagramConnectJob.php:172` says so — reached
via `GoogleBusinessSourceGenerator.php:136` → `GoogleBusinessEnrichJob.php:228` →
`GoogleBusinessAutoSync.php:114` → `:639` → `:698`, with no claimed-status term in any gate.

**[rev3]** Rev 2 also claimed `ScanPreviousWebsiteContentJob` triggers this "for any account whose
`previous_website` links to Instagram". Over-broad: `seedSocials` sits behind
`GoogleBusinessAutoSync.php:103-105`, `if (! $capabilities->google_business_full_sync) return;`, and
that capability is `$isBusiness`. **Only business accounts** reach the Instagram dispatch. This
strengthens the conclusion below rather than weakening it.

Two consequences the design must handle:

1. On an **unclaimed business** build, Google's fold runs first via `IntegrationConnectionObserver`,
   then `GoogleBusinessAutoSync` dispatches Instagram — so `InstagramIdentitySync` runs **after**
   Google on an unclaimed row. Benign under the ladder (Instagram 1 < Google 2), but it is a real
   ordering to be tested, not an impossible one.
2. It is the race in §4.3: the seeder holds a `$user` loaded before Google's fold committed.

What genuinely does not self-heal is the **partna + instagram** build like `jesshairstylist`: no
Google connection, so no `GoogleBusinessAutoSync` and (per the correction above) no
`ScanPreviousWebsiteContentJob` path either; Instagram is out of cron
(`PlatformRegistryServiceProvider.php:311`, "refresh = paid Apify, not in cron" — the only
registration among instagram/google-business/eventbrite/humanitix without `->refreshable()`); and
the dashboard button needs a login an unclaimed build has nobody for. Per Decision 4 those builds
are not backfilled.

### 1.6 Blast radius of a wrong sector **[rev2, extended rev3]**

Rev 1 claimed sector drives "ONLY" styling and food capabilities. An exhaustive grep of `app/` (36
files) confirms **no reader outside this table**, but the table is larger than rev 1's two rows.

`sector` is **not** on the unauthenticated wire. `UserDashboardResource.php:29-30` is the only
emitter, reached by `UserSelfController.php:42,99` (`user.api`), `ClaimController.php:61` and
`BootstrapController.php:85` (both `supabase.jwt`, both returning the caller's own user), and
`StaffSegmentController.php:200` (staff, AAL2). `SiteResource` carries the preset-merged design kit,
not the slug. `GET /api/public/profiles/{handle}` (`routes/api.php:176`) has zero `sector`
references in either the controller or `IndividualProfilePayloadBuilder`.

| Reader | Consequence of a wrong value |
|--------|------------------------------|
| `ProfileDesignPresets.php:82` | wrong colours/typography — via **four** consumers: `IndividualProfilePayloadBuilder.php:601` (public payload), `SiteResource.php:115`, **`ProEmailBrandResolver.php:79` (transactional email branding)**, `DesignRationaleService.php:77` |
| `AccountCapabilities.php:50` → `isFood` | **flips `can_use_menu` / `reservations` / `booking` / `online_ordering`** (`:66-69`), gating `MenuController.php:83,117`, `OnlineOrderingController.php:62`, `ReservationsController.php:59`, `BookingController.php:56`, `SquareController.php:40`, `FreshaController.php:71` |
| **`LinkRouter.php:164`** → `isFood` | **misroutes auto-placed links** (`booking :168`, `reservations :172`, `online-ordering :173`) — an **independent copy** of the capability match-arms, deliberately not derived from `AccountCapabilities` (`:155-159`) |
| `RoutingCapabilityGate.php:21` | denies routed booking links |
| `OnboardingSuggestions.php:170-171,213` | wrong onboarding suggestions — and see §4.6 |
| `SectorCriterion.php:19,36,43,47` | wrong staff marketing segments |
| `StaffUserController.php:55,57` | wrong staff search filter |
| `SectorOptionsController.php:27`, `SectorController.php:29`, `StaffSegmentController.php:200` | display only |

The narrow claim survives: a wrong **non-food** sector on a **partna** account costs a style preset,
an email brand, a suggestion and a staff filter. The general claim does not — **`isFood` has a
second consumer outside `AccountCapabilities`**, and food is the one sector class where a wrong
guess is not cheap. §3.5 and §4.8 make that structural.

Unchanged and adjacent: the raw Instagram `businessCategory` string *is* on the public wire
(`SectorTaxonomy.php:42`, F4 2026-08-10). That is the input this design classifies from.

---

## 2. Decisions

| # | Question | Decision |
|---|----------|----------|
| 1 | Guess on an ambiguous category, or stay null? | **Guess, but make it correctable.** |
| 2 | May Google replace an Instagram sector on a *partna* account? | **Yes — one rank ladder governs both branches.** |
| 3 | What do we classify on when the category is ambiguous? | **Handle + full name, then the ambiguous category.** |
| 4 | How do existing unclaimed builds pick this up? | **They don't — new builds only.** `jesshairstylist` stays null unless her build is re-run. |
| 5 | How does free-text matching avoid false positives? | **A separate, vetted, food-free `TEXT_KEYWORD_SECTORS`**, pinned by an adversarial corpus. |
| 6 | Scope, after review? | **One branch, all findings fixed together.** |
| 7 | **[rev3]** Google demotes a business out of food while menu content is live? | **Refuse the demotion when content exists.** §4.8. |

---

## 3. Architecture

Two new units. No migration.

### 3.1 `App\Services\Profile\SectorProvenance` **[rev2, hardened rev3]**

```php
final class SectorProvenance
{
    public const MANUAL = 'manual';
    public const GOOGLE = 'google-business';
    public const INSTAGRAM = 'instagram';

    /** Mirrors users_sector_source_check, ordered by authority. */
    private const RANKS = [self::INSTAGRAM => 1, self::GOOGLE => 2, self::MANUAL => 3];

    /** May a source refresh a value IT stamped itself? See §3.2. */
    private const SELF_REFRESH = [self::INSTAGRAM => false, self::GOOGLE => true, self::MANUAL => true];

    public static function mayWrite(User $user, string $incoming): bool
    {
        // [rev3] Fail closed on an unrecognised INCOMING source, before the blank
        // short-circuit. Rev 2 checked this after, so an out-of-vocabulary source
        // wrote through on a blank row and hit users_sector_source_check as a
        // 23514 — fatal on the Instagram path (§6), invisible on SQLite.
        if (! isset(self::RANKS[$incoming])) {
            return false;
        }

        $existingValue = $user->sector;

        // A blank value never blocks a fill, whatever provenance is stamped on it.
        if ($existingValue === null || trim($existingValue) === '') {
            return true;
        }

        // A non-blank value with unrecognised provenance is UNWRITABLE — something
        // outside the three known writers put it there.
        $existingSource = $user->sector_source;
        if (! isset(self::RANKS[$existingSource])) {
            return false;
        }

        $incomingRank = self::RANKS[$incoming];
        $existingRank = self::RANKS[$existingSource];

        return $incomingRank === $existingRank
            ? self::SELF_REFRESH[$incoming]
            : $incomingRank > $existingRank;
    }
}
```

Five semantics, each replacing something load-bearing:

- **It takes the `User`, not two strings.** Rev 1's `mayWrite(?string, ?string, string)` made
  transposition **type-valid**, and a transposed call returned `true` unconditionally — disabling
  *every* guard including manual protection, while a direct unit test stayed green.
- **Unrecognised incoming source is refused first [rev3].** Fail-closed in both directions.
- **Blank short-circuits the ladder**, using `trim()` to match `IdentitySync::isBlank` (`:391-399`)
  rather than diverging from it. Note this *widens* Instagram's blank test, whose own `isBlank`
  (`InstagramIdentitySync.php:154-157`) does not trim — a `(' ', <source>)` row becomes writable by
  Instagram where it is not today. Deliberate; pinned in §5.
- **Unrecognised existing provenance is unwritable, not rank 0.** Rev 1 had this backwards. `sector`
  is fillable while `sector_source` is not (`User.php:105-107`), so every mass-assignment path
  produces `(value, null)` — under rev 1's `?? 0` such a row was the *weakest* in the system and any
  source could overwrite it, a regression against today's `isBlank()`. Now it is the strongest. See
  §5's fixture-audit row: `(value, null)` is the *default* shape across the existing suite.
- **Same-rank writes are policy, not arithmetic.** §3.2.

**PHPStan:** bind `$existingSource` to a local after the `isset` guard rather than indexing
`array<string,int>` with a `?string`; narrowing a nullable offset through `isset` on a private const
is not reliable at this repo's level.

### 3.2 Why Instagram may not refresh its own value **[rev2]**

Rev 1 used `>=` uniformly. Right for Google — authoritative, cron-driven, already today's
business-branch behaviour — and wrong for Instagram:

1. `PARTNA_INSTAGRAM_ACTOR` is a documented **no-deploy rollback**, and the two actors return
   different keys (`InstagramIdentitySync.php:30-37`). Under `>=`, flipping an env var silently
   rewrites stored sectors on the next reconnect.
2. A user pressing "Refresh Instagram" (`RefreshController.php:187`) could change their sector with
   no warning. Under today's fill-if-blank, refresh can never touch it.

Cost: an IG category corrected from "Artist" to "Hair Stylist" is not auto-applied. §4.6 restores
the prompt that lets the user fix it.

### 3.3 `SectorTaxonomy::fromInstagramProfile()` — three tiers **[rev4 — tiers 1+2 fused]**

```php
/** @param list<mixed> $categoryCandidates the raw per-actor category keys, in precedence order */
public static function fromInstagramProfile(array $categoryCandidates, ?string $username, ?string $fullName): ?string
```

| Tier | Source | Resolution |
|------|--------|-----------|
| 1 | **`fromInstagramCategory()`, unchanged** — per candidate, first that maps wins | segment-major: for each segment, exact `??` keyword |
| 2 | `TEXT_KEYWORD_SECTORS` over `username`, then `fullName` | whole normalised string |
| 3 | `AMBIGUOUS_CATEGORY_SECTORS` exact match | per segment |

**[rev4] Tier 1 is a call to the existing function, not a reimplementation.** Every revision up to
rev 3 described the category pass as two separate tiers resolved *"tier-by-tier across all
candidates"* — all exact matches first, then all keyword matches. That inverts **category primacy**
and is a shipping regression. It was introduced in rev 1 and survived three reviews.

`fromInstagramCategory` (`SectorTaxonomy.php:404-420`) is **segment-major**: for each segment in
order, try exact *then* keyword, and return on the first hit. Instagram comma-joins categories
**primary first**, so segment-major is what makes the primary category win. Tier-major lets a
*secondary* category outrank the primary one. `SectorTaxonomyClassificationTest.php:335-366` — added
2026-08-11, one day before this spec — pins exactly this, and its docblock names the harm:

> "a restaurant that also lists 'Digital Creator' would land on content-creator, and
> `SectorTaxonomy::isFood()` would then silently switch off `can_use_menu` /
> `can_use_reservations` / `can_use_online_ordering`."

Verified by execution — eight of nine pinned inputs break under tier-major, three into a
food→non-food demotion that is unrecoverable by the ladder (§4.7: Google has nothing to say; §3.2:
Instagram may not self-refresh):

```
INPUT                                   TODAY (= rev4)   TIER-MAJOR (rev1–3)
Restaurant, Digital Creator             restaurant       content-creator   BREAKS
Barber Shop, Writer                     barber           writer            BREAKS
Hair Salon, Fitness Trainer             hair-salon       personal-trainer  BREAKS
Cafe, Blogger                           cafe             content-creator   BREAKS
Tattoo & Piercing Shop, Digital Creator tattoo-artist    content-creator   BREAKS
Restaurant, Contractor                  restaurant       builder           BREAKS
None, Restaurant, Digital Creator       restaurant       content-creator   BREAKS
Bakery, Content Creator                 bakery           content-creator   BREAKS
Digital Creator, Restaurant             content-creator  content-creator   ok
```

Delegating tier 1 to the unmodified function makes the regression unrepresentable: the category pass
*is* today's behaviour, by construction rather than by test. It also preserves the whole-string exact
pass at `:399-402` (whose stated reason is that a genuine category name can contain a comma), which
the rev-3 tier table silently dropped.

Rev 2 additionally put text *above* the keyword pass, which was wrong for a second, independent
reason. `INSTAGRAM_CATEGORY_SECTORS` is a **corrections** map, not a coverage map — its own docblock
(`SectorTaxonomy.php:222-224`) says so:

> "Only entries the fallback gets wrong belong here. Anything the substring map already resolves
> correctly ("Hair Stylist", "Barber Shop", "Tattoo & Piercing Shop", "Restaurant") is deliberately
> absent."

So the confident classifications live behind the keyword pass. Demoting it below handle text meant IG
category `"Restaurant"` with handle `fitzroyfitnesskitchen` → `gym` (via `TEXT_KEYWORD_SECTORS`'
`fitness`), where today it correctly returns `restaurant`. The same shape hits any category the
substring map gets right and the exact map omits — "Nail Salon" with handle `sarahsbeautyandhair` →
`hair-salon`.

**Text now fires only when the whole category pass yields nothing**, which is exactly the ambiguous
case it was introduced for. `jesshairstylist` still resolves: `"Artist"` returns null from
`fromInstagramCategory` (verified), then `jesshairstylist` → `hairstylist` → `hair-salon` at tier 2.

**The six misclassifications are fixed at source, not by ordering [rev3].** All six reproduce
against current code (verified by execution):

```
Sports Bar            => gym           via 'sport' @:164
Barre Studio          => bar   ← FOOD  via 'bar'   @:203
Bartender             => bar   ← FOOD  via 'bar'   @:203
Sportswear Store      => gym           via 'sport'
Juice Bar             => bar   ← FOOD  via 'bar'
Hair Removal Service  => hair-salon    via 'hair'  @:151
```

Rev 2 claimed the tier split fixed all six. It fixed at most two: `Sports Bar` needs `bar` and
`Juice Bar` needs `cafe`, both food slugs banned from the text tier by §3.5, so text could never
supply them; `Bartender` and `Barre Studio` would only work for handles that happen to
self-describe. The correct fix is **six new `INSTAGRAM_CATEGORY_SECTORS` entries** — exactly what
that map is for — handle-independent, deterministic, and requiring no structural change at all now
that tier 1 delegates to `fromInstagramCategory`:

```php
'sports bar' => 'bar',   'juice bar' => 'cafe',   'bartender' => 'bartender',
'barre studio' => 'yoga-instructor',   'sportswear store' => 'clothing-boutique',
'hair removal service' => 'esthetician',
```

All six values are valid slugs (`isValid()` verified) and all keys are already lowercase-trimmed, so
`SectorTaxonomyClassificationTest:286-294` passes unchanged. Widening this map is in scope; §8
protects only `KEYWORD_SECTORS` and `fromGoogleCategory`.

**[rev4] Verify the six keys against real Facebook vocabulary before merge.** They were derived by
confirming the *substring map mis-maps those strings*, not by confirming Instagram ever emits them,
and `INSTAGRAM_CATEGORY_SECTORS` is exact-match. `barre studio` and `bartender` are the two least
certain. Keeping an unconfirmed key is safe — a dead exact-match key costs nothing — so all six
stay, but near-variants ("Sports Bar & Grill", "Barre Fitness Studio") will still fall through, and
the §5 row that asserts they resolve is self-fulfilling until the strings are confirmed against
observed Apify payloads.

**Tier 3 runs over `categorySegments()`, not the raw string.** Instagram comma-joins categories
primary-first and emits its literal `"None"` as a segment — `hungryjacksau` returns
`"None,Fast food restaurant"` (`SectorTaxonomy.php:434-440`). Rev 1 exact-matched the whole string,
so `"None,Artist"` → `noneartist` missed. Verified: `fromInstagramCategory('None,Artist')` and
`('Artist')` both return `NULL` today, so the gap is real.

`KEYWORD_SECTORS` and `fromGoogleCategory()` are not modified.

### 3.4 `classifyText` and `TEXT_KEYWORD_SECTORS` **[rev2, ordering rule added rev3]**

Normalisation lowercases and reduces to `[a-z]`, then takes the first substring hit in map order.

```
jess.hair.stylist  → jesshairstylist  → 'hairstylist' → hair-salon
crucibletattooco   → crucibletattooco → 'tattoo'      → tattoo-artist
Spartan Fitness    → spartanfitness   → no 'spa' key  → 'fitness' → gym
```

**Why a separate map.** Substring matching is safe against a closed category vocabulary and
dangerous against free text. `KEYWORD_SECTORS` has `'spa'` at index 5 and `'fitness'` at index 8,
and `spartanfitness` contains both — so "Spartan Fitness" → `spa` on the shared map (verified).
Word-boundary anchoring fixes that but breaks run-together handles (`\btattoo` does not match inside
`crucibletattooco`). Neither matcher works on the shared map; a vetted map with plain substring
matching works on both.

**Admission rule** — four clauses, in the map's docblock:

> A key qualifies only if it (1) is ≥5 characters, (2) is not a substring of a common English word
> or Australian surname, (3) names a *trade* rather than a *medium*, and (4) **cannot be
> manufactured by joining across a separator in a plausible handle.**

Clause 4 is why **bare `'hair'` is dropped**. Normalisation does not merely ignore separators, it
*manufactures* matches across them (all verified):

```
beth.airbnb            → bethairbnb            → 'hair'  (Airbnb host)
sarah.airconditioning  → sarahairconditioning  → 'hair'  (HVAC tradie)
leah.airbrushtanning   → leahairbrushtanning   → 'hair'  (should be esthetician)
hannah.airbnbhost      → hannahairbnbhost      → 'hair'
```

*Name ending in h* + *word starting "air"* is a productive pattern, not an enumerable corpus.
Dropping the key costs nothing for the motivating case — `jess.hair.stylist` → `jesshairstylist`
still contains `hairstylist`. Compounds (`hairstylist`, `hairdress`, `hairsalon`, `hairstudio`)
stay; a bare `jess.hair` now misses, which is the correct trade.

**Ordering rule [rev3].** Rev 2 said "specific-before-generic" and left the corpus as the contract.
That is not a rule the next editor can follow, and it does not describe what the claimed outcomes
require. Both `sarahbarberphotography` and `realestatephotography` match two keys, and
first-hit-wins means the outcomes hold **iff every `photograph*` key precedes both `barber` and
`realestate`**. That is not specificity — it is a deliberate precedence. Written as a rule:

> Where a key is commonly an Australian **surname** (`barber`, `baker`) or a **qualifier of another
> trade** (`realestate`, `wedding`), it MUST be ordered after every trade key that can co-occur with
> it in one handle. The medium a person practises (`photograph`, `videograph`, `design`) beats the
> subject they practise it on.

The §5 corpus enforces it; this rule explains it.

`melbournebarrepilates` is no longer cited as a tier-3 outcome — with `barre studio` in tier 1 it
never reaches text, and rev 2's use of it depended on a `barre`/`pilates` key it never listed.

### 3.5 No food slug may be reached from free text **[rev2, tightened rev3]**

**Tiers 2 and 3 — text and ambiguous-category — may never return a value in `FOOD_SECTORS`.**

Rev 2 stated this as a constraint on the *contents* of `TEXT_KEYWORD_SECTORS`, and §5 asserted it by
inspecting the constant. That is a map-shape assertion: it passes trivially and proves nothing about
behaviour. Rev 3 states it as an invariant over `fromInstagramProfile`'s **output per tier**, and §5
asserts it by driving the function.

Without it the design's justification collapses. §1.6's "a wrong non-food sector is cheap" is the
basis for Decision 1. These all pass the admission rule and produce food slugs:

- `coffee` (6, a trade) → `cafe`. `coffeeandcode` is a common IT sole-trader handle.
- `catering` (8, a trade) → `caterer`. `evecateringequipment` is a retailer.
- `baker` → `bakery`. **Baker is a top-20 Australian surname**: `jessbakerphotography`.

On a business account `AccountCapabilities.php:66-69` flips `can_use_menu` and
`can_use_online_ordering` **on** and `can_use_booking: $isBusiness ? ! $isFood : true` **off** — a
business photographer loses booking because of their surname. And per §1.6 the damage is not
confined to `AccountCapabilities`: `LinkRouter.php:161-174` carries an independent copy of the same
arms and deliberately does not derive from the capability (`:155-159`), so a food slug from free
text misroutes auto-placed links on paths that never consult `AccountCapabilities` at all.

Tier 1 may still return food slugs — that is correct, a category *is* evidence — and the
`Sports Bar` / `Juice Bar` / `Bartender` cases are handled inside tier 1 by §3.3 rather than by this
constraint. A genuine café is unaffected: "Coffee shop" → `cafe` at tier 1 (verified).

**[rev4]** §3.4's ordering rule cites `baker` as a key needing careful placement. It is banned
outright by this section (`baker` → `bakery`, a food slug), so the rule's surname clause is
illustrated by `barber` alone.

### 3.6 `AMBIGUOUS_CATEGORY_SECTORS`

```php
private const AMBIGUOUS_CATEGORY_SECTORS = ['artist' => 'artist'];
```

Tier 3, exact match per segment. The categories declined in `INSTAGRAM_CATEGORY_SECTORS`' closing
comment (`health/beauty`, `public figure`, `entrepreneur`, `product/service`, `local business`) stay
declined — no single slug is defensible for any of them, and `SectorTaxonomyClassificationTest.php:265-275`
pins them null via `fromInstagramCategory`, which tier 3 does not touch.

`'artist'` is not in `FOOD_SECTORS`, so §3.5 is satisfied. Note `'Artist'` appears in the non-food
list at `:277-284` but **not** in the null-pinned list at `:265-275`, so tier 3 breaks no existing
assertion.

---

## 4. Call-site changes

Five files plus comment repairs. No migration.

### 4.1 `IdentitySync::applySector`

Collapses from two branches to one. `$overwrite` stops governing sector but stays on
`applyUserIdentityFields` for `applyWorkplaceFields` (`:126`) and `mirrorPublicContactNumber`
(`:188`). The call at `:187` drops its `$overwrite` argument.

```php
private function applySector(User $user, ?string $mappedSector): void
{
    if ($mappedSector === null) return;
    if (! SectorProvenance::mayWrite($user, SectorProvenance::GOOGLE)) return;

    // §4.8 — pure predicate first, DB probe only on an actual demotion attempt.
    if (SectorProvenance::isFoodDemotion($user, $mappedSector) && $this->foodContent->existsFor($user)) {
        SectorProvenance::logTransition($user, $mappedSector, self::GOOGLE_SOURCE, __METHOD__, 'refused_food_demotion');
        return;
    }

    if ($user->sector !== $mappedSector || $user->sector_source !== self::GOOGLE_SOURCE) {
        SectorProvenance::logTransition($user, $mappedSector, self::GOOGLE_SOURCE, __METHOD__, 'applied');
        $user->sector = $mappedSector;
        $user->sector_source = self::GOOGLE_SOURCE;
        $user->save();
    }
}
```

`$user` here is the locked `$fresh` row, so `logTransition` reads the correct `from`/`from_source`
before the assignment. **§4.3's writer must do the same** — passing the caller's pre-lock `$user`
would log stale provenance in the one path whose entire support story is this log.

`mayWrite` is pure and reads only the locked `$fresh` row passed by `applyUserIdentityFields`
(`:179-192`, `lockForUpdate()` at `:182`), so the check-then-write stays inside the LIFE-107 lock.

**The site touch does NOT go here [rev3].** `applySector` receives `$fresh`, not the caller's
`$user`; `$fresh->site` is an unloaded relation, so touching it here fires a site write inside the
user-row lock. The touch moves to `applyUserIdentityFields` after the transaction commits — §4.4.

**Two behaviour changes beyond the reported bug**, both intended:

1. On a partna account Google now also replaces a sector *Google itself* set earlier. Today partna
   is fill-if-blank, so a stale Google sector survives a recategorisation.
2. The write condition includes `sector_source !== GOOGLE_SOURCE`, so a correct-value/wrong-source
   row gets a provenance-only rewrite. **[rev3]** This is unreachable for `(value, null)` — §3.1
   refuses that row first — so it applies only to `(value, 'instagram')`.

### 4.2 `InstagramIdentitySync::applySector`

Hands the candidate list and both text signals to `fromInstagramProfile`. **[rev3]** Every scalar
goes through `stringOrNull` first, as the rest of the file does (`:43-46`) — a non-string from Apify
would otherwise be a TypeError, and §6 shows there is no try/catch to absorb it.

```php
$this->applySector(
    $user,
    [
        $payload['businessCategoryName'] ?? null,
        $payload['business_category_name'] ?? null,
        $payload['category_name'] ?? null,
    ],
    $this->stringOrNull($payload['username'] ?? null),
    $this->stringOrNull($payload['fullName'] ?? $payload['full_name'] ?? null),
);
```

The fold itself operates on the locked row, not `$user` — §4.3.

### 4.3 `InstagramIdentitySync` must take the LIFE-107 lock **[rev2, refresh resolved rev3]**

`InstagramIdentitySync` has **no transaction and no `lockForUpdate` anywhere in the file** (verified
across all 163 lines; it saves at `:78`, `:88`, `:105`, `:150` on the caller's instance). The caller
loads `$user` at `InstagramConnectionSeeder.php:227` and calls `applyIdentity` at `:229`.

This is a real lost-update **today** — a stale `null` read against a row Google just set to `cafe`
passes `isBlank()` and clobbers it — so it is **pre-existing, not introduced by the ladder**. But
§1.5 establishes Google-then-Instagram on one unclaimed row as a live ordering, and the file is
being rewritten anyway.

1. Wrap the sector fold in `DB::transaction` + a `lockForUpdate()` re-read; operate on `$fresh`,
   never the caller's `$user` — the footgun `IdentitySync`'s docblock (`:168-177`) closes by design.
2. **Then `$user->refresh()`, mirroring `IdentitySync.php:191` [rev3].** Both reviewers found this
   independently and it is not optional. `InstagramConnectionSeeder.php:230` passes the same `$user`
   to `autoSaveUnmatchedLinks` → `CustomLinkSeeder::seed` → `LinkRouter::route` → `gateAllows`,
   which reads `$user->sector` at `LinkRouter.php:164`. Today that value is fresh because
   `applySector` writes it onto `$user` directly. Writing to `$fresh` without refreshing would leave
   the second half of one bio-link run gated on a stale sector.
3. Add `InstagramIdentitySyncConcurrencyTest` mirroring LIFE-107, **plus a source-grep pin** — see
   §5, because `lockForUpdate()` is a no-op on SQLite and a behavioural test alone passes against a
   bare `refresh()` with no transaction.

`IdentitySyncConcurrencyTest` **cannot** detect any of this: `:146-150` reads
`app_path('Services/Platforms/IdentitySync.php')` only. Rev 1 cited it as the pin; it is not one.

**[rev4] Coordinate with the media-pool Instagram work.**
`docs/superpowers/plans/2026-08-11-media-pool-instagram-EXECUTE-PROMPT.md:165` rewrites
`InstagramConnectionSeeder`'s photo mirroring, and names the same three entry points §1.5
enumerates. The two changes touch different regions of `seed()` — mirroring sits around `:90-130`,
the identity fold at `:227-230` — so this is a coordination risk, not a design conflict. The
specific hazard is **semantic, not textual**: §4.3 step 2 assumes `$user` is loaded at `:227` and
consumed at `:230`, so if the media work reorders `seed()` or moves where `$user` is resolved, the
`refresh()` placement stops being correct while still merging cleanly. Whichever lands second
re-checks that ordering. No worktree currently holds this file (`git worktree list` shows only
`dast-hardening`).

**Known ordering gap, not fixed here.** `InstagramConnectionSeeder.php:220` runs
`autoSync->seed(...)` — which routes bio links through `gateAllows` — **before** `:229` writes the
sector. On a first-ever build the sector is null during routing, so a business food account has
`reservations`/`online-ordering` denied and nothing re-routes afterwards. Pre-existing, widened in
visibility by §1.6 naming `LinkRouter` as an `isFood` consumer. Recorded in §8 as out of scope; it
needs its own fix, not a reordering smuggled into this one.

### 4.4 Both writers must touch the site **[rev2, placement corrected rev3]**

`SectorController.php:34-39` already documents the requirement:

```php
// The sector drives the read-time profile design presets — touch the
// site so the public payload + email caches roll and the sitepage
// restyles immediately (SiteObserver::saved runs the purge chain).
if ($changed) { $user->site()->first()?->touch(); }
```

Neither sync writer does this. Masked today because Instagram writes happen pre-publish and
Google-on-partna only fills blanks — the design removes both masks, and
`IntegrationConnectionObserver.php:111-114` fires the Google fold on **every** ScheduledRefresh whose
payload changed. So a live claimed partna site's colours, and its transactional email branding
(`ProEmailBrandResolver.php:79`), can change at origin while the edge serves the old ones.

`IndividualProfilePayloadBuilder::cacheKey` (`:723-727`) keys on `$site->updated_at` (falling back to
`$pro->updated_at` only when there is no Site row), which `$user->save()` does not roll.
`UserObserver::updated` does bust Redis (`bustSite: true`, `sector` absent from
`PUBLIC_PROFILE_USER_FIELDS` at `:38-42`), but no Redis path dispatches an edge purge — verified
neither `UserCacheService` nor `SiteCacheInvalidator` does.

**[rev3]** Rev 2 said the purge is dispatched "ONLY from `SiteObserver::saved:45`". False — the
dispatch is at `SiteObserver.php:46` and there are ~15 dispatch sites across the app. The
*conclusion* survives: none of them is on a `$user->save()` path, so the edge is not purged.

**Placement:** each writer touches the site **after its transaction commits and after
`$user->refresh()`**, on the caller's `$user`, guarded on an actual sector change — never on
`$fresh` inside the lock (§4.1). `CloudflareCachePurgeJob implements ShouldBeUnique` (`:26`) and is
dispatched statically, so the unique lock bounds dispatch volume; what is not bounded is the
user-visible restyle, which is §4.7's concern.

### 4.5 `SectorController`

No change. Manual is top rank; clearing to null resets both columns, and the blank short-circuit
then lets any source refill.

### 4.6 `OnboardingSuggestions` — the guess must not suppress its own correction **[rev2, business gate dropped rev3]**

`OnboardingSuggestions.php:214` is character-exact:

```php
'askSector' => ! $isBusiness && $sector === null,
```

Writing a guess sets `$sector`, which sets `askSector` false, which removes the only prompt that
lets the user fix it. Decision 1 is "guess, but make it correctable"; rev 1 dropped the nudge as
"independent of this fix" — it is not, because the design *removes* an existing ask.

**[rev3] Both legs change.** Rev 2 kept `! $isBusiness`, which left business accounts — the only
type where sector gates capabilities, and a type that demonstrably acquires Instagram guesses via
§1.5's `dispatchInstagram` ordering — with no prompt at all:

```php
'askSector' => $user->sector_source !== SectorProvenance::MANUAL,
```

This asks whenever the value was not chosen by a human, on either account type.

Two consequences to handle in implementation:

- `tests/Feature/Onboarding/OnboardingSuggestionsTest.php:66` asserts `askSector` is **false** for a
  partna user seeded with a sector and **no** `sector_source` — a `(value, null)` row. It flips to
  true and must be updated, not deleted.
- `askWorkplace` (`:216`) is gated on `$sector !== null` and will now fire alongside `askSector`
  more often. Confirm that pairing reads sensibly.

**Open, flagged not resolved:** `OnboardingController`'s docblock scopes this payload to "the
post-claim signup setup steps (signup-v2 E/F)" — a one-shot flow. If it is genuinely one-shot, a
Google refresh that changes the sector afterwards never re-prompts, and §4.6 is a weaker correction
path than it appears. If the frontend polls it, a user content with a google-business sector is
nagged with no dismissal state. **This needs a frontend answer before implementation**; it does not
change the backend change above, which is strictly better than the status quo either way.

### 4.7 The ladder only helps when the higher source has a value **[rev3]**

`applySector` returns at `if ($mappedSector === null)` **before** `mayWrite` is consulted. So Google
cannot correct anything when `fromGoogleCategory()` returns null — common, since Places
`primaryTypeDisplayName` emits values like "Bistro", "Meal takeaway", "Beauty salon" and "Juice
shop" that match no `KEYWORD_SECTORS` key.

Combined with `SELF_REFRESH[INSTAGRAM] = false`, a wrong Instagram guess on an account Google has
nothing to say about is correctable **only** by a human. That is why §4.6 drops the `! $isBusiness`
gate rather than leaving business to the ladder.

This is a property of the design, not a defect to fix here — widening `KEYWORD_SECTORS` for Google
is explicitly out of scope (§8). It is recorded because "one rank ladder governs both branches"
overstates the guarantee without it.

### 4.8 Food demotion is refused while food content exists **[rev3 — Decision 7]**

Under §4.1 Google can flip a business from a food sector to a non-food one — e.g. Places returns
"Event venue" for a restaurant that also does functions → `event-venue` via `KEYWORD_SECTORS:185`.
`can_use_menu` goes false. But `PageCapabilities.php:12-16` records that enforcement deliberately
moved to **write** time so pages are not dropped at render — so the Menu page **stays live
publicly** while `MenuController.php:83`, `MenuContentController.php:473` and
`OnlineOrderingController.php:62` return 403 to the owner. That docblock names the exact symptom
this would reproduce: *"my Menu page disappeared and nothing told me why."* Trigger is hourly cron;
there is no cleanup (`UserObserver::updated:102-104` branches on `account_type` only).

**[rev4] Split into a pure predicate plus an injected probe.** Rev 3 put `hasFoodContent` on
`SectorProvenance`, which broke the purity property §4.1 relies on to argue lock safety and made the
class un-unit-testable without a database.

```php
// SectorProvenance — pure, no DB.
public static function isFoodDemotion(User $user, string $incomingSector): bool
{
    return $user->isBusiness()
        && SectorTaxonomy::isFood($user->sector)
        && ! SectorTaxonomy::isFood($incomingSector);
}
```

```php
// App\Services\Profile\FoodContentProbe — one query, injected into both sync writers.
public function existsFor(User $user): bool
```

**What the probe must actually query [rev4].** Rev 3 scoped it to "menu items / online-ordering
config", which misses the content class that produces the cited symptom. A Menu **page** is a
`site.pages` row with `capability='menu'` (`Page.php:39,54`, written only by `PageController.php:136`
via `PageCapabilities::allows`). A business that created a Menu page but hasn't added dishes has zero
menu items — the probe returns false, the demotion proceeds, and their live Menu page 403s them on
every edit. That is the exact case the guard exists for. Four EXISTS clauses, collapsed into **one
query** so the lock holds for one round-trip:

- `site.menus` by `user_id` with `whereNull('deleted_at')` (`Menu` uses `SoftDeletes`, `Menu.php:64`)
  joined to `site.menu_items` — or owner content per `MenuPayloadComposer::hasOwnerContent`
  (`:64-80`).
- `site.platform_connections` where `platform = 'online-ordering'` (the shape `MenuSource::entries`
  uses, `MenuSource.php:240-245`).
- `site.pages` where `capability IN ('menu','online_ordering','reservations')`.
- `site.sections` of gated kind `menu_item` (`PageCapabilities.php:36-40`).

**Use an explicit `Site::query()->where('user_id', …)` sub-select, never `$fresh->site`.**
`AppServiceProvider.php:372` sets `Model::preventLazyLoading(! app()->isProduction())`, so touching
that unloaded relation **throws** in dev and test.

**[rev4] Forward compatibility with the content-pool convergence.** `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`
retires the `site.*` **item** tables onto `content.items`, and `site.menu_items` (370 rows →
kind `menu_item`), `site.menu_categories`, `site.menu_item_categories` and
`site.menu_item_platforms` are all on that list (§1.4). Menus are **slice 4**, each slice gets its
own spec → plan → implement cycle, and slice 2 is the one in flight — so this spec ships first and
is not blocked. But the probe must be written knowing one of its four clauses has a scheduled
replacement:

| Probe clause | Convergence fate |
|---|---|
| `site.menus` + `site.menu_items` | **retired** → `content.items` where `kind = 'menu_item'` (slice 4) |
| `site.platform_connections` (`platform = 'online-ordering'`) | survives — not an item table |
| `site.pages` (`capability IN (…)`) | **survives** — §2 explicitly retains `pages` |
| `site.sections` (kind `menu_item`) | **survives** — §2 explicitly retains `sections`, `section_items` |

Three of four survive, including the clause that carries the cited symptom (a Menu *page* with no
dishes). Keep the menu-items clause isolated as a single named sub-query so slice 4 swaps one
expression rather than rewriting the probe, and add the probe to that slice's migration checklist.

**Cost is bounded [rev4].** `isFoodDemotion` short-circuits before any query, so the probe runs only
on a genuine food→non-food attempt on a business — not on every fold. Rev 3 said the trigger is
"hourly cron"; that is wrong. Google's per-connection TTL is **2 days**
(`PlatformRegistryServiceProvider.php:613` → `config/partna.php:1831`,
`PARTNA_REFRESH_INTERVAL_GOOGLE_BUSINESS = 2*86400`). Steady state is one extra round-trip per stuck
business per two days.

Applies to **automated** writers only. A manual pick is the human resolving it and must not be
blocked — `SectorController` does not call this. **[rev4]** Note what that leaves open: a user can
still pick a non-food sector by hand while menu content is live and 403 themselves out of their own
page, unwarned. Self-inflicted and acceptable, but the state is reachable, not impossible.

**Recovery path [rev4].** A refusal has no re-evaluation trigger: `applySector` runs only from
`IntegrationConnectionObserver::saved` (`:110-113`, gated on `wasRecentlyCreated || wasChanged('payload')`)
at the 2-day cadence, and nothing in the menu-delete path re-runs the fold. A business that genuinely
leaves food must either delete its food content and wait for the next payload change, or pick the new
sector by hand. §4.6's `askSector` — which rev 3 widened to business — is the surfacing mechanism.

The cost is accepted: a business whose sector is genuinely wrong stays wrong until someone resolves
it. That is strictly better than 403-ing an owner out of their own live page, and both the refusal
and its reason are logged (§4.9).

### 4.9 `SectorProvenance::logTransition` **[rev3 — was invented and undefined]**

Rev 2 referenced `logSectorTransition` in a code sketch and defined it nowhere; a repo grep returns
only the spec. §3.2 makes it the entire support story for a value Instagram may never refresh, so it
needs a definition:

```php
public static function logTransition(
    User $user, ?string $to, ?string $toSource, string $caller, string $outcome = 'applied'
): void
```

**[rev4] `$outcome` is required, not decoration.** Rev 3 specified a refusal as "`to_source` null
plus a reason" against a signature with no reason parameter — and a refusal logged as
`to_source: null` is indistinguishable from a clear-to-null. Rev 3 also never actually *called* it on
the refusal path: §4.1's sketch was a bare `return`, and the predicate had no logging. §5's row
asserting "refused and logged" could not have passed.

Static on `SectorProvenance` — both writers already depend on the class, and it stays pure (it reads
`$user`, writes a log line, touches no database). Logs at `info`: `user_id`, `from`, `from_source`,
`to`, `to_source`, `caller`, `outcome`. Outcomes today: `applied`, `refused_food_demotion`.

**Both writers pass the locked row, before the assignment** (§4.1, §4.3) — a pre-lock instance would
log stale provenance.

`info` is correct: `LOG_LEVEL=debug` on both envs, and `Log::info` with a `user_id` UUID is the
house convention (~126 call sites, e.g. `InstagramConnectionSeeder.php:244`). A sector slug is a
public taxonomy value, not personal data. One consequence to know: `config/nightwatch.php:49` is
`'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'warning'))` and `NIGHTWATCH_LOG_LEVEL`
is unset on both envs, so **every one of these ships to Nightwatch as an event**, not just to a file.
Volume converges — §4.1's write condition fires once per genuine change, and the refusal is bounded
at roughly one per stuck business per two days.

### 4.10 Comment repairs **[rev2]**

Four comments become false and must be rewritten in the same commit, or the next reader restores the
old reasoning: `SectorTaxonomy.php:204-211` (the "NO bare `'artist'` key" block, whose stated reason
is the stickiness this removes), `SectorController.php:11-16`,
`InstagramIdentitySync.php:13-17` and `:70-72`.

Noted, not fixable: `supabase/migrations/20260728150000_field_bindings.sql:19-21` states the sector
law as "manual permanent; first non-Google source wins" and attributes it to a `FieldBindingResolver`
that exists in neither `app/` nor `tests/`. Shipped migrations are immutable; §7 records it.

---

## 5. Testing

A test that passes whether or not the implementation is correct is worse than no test. Each row
names what makes it *fail*.

| Test | Asserts | Fails when |
|------|---------|-----------|
| `tests/Unit/Profile/SectorProvenanceTest.php` | truth table: existing source ∈ {null, instagram, google-business, manual, bogus} × value ∈ {null, `''`, `' '`, set} × incoming ∈ {instagram, google-business, manual, **bogus**} | any rank, self-refresh or fail-closed rule drifts |
| — same | `(set value, null source)` unwritable by every source | the rev-1 C3 regression returns |
| — same | unrecognised **incoming** refused even on a blank row | the rev-2 I1 fail-open returns |
| — same | `INSTAGRAM` may not self-refresh; `GOOGLE`/`MANUAL` may | `SELF_REFRESH` drifts |
| `SectorTaxonomyClassificationTest` — **tier order** | `"Restaurant"` + handle `fitzroyfitnesskitchen` → `restaurant`; `"Nail Salon"` + `sarahsbeautyandhair` → `nail-technician` | text is promoted above the category pass again |
| — **primacy, migrated [rev4]** | the nine compound inputs in §3.3's table, driven through **`fromInstagramProfile`** — `Restaurant, Digital Creator` → `restaurant`, `Barber Shop, Writer` → `barber`, etc. | tier 1 stops delegating to `fromInstagramCategory` and goes tier-major again. **This is the rev-1→3 regression; single-segment cases pass either way, so the pin must be compound** |
| — **migrate `:304-366` [rev4]** | after §4.2, `fromInstagramCategory`'s only production caller is gone, so its 20-odd assertions — including both primacy blocks — pin a function nothing calls while the live classifier has no primacy coverage. Repoint them at `fromInstagramProfile` | the live path is left unpinned |
| — six categories | all six §3.3 inputs resolve correctly, handle-independent (assert with an empty handle). **Self-fulfilling until the keys are confirmed against observed Apify payloads** (§3.3) | the six exact-map entries are removed |
| — tier 3 segments | `"None,Artist"` → `artist` | tier 3 stops using `categorySegments()` |
| — whole-string pass | a comma-containing category name still resolves whole-string first (`:399-402`); today no `INSTAGRAM_CATEGORY_SECTORS` key contains a comma and nothing pins that — `:373-377` covers `KEYWORD_SECTORS` only | tier 1 stops delegating and drops the whole-string pass |
| — **food invariant, behavioural** | drive `fromInstagramProfile` over the whole adversarial corpus with a null category; **no result is in `FOOD_SECTORS`** | a food slug becomes reachable from tier 2/3 — unlike rev 2's constant-inspection version, which passed trivially |
| — non-food invariant, extended | existing `:277-284` extended to `fromInstagramProfile` | it currently calls only `fromInstagramCategory` |
| — slug validity | every value in both new maps passes `isValid()`, extending `:286-294` | a typo'd slug ships — SQLite misses it, Postgres throws 23514, and §6 shows that fails the whole connect job |
| — **adversarial corpus** | `beth.airbnb`, `sarah.airconditioning`, `leah.airbrushtanning`, `hannah.airbnbhost`, `hairyhounds`, `sarahbarberphotography`→`photographer`, `realestatephotography`→`photographer`, `jessbakerphotography`, `coffeeandcode`, `Spartan Fitness`, `bakerstreetbarbers`, `facepainting.co`, `mrchairs.furniture`, `thebarberlin` | any separator-join, surname or qualifier false positive returns |
| — set equality | representative-input table covers `TEXT_KEYWORD_SECTORS` in full | the docblock goes stale |
| `InstagramIdentitySyncTest` | fills blank; does not overwrite google-business or manual; does not self-refresh; `jess.hair.stylist` + `"Artist"` → `hair-salon` | — |
| — `:168-172` | **no rename** — rev 2 proposed one; under the ladder Instagram can never overwrite any non-blank recognised value, so its title stays true | — |
| — `(' ', <source>)` | now writable by Instagram (the `trim()` widening, §3.1) | the widening is unintentional rather than chosen |
| `IdentitySyncTest` — **invert** `:303-322` | today asserts Google never overwrites an instagram sector on a business resync; the design makes Google win | — |
| — new | Google overwrites instagram on **both** account types; never overwrites manual; refreshes its own value on partna | — |
| **`InstagramIdentitySyncConcurrencyTest`** (new) | stale `$user` + concurrent raw Google write → Google's value survives; **and** `$user` is refreshed so `LinkRouter` sees the new sector | §4.3 step 2 is dropped |
| — **source-grep pin** | `InstagramIdentitySync.php` contains `lockForUpdate` and `->transaction(` | `lockForUpdate()` is a SQLite no-op, so the behavioural test alone passes against a bare `refresh()` |
| `IdentitySyncConcurrencyTest` | stays green **unmodified** (`'manual'` at `:82`, rank 3 > 2) | — |
| **cache/purge** (new) | a sector change from either writer touches the site → `SiteObserver::saved` dispatches the purge; and the touch happens **outside** the lock | the rev-2 C4 regression returns, or a site write lands inside the user-row lock |
| **food demotion** (new) | business + food sector + food content + non-food incoming → refused and logged with `refused_food_demotion`; same without content → allowed; **Menu page but zero menu items → still refused** (§4.8's missed case); manual pick always allowed | §4.8 is dropped, leaks into `SectorController`, or the probe omits `site.pages` |
| — **harness [rev4]** | `IdentitySyncTest`'s `beforeEach` (`:16-22`) creates users/sites/blocks only — **not `site.menus`** (`tests/Pest.php:910` is a separate helper). `:284-300` (`bizgooglesector`: business, `cafe`+google-business, mapped to `barber`) lands directly on the demotion path and would **error** with `no such table` | the tables aren't added to the harness |
| **`logTransition`** (new) | fires with `outcome: applied` on every actual change and `outcome: refused_food_demotion` on every §4.8 refusal, reading `from`/`from_source` off the **locked** row | §4.9 is stubbed, or a writer passes its pre-lock instance |
| `OnboardingSuggestionsTest` — **update `:66` and `:103` [rev4]** | there are three `askSector` assertions (`:54`, `:66`, `:103`) and `onboardingUser` (`:21-32`) never sets `sector_source`. Both `:66` (partna, `hair-salon`) **and** `:103` (business, `restaurant`) flip false→true. Rev 3 named only `:66` | — |
| — new | `askSector` true for an instagram-sourced sector on **both** partna and business; false for manual | the `! $isBusiness` gate returns |
| **fixture audit [rev4 — rescoped]** | Rev 3 claimed §3.1 would break the suite's default `(value, null)` fixtures. **Verified false**: every suite exercising a sync writer either `forceFill`s an explicit source (`IdentitySyncTest:227,252,270,289,311`) or starts sectorless (`InstagramIdentitySyncTest:17,49,69,242`; `InstagramAsyncConnectTest:824,882,934`); `GoogleBusinessApifyTest:46` is `(restaurant, null)` but its fixture carries no `primaryTypeDisplayName`, so `applySector` returns before `mayWrite`. `(value, null)` is common but **read, not written**, so the change is inert. `ProfileDesignPresetsTest:39` (`sector_source = 'google'`) is an unsaved model that never reaches a CHECK or `mayWrite` — cosmetically wrong, zero consequence. **The real unbudgeted churn is the harness row above and the two `askSector` assertions**, not §3.1 | — |
| **`tests/Schema/`** CHECK pin | `pg_get_constraintdef` for `users_sector_source_check` equals `array_keys(RANKS)`; tag `->group('postgres')` as the neighbouring tests do | the CHECK widens without a rank decision |
| `SectorProvenanceTest` — fast pin | scan **all** `supabase/migrations/*.sql` for `users_sector_source_check`, extract the `ARRAY[…]` literal, set-equal against `array_keys(RANKS)` via Reflection | same, in the fast lane |

**On the CHECK pin.** Rev 1 proposed asserting `array_keys(RANKS)` against a hard-coded list —
self-referential, failing only when someone edits `RANKS`, which needs no catching. `tests/Schema/`
**can** read `pg_constraint` (`ModerationStateColumnTest.php:55` does exactly this), so the real pin
belongs there; the fast-lane version must scan the whole migration directory, since a later
migration is how the CHECK would widen. `RANKS` is `private const`, so Reflection is required —
precedent at `SectorTaxonomyClassificationTest.php:102`. `tests/Schema/` runs in CI
(`ci.yml:486 composer test:schema`) but **not** in `composer test` (`phpunit.xml:7-14`), which is
why both exist.

Related suites to re-run: `SectorCapabilityGatingTest`, `InstagramSeederCategoryTest`,
`SectorTaxonomyTest`, `SectorControllerTest`, `ProfileDesignPresetsTest`, and the `LinkRouter`
routing corpus given §1.6.

---

## 6. Error handling

`mayWrite` is total and fail-closed in both directions. `classifyText(null)` → null.
`fromInstagramProfile` returns null when every tier misses; all callers treat null as "leave the
stored value untouched".

Asymmetry worth knowing: `IdentitySync::applyFromGooglePayload` is best-effort (try/catch at
`:50`/`:96`), but `InstagramConnectionSeeder.php:229` is not wrapped. `seed()` spans `:80-314` and
contains two try/catch blocks — `:148-150` (stale-media delete) and `:285-296`
(`Cache::lock`/`LockTimeoutException`) — **neither enclosing `:229`**. Traced: a 23514 escapes
`applyIdentity` → `seed()` → `InstagramConnectJob::handle()` (`:134-179`, no try/catch), burns
`$maxExceptions = 2` (`:69`), then `failed()` (`:181`) marks the connection `unavailable`. The throw
is at `:229`, **before** the authoritative connection write at `:285-296`, so payload, `is_active`
and media selection are never persisted — the whole connect fails.

On the **pre-account** path the same throw is caught: `InstagramSourceGenerator.php:107-109` wraps
`seed()` and rethrows `SourceGenerationException::scrapeFailed()`, so the build fails rather than the
job. Either way, the slug-validity pin in §5 is load-bearing, not tidy.

---

## 7. Migration and rollback

**No migration.** `sector` and `sector_source` are both nullable `text` (baseline `:1171-1172`);
`users_sector_source_check` (`:1175`) permits `'instagram'`; `users_sector_check` (`:1174`) permits
every slug written, `'artist'` included. `supabase/migrations/` holds 80 files including the
baseline; only two others mention sector, both in prose (`20260728150000_field_bindings.sql:19,21`,
`20260809090001_design_kits_preset_only.sql:18`). **No migration touches either column.**

**Rollback** is a single revert. An instagram-stamped sector written under the new code becomes
sticky again under the old rules — no stranded data, no schema to undo.

**Known stale prose:** `20260728150000_field_bindings.sql:19-21` documents the old law and names a
`FieldBindingResolver` that does not exist. Shipped migrations are immutable; this spec is the
current statement of the law.

---

## 8. Out of scope

- **A `sector_confidence` column.** Redundant under the ladder.
- **A backfill command** (Decision 4). **This fix reaches zero existing accounts** — it is a fix for
  every build from here. `jesshairstylist` stays null unless her build is re-run.
- **Making Instagram cron-refreshable.** Deliberate cost control
  (`PlatformRegistryServiceProvider.php:311`).
- **Widening `KEYWORD_SECTORS` or changing `fromGoogleCategory`.** The Google path must not regress.
  This is what makes §4.7 a permanent property: Places types Google cannot map leave the ladder with
  nothing to say. Widening `INSTAGRAM_CATEGORY_SECTORS` **is** in scope (§3.3).
- **The `LinkRouter` ordering gap** (§4.3): bio links route at `InstagramConnectionSeeder.php:220`
  before the sector is written at `:229`, so a first-ever business food build routes under a null
  sector and never re-routes. Pre-existing; needs its own fix.
- **`InstagramConnectionSeeder::categoryOrNull` (`:619-643`)**, a second copy of the
  candidate-selection rule that is on the public wire. Left alone deliberately; noted because two
  divergent implementations of one rule is a known hazard.
- **Whether `OnboardingSuggestions` is one-shot or polled** (§4.6). Needs a frontend answer; does not
  block the backend change.
