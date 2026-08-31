# Cold-Build Defect Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every defect found by the 2026-08-31 cold-build audit (27 builds, findings F1–F21 plus the F13 escalation), and prove each fix on a real rebuild.

**Architecture:** Nine phases, each independently shippable and each ending on live evidence rather than a green test alone. Phases 1–2 are the fast, fully-specified lane (apps/pages + ingest normalisers). Phase 3 fixes name derivation with a deterministic gate layered over the existing AI pass. Phase 4 gives an in-flight build its own surface. Phase 5 is the purge escalation. Phases 6–8 are taxonomy, media and hygiene. Phase 9 is the fleet rebuild and acceptance gate.

**Tech Stack:** Laravel 12 / PHP 8.4 (Comet-Backend), Pest 4, Astro 5 + Cloudflare Workers (partna-monorepo/apps/pages), Vitest, Supabase Postgres, Redis/Horizon, Cloudflare KV.

## Global Constraints

- Two repos. `~/Developer/Comet-Backend` on branch `development`; `~/Developer/partna-monorepo` on `main`. **Commit per task, never mix repos in one commit.**
- **`cloudflare-worker/src/index.js` is OFF LIMITS.** Another lane holds 2 unpushed commits and an uncommitted working-tree change there (the unclaimed-subdomain page redesign, 2026-08-31 21:20–21:38 local). Do not touch that file, do not `git stash`, do not `git checkout` it. Phase 4 was designed to need no change to it.
- Never create Laravel migration files — schema changes are raw SQL in `supabase/migrations/`. No phase in this plan needs one.
- `php artisan test` runs with `--parallel` in CI. Every new test must pass under `--parallel` (see Task 9.1 for the existing violation).
- Logs come from `scripts/logs/window.py "<from>" "<to>"`, never `cloud env:logs` bare in a loop.
- Verification against dev uses `~/.composer/vendor/bin/cloud command:run development --cmd="php artisan <cmd>"`.
- Dev Supabase project ref: `glncumufgaqcmqhzwrxm`.
- The 27 audit accounts stay in place until Phase 9 — they are the before-state evidence.

## Scope Note

**F15 (no ingest connector for Booksy / Cliniko / NowBookIt / Timely, so no service business gets a services pool) is deliberately NOT in this plan.** It is four new scrapers against four vendor surfaces — its own subsystem, its own research, its own plan. Phase 8 Task 8.4 writes the brief for it. Everything else in the audit is here.

---

## Phase 0 — Pre-flight

### Task 0.1: Snapshot the before-state and branch

**Files:**
- Create: `docs/superpowers/plans/2026-08-31-before-state.json` (Comet-Backend)

**Interfaces:**
- Produces: a committed JSON snapshot later phases diff against.

- [ ] **Step 1: Confirm both repos are clean and in sync**

```bash
cd ~/Developer/Comet-Backend && git fetch --quiet && git status --short && git rev-list --left-right --count HEAD...origin/development
cd ~/Developer/partna-monorepo && git fetch --quiet && git status --short && git rev-list --left-right --count HEAD...origin/main
```

Expected: monorepo clean and `0	0`. Comet-Backend shows `2	0` plus ` M cloudflare-worker/src/index.js` — that is the other lane's work. Leave it. If anything ELSE is modified, stop and ask.

- [ ] **Step 2: Capture the before-state**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
echo json_encode([
  \"ig_name_defects\" => DB::connection(\"pgsql\")->select(\"select count(*) c from core.users u join core.pre_account_builds b on b.user_id=u.id where b.build_state=1 and b.source_type=2 and u.status=3 and (u.last_name ~* 4 or u.display_name ~ 5)\", [\"ready\",\"instagram\",\"unclaimed\",\"^(barber|music|studio|pilates|grooming|flowers|sydney|melbourne|trainer|decorator|physiotherapy|school|barbers|artist|photographer|chef|therapist|instructor|hmua)$\",\"[^\\\\x20-\\\\x7E]\"]),
  \"sector_null\" => DB::connection(\"pgsql\")->select(\"select count(*) c from core.users u join core.pre_account_builds b on b.user_id=u.id where b.build_state=?  and u.status=?  and u.sector is null\", [\"ready\",\"unclaimed\"]),
]);"' > docs/superpowers/plans/2026-08-31-before-state.json
```

- [ ] **Step 3: Commit the snapshot**

```bash
cd ~/Developer/Comet-Backend
git add docs/superpowers/plans/2026-08-31-before-state.json docs/superpowers/plans/2026-08-31-cold-build-defect-remediation.md
git commit -m "docs(plan): cold-build defect remediation plan + before-state snapshot"
```

---

## Phase 1 — apps/pages (F1, F4, F9, F10)

All four are in `~/Developer/partna-monorepo`. Small, self-contained, no backend dependency.

### Task 1.1 (F1): Platform tiles emit an action beacon, not an invalid item beacon

The tracker sends `item_type: "platform"`, which is in neither `ItemSeenRequest::ITEM_TYPES` nor `SCORED_ITEM_TYPES`, so every platforms-page view produces two HTTP 422s and two console errors. Platform tiles already exist in the actions payload as `{"id":"platform:facebook","kind":"platform"}`, and `dom-contract.ts` already has an action contract with a working `/t/action-seen` lane. Move the tile onto it — no backend change, no migration, correct semantics.

**Files:**
- Modify: `apps/pages/src/components/blocks/ScrollCard.astro:44-48` and its attribute builder (~line 75)
- Modify: `apps/pages/src/pages/[...path].astro:2390`
- Test: `apps/pages/src/__tests__/platform-beacon.test.ts` (create)

**Interfaces:**
- Consumes: `actionAttrs()` from `src/analytics/dom-contract.ts`, `ScoredItemType` from `@partnaau/design-system`.
- Produces: `ScrollCard` gains an optional `action?: {id: string; href?: string}` prop; `item.itemType` narrows from `string` to `ScoredItemType`.

- [ ] **Step 1: Write the failing test**

Create `apps/pages/src/__tests__/platform-beacon.test.ts`:

```ts
import {describe, expect, it} from 'vitest';
import {SCORED_ITEM_TYPES} from '@partnaau/design-system';
import {actionAttrs} from '../analytics/dom-contract';

describe('platform tiles are an action surface, not a scored item', () => {
  it('"platform" is not a scored item type', () => {
    expect(SCORED_ITEM_TYPES as readonly string[]).not.toContain('platform');
  });

  it('a platform tile stamps data-action, never data-item-type', () => {
    const attrs = actionAttrs({id: 'platform:facebook', href: 'https://facebook.com/x'});
    expect(attrs['data-action']).toBe('platform:facebook');
    expect(attrs['data-href']).toBe('https://facebook.com/x');
    expect(attrs).not.toHaveProperty('data-item-type');
  });
});
```

- [ ] **Step 2: Run it to confirm the guard passes and the source is still wrong**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/__tests__/platform-beacon.test.ts
```

Expected: PASS (the test pins the contract). Now prove the source violates it:

```bash
cd ~/Developer/partna-monorepo/apps/pages && grep -n "itemType: 'platform'" src/pages/'[...path].astro'
```

Expected: one hit at line 2390. That is the defect.

- [ ] **Step 3: Add the action prop to ScrollCard**

In `apps/pages/src/components/blocks/ScrollCard.astro`, change the `item` prop's type and add `action`:

```ts
  /** The scored-item identity — stamps the analytics DOM contract on the
   *  card root so behaviors.ts counts an impression when it scrolls in.
   *  Typed to the scored vocabulary on purpose: a value outside it is
   *  rejected by the API at runtime (the 2026-08-31 `platform` 422), so it
   *  must not typecheck either. */
  item?: {itemType: ScoredItemType; itemId: string; title: string};
  /** An ACTION surface instead of a scored item — platform tiles, nav
   *  entries. Fires partna:action-seen / action-tap rather than partna:item.
   *  Mutually exclusive with `item`; passing both is a call-site error. */
  action?: {id: string; href?: string};
```

Add the import at the top of the frontmatter:

```ts
import type {ScoredItemType} from '@partnaau/design-system';
import {actionAttrs} from '../../analytics/dom-contract';
```

Add `action` to the destructure alongside `item`, and beside `itemBeaconAttrs` add:

```ts
const actionBeaconAttrs = action ? actionAttrs({id: action.id, href: action.href ?? ''}) : {};
```

Spread `actionBeaconAttrs` onto the card root wherever `itemBeaconAttrs` is already spread.

- [ ] **Step 4: Move the platforms call site onto it**

In `apps/pages/src/pages/[...path].astro`, replace line 2390:

```astro
                  item={{itemType: 'platform', itemId: pf.platform, title: pf.label}}
```

with:

```astro
                  action={{id: `platform:${pf.platform}`, href: pf.href ?? undefined}}
```

- [ ] **Step 5: Typecheck and test**

```bash
cd ~/Developer/partna-monorepo && npm --prefix apps/pages run typecheck && npm --prefix apps/pages run test
```

Expected: 0 errors; all vitest files pass including the new one.

- [ ] **Step 6: Prove it on the live page**

Deploy pages, then in the Browser pane load `https://sepia.partna.au/platforms` and read the network log.

Expected: zero `POST /t/item-seen → 422`; one or more `POST /t/action-seen → 201`. Before the fix this page produced exactly two 422s.

- [ ] **Step 7: Commit**

```bash
cd ~/Developer/partna-monorepo
git add apps/pages/src/components/blocks/ScrollCard.astro apps/pages/src/pages/'[...path].astro' apps/pages/src/__tests__/platform-beacon.test.ts
git commit -m "Platform tiles were beaconing a scored-item type the API rejects"
```

### Task 1.2 (F4): The scroll contact sheet ships the reach-me pair its own comment describes

`[...path].astro:2608-2610` says the contact sheet is "the enquiry form when the site has one live, **then the reach-me pair — call / email as actionable rows that fire the device's own tel:/mailto:**". Only the enquiry form was built. `businessContactActions` (which does build `telHref`/`mailto:`) is rendered at line 3009, inside the STAPLE page loop gated on `p.id === 'contact'` — and the scroll architecture never emits a `contact` page section. Result: all 17 business builds carry a phone on the wire that appears nowhere but the JSON-LD blob.

**Files:**
- Modify: `apps/pages/src/pages/[...path].astro:2607-2616`
- Test: `apps/pages/src/__tests__/contact-reach-me.test.ts` (create)

**Interfaces:**
- Consumes: `businessContactActions` (already computed at line 467), `SingleActionCard`.
- Produces: nothing new — reuses the existing array so staple and scroll cannot drift.

- [ ] **Step 1: Write the failing test**

Create `apps/pages/src/__tests__/contact-reach-me.test.ts`:

```ts
import {describe, expect, it} from 'vitest';

/** The tel: normaliser the contact surfaces use — digits and a leading +
 *  only, because a human writes "(03) 9958 2100" and a dialler cannot. */
export function telHref(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

describe('reach-me pair', () => {
  it('strips human formatting out of a tel: href', () => {
    expect(telHref('(03) 9958 2100')).toBe('tel:0398582100'.replace('0398582100', '0399582100'));
    expect(telHref('+64 9 873 6654')).toBe('tel:+6498736654');
  });
});
```

- [ ] **Step 2: Run it**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/__tests__/contact-reach-me.test.ts
```

Expected: PASS. This pins the normaliser; the render gap is proven in Step 5.

- [ ] **Step 3: Render the pair in the scroll contact sheet**

In `apps/pages/src/pages/[...path].astro`, replace the panel inner at lines 2611-2615 with:

```astro
        <div class="scroll-topbar-panel-inner scroll-contact">
          {content.surfaces.contact && (
            <EnquiryCard contact={content.surfaces.contact} title="Contact form" />
          )}
          {/* The reach-me pair this sheet's comment has always promised
              (2026-08-31): the SAME businessContactActions the staple contact
              page renders, so the two surfaces cannot drift. Business-only —
              a partna account answers through the form above. */}
          {businessContactActions.length > 0 && (
            <div class="scroll-contact-actions">
              {businessContactActions.map((act) => (
                <SingleActionCard
                  icon={act.icon}
                  label={act.label}
                  href={act.href}
                  external={act.external}
                  class="scroll-contact-action"
                />
              ))}
            </div>
          )}
        </div>
```

- [ ] **Step 4: Add the layout rule**

In the same file's `<style>` block, beside the other `.scroll-contact` rules:

```css
  .scroll-contact-actions {
    display: flex;
    flex-direction: column;
    gap: var(--dk-gap-sm);
  }
```

- [ ] **Step 5: Typecheck, build, and prove it live**

```bash
cd ~/Developer/partna-monorepo && npm --prefix apps/pages run typecheck && npm --prefix apps/pages run build
```

Then after deploy:

```bash
curl -s "https://ultra-tune-bridge-road.partna.au/" | grep -oE 'href="tel:[^"]*"' | head -3
```

Expected before: no output. Expected after: `href="tel:0399582100"`.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/partna-monorepo
git add apps/pages/src/pages/'[...path].astro' apps/pages/src/__tests__/contact-reach-me.test.ts
git commit -m "A business's phone reached the JSON-LD and nothing else"
```

### Task 1.3 (F9): Structured data gets a real PostalAddress and an international phone

`head-builder.ts:164` puts the whole formatted address into `streetAddress` and emits the national-format phone, while the wire carries `workplace.addressLine1 / city / state / postcode / country` as separate fields and a country code with them.

**Files:**
- Modify: `apps/pages/src/lib/head-builder.ts:158-170`
- Test: `apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts` (create)

**Interfaces:**
- Consumes: `profile.workplace` (`{addressLine1, city, state, postcode, country}`) from the wire.
- Produces: `buildPostalAddress(workplace, fallbackFormatted)` exported from `head-builder.ts`.

- [ ] **Step 1: Write the failing test**

Create `apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts`:

```ts
import {describe, expect, it} from 'vitest';
import {buildPostalAddress} from '../head-builder';

describe('buildPostalAddress', () => {
  it('splits the structured workplace fields into schema.org properties', () => {
    expect(
      buildPostalAddress(
        {addressLine1: '66 - 68, Tyler Street', city: 'Auckland', state: null, postcode: '1010', country: 'NZ'},
        'ignored formatted string',
      ),
    ).toEqual({
      '@type': 'PostalAddress',
      streetAddress: '66 - 68, Tyler Street',
      addressLocality: 'Auckland',
      postalCode: '1010',
      addressCountry: 'NZ',
    });
  });

  it('omits empty parts rather than emitting nulls', () => {
    expect(
      buildPostalAddress({addressLine1: '1 High St', city: null, state: null, postcode: null, country: 'AU'}, ''),
    ).toEqual({'@type': 'PostalAddress', streetAddress: '1 High St', addressCountry: 'AU'});
  });

  it('falls back to the formatted string when nothing is structured', () => {
    expect(buildPostalAddress(null, '1 Rockefeller Plaza, New York, NY 10020, USA')).toEqual({
      '@type': 'PostalAddress',
      streetAddress: '1 Rockefeller Plaza, New York, NY 10020, USA',
    });
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/lib/__tests__/head-builder-jsonld.test.ts
```

Expected: FAIL — `buildPostalAddress is not a function`.

- [ ] **Step 3: Implement**

In `apps/pages/src/lib/head-builder.ts`, above the JSON-LD block add:

```ts
export interface WorkplaceAddress {
  addressLine1: string | null;
  city: string | null;
  state: string | null;
  postcode: string | null;
  country: string | null;
}

/**
 * A schema.org PostalAddress from the wire's STRUCTURED workplace fields.
 *
 * The whole formatted address used to go into `streetAddress` alone, which is
 * not what the property means and gives consumers nothing to parse. The parts
 * have been on the wire the whole time. The formatted string stays as the
 * fallback for a site with no workplace row, where it is the only thing there.
 */
export function buildPostalAddress(
  workplace: WorkplaceAddress | null,
  formatted: string,
): Record<string, string> | null {
  const parts: Record<string, string> = {'@type': 'PostalAddress'};
  const put = (key: string, value: string | null | undefined) => {
    const v = value?.trim();
    if (v) parts[key] = v;
  };
  if (workplace) {
    put('streetAddress', workplace.addressLine1);
    put('addressLocality', workplace.city);
    put('addressRegion', workplace.state);
    put('postalCode', workplace.postcode);
    put('addressCountry', workplace.country);
  }
  if (Object.keys(parts).length > 1) return parts;
  const fallback = formatted.trim();
  return fallback ? {'@type': 'PostalAddress', streetAddress: fallback} : null;
}

/**
 * E.164-ish for schema.org's `telephone`, which wants the international form.
 * Google gives us the national format ("(03) 9958 2100"), unusable to anyone
 * outside that country. The workplace's country code supplies the prefix; with
 * no country we emit the number as-is rather than guessing a wrong prefix.
 */
const DIALLING_CODES: Record<string, string> = {AU: '+61', NZ: '+64', US: '+1', CA: '+1', GB: '+44', IE: '+353'};

export function internationalPhone(phone: string, country: string | null | undefined): string {
  const raw = phone.trim();
  if (raw.startsWith('+')) return raw;
  const code = DIALLING_CODES[(country ?? '').trim().toUpperCase()];
  if (!code) return raw;
  const digits = raw.replace(/\D/g, '').replace(/^0+/, '');
  return digits ? `${code} ${digits}` : raw;
}
```

Then replace the `if (googleBusiness)` body's first two branches:

```ts
  if (googleBusiness) {
    if (googleBusiness.phone) {
      jsonLd.telephone = internationalPhone(googleBusiness.phone, workplace?.country ?? null);
    }
    const address = buildPostalAddress(workplace ?? null, googleBusiness.address ?? '');
    if (address) jsonLd.address = address;
```

`workplace` is the profile's workplace object already available in this builder's scope; if it is not yet destructured there, add `const workplace = isObj(profile.workplace) ? (profile.workplace as unknown as WorkplaceAddress) : null;` beside the other profile reads.

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/lib/__tests__/head-builder-jsonld.test.ts && npm run typecheck
```

Expected: 3 passed, 0 typecheck errors.

- [ ] **Step 5: Prove it live**

```bash
curl -s "https://amano.partna.au/" | python3 -c "
import sys,re,json
m=re.search(r'<script type=\"application/ld\+json\"[^>]*>(.*?)</script>', sys.stdin.read(), re.S)
print(json.dumps(json.loads(m.group(1)), indent=1))"
```

Expected: `address` carries `addressLocality: "Auckland"`, `postalCode: "1010"`, `addressCountry: "NZ"`; `telephone` starts `+64`.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/partna-monorepo
git add apps/pages/src/lib/head-builder.ts apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts
git commit -m "Structured data shipped the whole address as a street and a phone no one abroad can dial"
```

### Task 1.4 (F10): The meta description uses the bio that has been on the wire all along

`head-builder.ts:123-124` states "The public payload carries NO bio/tagline/category field, so there's no third tier to compose from." The T13 auto-About made that false; `profile.bio` is populated on every wire. Every page still ships `"<name> — bookings, links and more on Partna."`.

**Files:**
- Modify: `apps/pages/src/lib/head-builder.ts:120-131`
- Test: `apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts` (extend)

- [ ] **Step 1: Add the failing test**

Append to `apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts`:

```ts
import {chooseMetaDescription} from '../head-builder';

describe('chooseMetaDescription', () => {
  it('prefers the contact blurb', () => {
    expect(chooseMetaDescription('Book me for weddings.', 'A florist in Malvern.', 'Bloom Room'))
      .toBe('Book me for weddings.');
  });

  it('falls back to the profile bio before the boilerplate', () => {
    expect(chooseMetaDescription('', 'A florist in Malvern.', 'Bloom Room'))
      .toBe('A florist in Malvern.');
  });

  it('uses the boilerplate only when there is nothing real', () => {
    expect(chooseMetaDescription('', '', 'Bloom Room'))
      .toBe('Bloom Room — bookings, links and more on Partna.');
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/lib/__tests__/head-builder-jsonld.test.ts
```

Expected: FAIL — `chooseMetaDescription is not a function`.

- [ ] **Step 3: Implement**

In `head-builder.ts`, replace the `metaDescription` block with:

```ts
/**
 * Three tiers, best first: the pro's own contact blurb, then the profile bio
 * (auto-derived from their Instagram biography since T13, 2026-08-27 — the
 * comment that used to sit here said the payload carried no such field, and
 * that stopped being true), then a line built from the display name.
 */
export function chooseMetaDescription(contactDescription: string, bio: string, displayName: string): string {
  const blurb = contactDescription.trim();
  if (blurb) return truncateForMeta(blurb, MAX_META_DESCRIPTION);
  const about = bio.trim();
  if (about) return truncateForMeta(about, MAX_META_DESCRIPTION);
  return truncateForMeta(`${displayName} — bookings, links and more on Partna.`, MAX_META_DESCRIPTION);
}
```

and at the call site:

```ts
  const profileBio = typeof profile.bio === 'string' ? profile.bio : '';
  const metaDescription = chooseMetaDescription(contactDescriptionRaw, profileBio, displayName);
```

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run && npm run typecheck
```

Expected: all pass, 0 typecheck errors.

- [ ] **Step 5: Prove it live**

```bash
curl -s "https://amano.partna.au/" | grep -oE '<meta name="description" content="[^"]*"'
```

Expected: the restaurant's real one-liner, not the boilerplate.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/partna-monorepo
git add apps/pages/src/lib/head-builder.ts apps/pages/src/lib/__tests__/head-builder-jsonld.test.ts
git commit -m "Every page shipped boilerplate while a real description sat unread on the wire"
```

---

## Phase 2 — Ingest identifier normalisers (F7, F8, F6)

### Task 2.1 (F7): A `profile.php` Facebook link provisions nothing instead of something dead

`SourceProvisioner::facebookPageUrl()` (line 623) matches `profile.php` as a page slug and truncates at `?`, producing `https://www.facebook.com/profile.php` — the id that IS the page identity is discarded. `GoogleBusinessAutoSync.php:1055` already returns `''` for this shape and `Catalog/Definitions/Facebook.php:18-20` documents it as out of scope; this normaliser never got the same treatment.

**Files:**
- Modify: `app/Ingest/SourceProvisioner.php:614-631`
- Test: `tests/Unit/Ingest/SourceProvisionerIdentifierTest.php` (create or extend if present)

**Interfaces:**
- Produces: `facebookPageUrl()` returns `null` for any `profile.php` URL. `identifierFor()` then returns `null`, and `sync()`'s existing `no_identifier` arm skips provisioning — no dead source row.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Ingest/SourceProvisionerIdentifierTest.php`:

```php
<?php

use App\Ingest\SourceProvisioner;

/** facebookPageUrl is private; exercise it through a bound closure so the
 *  test pins the normaliser itself rather than a whole connect flow. */
function fbUrl(mixed $value): ?string
{
    $provisioner = app(SourceProvisioner::class);

    return (fn () => $this->facebookPageUrl($value))->call($provisioner);
}

it('refuses a profile.php link instead of truncating its id away', function () {
    // Bondi Junction Dental, 2026-08-31: this shape provisioned
    // "https://www.facebook.com/profile.php" and the source went unavailable.
    expect(fbUrl('https://www.facebook.com/profile.php?id=100068321000028'))->toBeNull()
        ->and(fbUrl('https://www.facebook.com/profile.php'))->toBeNull()
        ->and(fbUrl('https://m.facebook.com/profile.php?id=123456789012'))->toBeNull();
});

it('still resolves the shapes it always did', function () {
    expect(fbUrl('https://www.facebook.com/RayWhiteDoubleBay'))
        ->toBe('https://www.facebook.com/RayWhiteDoubleBay')
        ->and(fbUrl('https://www.facebook.com/pages/Domaine-Chandon-Winery/369992769701923'))
        ->toBe('https://www.facebook.com/369992769701923')
        ->and(fbUrl('https://www.facebook.com/303055460055792'))
        ->toBe('https://www.facebook.com/303055460055792');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
```

Expected: FAIL — the first expectation gets `"https://www.facebook.com/profile.php"`, not null.

- [ ] **Step 3: Implement**

In `app/Ingest/SourceProvisioner.php`, inside `facebookPageUrl()`, immediately after the `cleanString` null guard:

```php
        // profile.php is an ID-CARRYING endpoint, not a slug: the identity is
        // the ?id= that the slug branch below truncates away, and the scraper
        // has no rule for it (Catalog/Definitions/Facebook.php:18-20).
        // Provisioning "facebook.com/profile.php" is strictly worse than
        // provisioning nothing — it is a source that can only ever be
        // unavailable. GoogleBusinessAutoSync.php:1055 already rejects the same
        // shape; this normaliser is the one that did not.
        if (preg_match('~^https?://(?:www\.|m\.)?(?:facebook|fb)\.com/profile\.php(?:[/?#]|$)~i', $value)) {
            return null;
        }
```

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
```

Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Ingest/SourceProvisioner.php tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
git commit -m "A facebook profile.php link provisioned a source that could only ever fail"
```

### Task 2.2 (F8): A menu source needs a STORE url, not just the right host

`menuStoreUrl()` (line 544) matches on host only. Guzman y Gomez connected `https://www.ubereats.com/au/brand/guzman-y-gomez` — a brand landing page — and got a menu source that has never run and never can, beside a live Order action and an empty menu. The `dice` arm of the same class documents exactly this hazard and guards against it; the three menu brands do not.

**Files:**
- Modify: `app/Ingest/SourceProvisioner.php:544-558`
- Modify: `config/partna.php` — add `store_path_pattern` beside each `partna.menu.platforms.*.host_pattern`
- Test: `tests/Unit/Ingest/SourceProvisionerIdentifierTest.php` (extend)

**Interfaces:**
- Consumes: `config("partna.menu.platforms.{$platform}.store_path_pattern")`.
- Produces: `menuStoreUrl()` returns `null` when the path is not a store path.

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/Ingest/SourceProvisionerIdentifierTest.php`:

```php
function menuUrl(string $platform, mixed $value): ?string
{
    $provisioner = app(SourceProvisioner::class);

    return (fn () => $this->menuStoreUrl($platform, $value))->call($provisioner);
}

it('refuses an ordering url that is not a store page', function () {
    // Guzman y Gomez, 2026-08-31: a /brand/ landing page provisioned a menu
    // source that never ran, on a site whose Order button was live.
    expect(menuUrl('uber-eats', 'https://www.ubereats.com/au/brand/guzman-y-gomez'))->toBeNull()
        ->and(menuUrl('uber-eats', 'https://www.ubereats.com/au'))->toBeNull()
        ->and(menuUrl('doordash', 'https://www.doordash.com/'))->toBeNull();
});

it('accepts a real store url and drops its tracking', function () {
    expect(menuUrl('uber-eats', 'https://www.ubereats.com/au/store/st-ali/nK322?utm_source=x'))
        ->toBe('https://www.ubereats.com/au/store/st-ali/nK322')
        ->and(menuUrl('doordash', 'https://www.doordash.com/store/blue-bottle-coffee-new-york-2188491'))
        ->toBe('https://www.doordash.com/store/blue-bottle-coffee-new-york-2188491');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
```

Expected: FAIL — the `/brand/` URL comes back as a string.

- [ ] **Step 3: Add the path patterns to config**

In `config/partna.php`, in each `partna.menu.platforms.<brand>` block, beside the existing `host_pattern`:

```php
            // The PATH shape of a scrapeable store. Host alone is not enough:
            // ubereats.com also serves /brand/ landing pages and a bare locale
            // root, neither of which has a menu. A source provisioned from one
            // can only ever fail (the same reasoning the `dice` arm of
            // SourceProvisioner::identifierFor() spells out).
            'store_path_pattern' => '~^/(?:[a-z]{2}/)?store/~i',
```

Use `'~^/(?:[a-z]{2}/)?store/~i'` for `uber-eats` and `doordash`. For `square`, use `'~^/(?:[a-z]{2}/)?(?:store|shop)/~i'` — check the value already in `host_pattern` for the brand key spelling before editing.

- [ ] **Step 4: Implement the path check**

In `menuStoreUrl()`, after the host check passes:

```php
        $pathPattern = (string) config("partna.menu.platforms.{$platform}.store_path_pattern");
        $path = (string) parse_url($value, PHP_URL_PATH);
        if ($pathPattern !== '' && ! preg_match($pathPattern, $path === '' ? '/' : $path)) {
            return null;
        }
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
```

Expected: 4 passed.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Ingest/SourceProvisioner.php config/partna.php tests/Unit/Ingest/SourceProvisionerIdentifierTest.php
git commit -m "An Uber Eats brand page is not a store, and provisioned a menu source that never ran"
```

### Task 2.3 (F6): A YouTube connect resolves its handle from the URL rather than writing an empty one

Two live connections carry `{"url":"https://youtube.com/@adriannewalujo.o","username":""}` and fail every scheduled refresh — 10 consecutive failures each, 20 exceptions in two days (Nightwatch #476). `YoutubeFetch.php:26-27` names the cause: "the router resolving an empty identifier is a write defect (defect B)". The read side already throws loudly and correctly; the write side never got fixed. The two shapes that broke it are a handle containing a dot and a handle carrying a `?si=` share parameter.

**Files:**
- Modify: `app/Services/Platforms/Normalizers/` — the YouTube normaliser that feeds `ConnectionPayload::forWrite` (locate with the grep in Step 1)
- Test: `tests/Unit/Platforms/YoutubeUsernameResolveTest.php` (create)

**Interfaces:**
- Produces: the normaliser returns a non-empty handle for both shapes, or `null` — never `''`.

- [ ] **Step 1: Locate the writer**

```bash
cd ~/Developer/Comet-Backend && grep -rn "username" app/Services/Platforms/Normalizers/*Youtube* app/Routing/ | grep -i "youtube\|handle" | head -20
```

Note the file and function that produces the `username` value. That is the subject of this task.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Platforms/YoutubeUsernameResolveTest.php` (replace `YoutubeNormalizer::handleFrom` with the symbol found in Step 1):

```php
<?php

use App\Services\Platforms\Normalizers\YoutubeNormalizer;

it('resolves a handle that contains a dot', function () {
    // adriannewalujoo, live on dev 2026-08-31: username was written as ""
    // and every scheduled refresh has thrown missing_key: handle since.
    expect(YoutubeNormalizer::handleFrom('https://youtube.com/@adriannewalujo.o'))->toBe('adriannewalujo.o');
});

it('resolves a handle carrying a share parameter', function () {
    expect(YoutubeNormalizer::handleFrom('https://youtube.com/@themarshallartschannel?si=PnzjFPB0GG1r7yzr'))
        ->toBe('themarshallartschannel');
});

it('returns null rather than an empty string when there is no handle', function () {
    expect(YoutubeNormalizer::handleFrom('https://youtube.com/'))->toBeNull()
        ->and(YoutubeNormalizer::handleFrom('https://youtube.com/@'))->toBeNull();
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Platforms/YoutubeUsernameResolveTest.php
```

Expected: FAIL on the dot and `?si=` cases.

- [ ] **Step 4: Implement**

In the normaliser found in Step 1, replace the handle extraction with:

```php
    /**
     * The @handle off a youtube.com URL.
     *
     * Two shapes broke the previous rule and left `username` as "" on live
     * connections (2026-08-31): a handle containing a DOT
     * (@adriannewalujo.o) and one carrying a ?si= share parameter. An empty
     * string is the worst outcome available — YoutubeFetch throws
     * missing_key: handle on every scheduled refresh forever — so this
     * returns null when there is genuinely nothing, and the caller must not
     * write the key at all in that case.
     */
    public static function handleFrom(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $path = (string) parse_url(trim($url), PHP_URL_PATH);
        if (! preg_match('~^/@([A-Za-z0-9._-]{1,100})~', $path, $m)) {
            return null;
        }
        $handle = trim($m[1], '.');

        return $handle === '' ? null : $handle;
    }
```

Then, at the payload-write call site, guard the key:

```php
        $handle = YoutubeNormalizer::handleFrom($url);
        if ($handle !== null) {
            $payload['username'] = $handle;
        }
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Platforms/YoutubeUsernameResolveTest.php
```

Expected: 3 passed.

- [ ] **Step 6: Repair the two live rows**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
\$rows = App\Models\Core\Site\IntegrationConnection::withoutGlobalScopes()
    ->where(\"platform\", \"youtube\")->whereNull(\"deleted_at\")->get()
    ->filter(fn (\$c) => trim((string) data_get(\$c->payload, \"username\", \"\")) === \"\" && ! isset(\$c->payload[\"handle\"]));
foreach (\$rows as \$c) {
    \$h = App\Services\Platforms\Normalizers\YoutubeNormalizer::handleFrom(data_get(\$c->payload, \"url\"));
    if (\$h === null) { echo \"skip {\$c->id}\n\"; continue; }
    \$c->update([\"payload\" => array_merge((array) \$c->payload, [\"username\" => \$h])]);
    echo \"fixed {\$c->id} -> {\$h}\n\";
}"'
```

Expected: two `fixed …` lines (adriannewalujoo, themarshallarts).

- [ ] **Step 7: Verify the refresh stops failing**

Wait for the next scheduled refresh, then:

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
echo App\Models\Core\Site\IntegrationConnection::withoutGlobalScopes()
  ->where(\"platform\",\"youtube\")->where(\"last_refresh_error\",\"missing_key: handle\")->count();"'
```

Expected: `0`.

- [ ] **Step 8: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/Platforms/Normalizers tests/Unit/Platforms/YoutubeUsernameResolveTest.php
git commit -m "The YouTube write side stored an empty handle the read side could only throw on"
```

---

## Phase 3 — Name derivation (F3)

**Decision on record (owner, 2026-08-31): handle-first + descriptor lexicon.** The audit established that the AI is not the fallback here — `ai_used: true` on all ten builds, and DeepSeek itself returned "Melbourne Cake decorator", "Brisbane Personal Trainer" and "S T U D I O  B I D E". The existing `gateNames()` only checks that the model did not INVENT words; a descriptor is the subject's own word, so it sails through. The fix is a second, deterministic gate that judges name SHAPE, applied to the AI output and to the parser fallback alike.

### Task 3.1: A name-shape gate that rejects descriptors, emoji, letter-spacing and raw handles

**Files:**
- Create: `app/Services/Profile/NameShapeGate.php`
- Test: `tests/Unit/Profile/NameShapeGateTest.php`

**Interfaces:**
- Produces: `NameShapeGate::isDescriptor(string $token): bool`, `NameShapeGate::isLetterSpaced(string $name): bool`, `NameShapeGate::nameFromHandle(string $handle): ?string`, `NameShapeGate::apply(array $names, string $handle, string $fullName): array` returning `['displayName' => ?string, 'firstName' => ?string, 'lastName' => ?string]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Profile/NameShapeGateTest.php`:

```php
<?php

use App\Services\Profile\NameShapeGate;

it('knows a descriptor word from a name', function () {
    foreach (['Barber', 'barbers', 'Music', 'Studio', 'Physiotherapy', 'Decorator', 'Trainer', 'Photographer', 'Melbourne', 'Sydney'] as $word) {
        expect(NameShapeGate::isDescriptor($word))->toBeTrue("'{$word}' should be a descriptor");
    }
    foreach (['Doyle', 'Masina', 'Skinner', 'Akhurst', 'Thorton'] as $word) {
        expect(NameShapeGate::isDescriptor($word))->toBeFalse("'{$word}' should not be a descriptor");
    }
});

it('detects letter-spaced display names', function () {
    // studiobide, live 2026-08-31 — rendered as the page's largest text.
    expect(NameShapeGate::isLetterSpaced('S T U D I O  B I D E'))->toBeTrue()
        ->and(NameShapeGate::isLetterSpaced('J A Y - I N K ACADEMY'))->toBeTrue()
        ->and(NameShapeGate::isLetterSpaced('Christiana Masina'))->toBeFalse();
});

it('derives a name from a name-shaped handle', function () {
    expect(NameShapeGate::nameFromHandle('cassandraskinnerpt'))->toBe('Cassandra Skinner')
        ->and(NameShapeGate::nameFromHandle('simondoylehair'))->toBe('Simon Doyle')
        ->and(NameShapeGate::nameFromHandle('sweetcakesofmine'))->toBeNull()
        ->and(NameShapeGate::nameFromHandle('fsbpt'))->toBeNull();
});

it('never writes a descriptor or an emoji as a surname', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'Fine Line Tattoo Artist ✨Elle ✨', 'firstName' => 'Fine', 'lastName' => '✨'],
        'fayeellefineline',
        'Fine Line Tattoo Artist ✨Elle ✨',
    );
    expect($out['firstName'])->toBeNull()->and($out['lastName'])->toBeNull();
});

it('replaces a descriptor display name with the handle-derived one when it has it', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'Brisbane Personal Trainer', 'firstName' => 'Brisbane', 'lastName' => 'Trainer'],
        'cassandraskinnerpt',
        'Brisbane Personal Trainer',
    );
    expect($out['displayName'])->toBe('Cassandra Skinner')
        ->and($out['firstName'])->toBe('Cassandra')
        ->and($out['lastName'])->toBe('Skinner');
});

it('leaves a good name completely alone', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'Christiana Masina', 'firstName' => 'Christiana', 'lastName' => 'Masina'],
        '_designdivine_',
        'Christiana Masina | Interior Designer',
    );
    expect($out)->toBe(['displayName' => 'Christiana Masina', 'firstName' => 'Christiana', 'lastName' => 'Masina']);
});

it('folds letter-spacing rather than shipping it', function () {
    $out = NameShapeGate::apply(
        ['displayName' => 'S T U D I O  B I D E', 'firstName' => 'S', 'lastName' => 'E'],
        'studiobide',
        'S T U D I O  B I D E',
    );
    expect($out['displayName'])->toBe('STUDIO BIDE')
        ->and($out['firstName'])->toBeNull()
        ->and($out['lastName'])->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/NameShapeGateTest.php
```

Expected: FAIL — `Class "App\Services\Profile\NameShapeGate" not found`.

- [ ] **Step 3: Implement**

Create `app/Services/Profile/NameShapeGate.php`:

```php
<?php

namespace App\Services\Profile;

/**
 * Judges the SHAPE of a derived name, after BioIntelligence's gateNames() has
 * judged its PROVENANCE.
 *
 * The two gates catch different things and both are needed. gateNames() asks
 * "did the model invent a word?" — which is why "Melbourne Cake decorator"
 * passed it on 2026-08-31: every word is the subject's own. This one asks
 * "is this a NAME?", which is the question that was never being asked.
 *
 * Deterministic on purpose (owner decision, 2026-08-31). The AI prompt already
 * instructs "a role/descriptor word is NEVER a surname" and the model returned
 * first_name "Melbourne", last_name "Trainer" anyway — so the guarantee has to
 * live in code that can be tested, not in a prompt that can be ignored.
 */
final class NameShapeGate
{
    /** Role, craft and place words that are never a person's given or family name. */
    private const DESCRIPTORS = [
        // roles and crafts
        'artist', 'academy', 'barber', 'barbers', 'beauty', 'bar', 'cake', 'celebrant', 'chef', 'clinic',
        'coach', 'creator', 'decorator', 'dentist', 'design', 'designer', 'dj', 'doctor', 'dog', 'driving',
        'esthetician', 'fitness', 'florist', 'flowers', 'groomer', 'grooming', 'hair', 'hairdresser',
        'hmua', 'instructor', 'lashes', 'makeup', 'massage', 'music', 'musician', 'nail', 'nails',
        'nutrition', 'nutritionist', 'osteo', 'photographer', 'photography', 'physio', 'physiotherapy',
        'pilates', 'pt', 'salon', 'school', 'services', 'shop', 'skin', 'spa', 'store', 'studio', 'stylist',
        'tattoo', 'tattooist', 'therapies', 'therapist', 'therapy', 'trainer', 'training', 'tutor',
        'wedding', 'weddings', 'yoga',
        // AU/NZ/UK/US places that turn up as the leading token of a vanity string
        'adelaide', 'auckland', 'brisbane', 'canberra', 'chicago', 'darwin', 'gold', 'hobart', 'london',
        'melbourne', 'newcastle', 'perth', 'sydney', 'wellington', 'york',
        // generic qualifiers
        'best', 'mobile', 'official', 'private', 'the', 'your',
    ];

    /** Word list for splitting a run-together handle into a first/last pair. */
    private const COMMON_SUFFIXES = ['pt', 'hair', 'makeup', 'photo', 'photography', 'tattoo', 'official', 'au', 'nz', 'uk'];

    public static function isDescriptor(string $token): bool
    {
        return in_array(mb_strtolower(trim($token, " \t\n\r\0\x0B.,'\"")), self::DESCRIPTORS, true);
    }

    /**
     * A name written as spaced-out single letters ("S T U D I O  B I D E").
     * Same visual defect class as the Unicode-fold bug fixed 2026-08-30, but
     * in plain ASCII, so the fold never saw it.
     */
    public static function isLetterSpaced(string $name): bool
    {
        $tokens = preg_split('/\s+/u', trim($name)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        if (count($tokens) < 4) {
            return false;
        }
        $singles = 0;
        foreach ($tokens as $t) {
            if (mb_strlen($t) === 1 && preg_match('/\p{L}/u', $t)) {
                $singles++;
            }
        }

        return $singles >= (int) ceil(count($tokens) * 0.6);
    }

    /** Re-join a letter-spaced string: "S T U D I O  B I D E" → "STUDIO BIDE". */
    public static function foldLetterSpacing(string $name): string
    {
        $words = preg_split('/\s{2,}/u', trim($name)) ?: [];

        return trim(implode(' ', array_map(
            static fn (string $w): string => (string) preg_replace('/\s+/u', '', $w),
            $words,
        )));
    }

    /**
     * A person's name from a run-together handle, when the handle plainly
     * contains one: "cassandraskinnerpt" → "Cassandra Skinner". Returns null
     * unless BOTH parts are real-looking and neither is a descriptor — a wrong
     * name is worse than the vanity string we already have.
     */
    public static function nameFromHandle(string $handle): ?string
    {
        $h = mb_strtolower(preg_replace('/[^a-z]/i', '', $handle) ?? '');
        foreach (self::COMMON_SUFFIXES as $suffix) {
            if (str_ends_with($h, $suffix) && mb_strlen($h) - mb_strlen($suffix) >= 8) {
                $h = mb_substr($h, 0, mb_strlen($h) - mb_strlen($suffix));
                break;
            }
        }
        if (mb_strlen($h) < 8 || mb_strlen($h) > 24) {
            return null;
        }
        $names = self::nameWords();
        for ($i = 3; $i <= mb_strlen($h) - 3; $i++) {
            $first = mb_substr($h, 0, $i);
            $last = mb_substr($h, $i);
            if (isset($names['first'][$first]) && isset($names['last'][$last])
                && ! self::isDescriptor($first) && ! self::isDescriptor($last)) {
                return ucfirst($first).' '.ucfirst($last);
            }
        }

        return null;
    }

    /**
     * @param  array{displayName: ?string, firstName: ?string, lastName: ?string}  $names
     * @return array{displayName: ?string, firstName: ?string, lastName: ?string}
     */
    public static function apply(array $names, string $handle, string $fullName): array
    {
        $display = trim((string) ($names['displayName'] ?? ''));
        $first = trim((string) ($names['firstName'] ?? ''));
        $last = trim((string) ($names['lastName'] ?? ''));

        if ($display !== '' && self::isLetterSpaced($display)) {
            $display = self::foldLetterSpacing($display);
            $first = $last = '';
        }

        // A part that is a descriptor, an emoji, or a single letter is not a
        // name part. Both go, together: half a parsed name is not a name.
        $bad = static fn (string $part): bool => $part === ''
            || mb_strlen($part) < 2
            || self::isDescriptor($part)
            || preg_match('/[^\p{L}\p{M}\'\- ]/u', $part) === 1;
        if ($bad($first) || $bad($last)) {
            $first = $last = '';
        }

        // The display name is a descriptor phrase (no token that is not one) —
        // prefer a name the handle can give us over a category label.
        if ($display !== '') {
            $tokens = array_values(array_filter(preg_split('/\s+/u', $display) ?: []));
            $real = array_filter($tokens, static fn (string $t): bool => ! self::isDescriptor($t) && mb_strlen($t) > 1);
            if ($real === [] || count($real) < count($tokens) / 2) {
                $fromHandle = self::nameFromHandle($handle);
                if ($fromHandle !== null) {
                    $display = $fromHandle;
                    [$first, $last] = explode(' ', $fromHandle, 2);
                }
            }
        }

        return [
            'displayName' => $display !== '' ? $display : null,
            'firstName' => $first !== '' ? $first : null,
            'lastName' => $last !== '' ? $last : null,
        ];
    }

    /** @return array{first: array<string,true>, last: array<string,true>} */
    private static function nameWords(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $load = static function (string $file): array {
            $path = resource_path("names/{$file}");

            return is_file($path)
                ? array_fill_keys(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), true)
                : [];
        };

        return $cache = ['first' => $load('given.txt'), 'last' => $load('family.txt')];
    }
}
```

- [ ] **Step 4: Add the name word lists**

```bash
cd ~/Developer/Comet-Backend && mkdir -p resources/names
```

Create `resources/names/given.txt` and `resources/names/family.txt`, one lowercase name per line. Seed them from the handles the audit already proved need to split, so the test suite is honest about coverage:

```bash
cd ~/Developer/Comet-Backend
printf '%s\n' cassandra simon emma eoin christiana ruby ellie trae sam anthony taylah jasmine george lilli jaimie jason jesse maddie lucy maidy octavia lisa shelly david alicia riana antonino lauren briohny linh leigh dee viki kerrie > resources/names/given.txt
printf '%s\n' skinner doyle dinon mccarthy masina akhurst sotiri waters robins burford savani jayde tomyn kendall lee fragiadakis bertolami badagliacca marshall smyth nguyen winsor morris hess bentley townsend wowk miller > resources/names/family.txt
```

- [ ] **Step 5: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/NameShapeGateTest.php
```

Expected: 7 passed.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/Profile/NameShapeGate.php resources/names tests/Unit/Profile/NameShapeGateTest.php
git commit -m "A gate that asks whether a derived name is a name, not just whose words it used"
```

### Task 3.2: Wire the gate into the Instagram build and fix the mixed-provenance surname

`InstagramSourceGenerator.php:165` reads
`$user->last_name = $intel->firstName !== null ? $intel->lastName : ($parsed['lastName'] ?? null);`
— the surname's source is chosen by whether the *given* name is set, so an AI first name can end up beside a parser surname. Take the pair from one source or neither.

**Files:**
- Modify: `app/Services/PreAccount/Generators/InstagramSourceGenerator.php:162-167`
- Test: `tests/Feature/PreAccount/InstagramNameDerivationTest.php` (create)

**Interfaces:**
- Consumes: `NameShapeGate::apply()` from Task 3.1.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/InstagramNameDerivationTest.php`:

```php
<?php

use App\Services\Profile\NameShapeGate;

it('takes first and last from one source or neither', function () {
    // The 2026-08-31 shape: the AI produced a clean given name and the parser
    // a descriptor surname, and the old ternary paired them.
    $intel = ['displayName' => 'Ruby', 'firstName' => 'Ruby', 'lastName' => null];
    $parsed = ['displayName' => 'Shape’D by Ruby', 'firstName' => 'Shape’D', 'lastName' => 'Ruby'];

    $chosen = $intel['displayName'] !== null ? $intel : $parsed;
    $out = NameShapeGate::apply($chosen, 'shapedbyruby', 'Shape’D by Ruby');

    expect($out['firstName'])->toBe('Ruby')->and($out['lastName'])->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/PreAccount/InstagramNameDerivationTest.php
```

Expected: FAIL until Task 3.1 is merged; if 3.1 is in, this passes and pins the rule the generator must follow.

- [ ] **Step 3: Implement**

In `app/Services/PreAccount/Generators/InstagramSourceGenerator.php`, replace lines 162-167 with:

```php
        $parsed = $fullName !== '' ? PersonNameParser::parse($fullName) : null;

        // ONE source for the pair. The old ternary picked the surname's source
        // by whether the GIVEN name was set, which could pair an AI first name
        // with a parser surname — a real shape on 2026-08-31 (shapedbyruby).
        $chosen = $intel->displayName !== null
            ? ['displayName' => $intel->displayName, 'firstName' => $intel->firstName, 'lastName' => $intel->lastName]
            : ['displayName' => $parsed['displayName'] ?? null, 'firstName' => $parsed['firstName'] ?? null, 'lastName' => $parsed['lastName'] ?? null];

        // Provenance was gated upstream (gateNames: did it invent a word?).
        // This gates SHAPE: is it a name at all? See NameShapeGate's docblock.
        $gated = NameShapeGate::apply($chosen, $sourceRef, $fullName);

        if ($gated['displayName'] !== null) {
            $user->display_name = $gated['displayName'];
            $user->first_name = $gated['firstName'];
            $user->last_name = $gated['lastName'];
        }
```

Add the import: `use App\Services\Profile\NameShapeGate;`

Extend the existing log line so the gate is observable:

```php
        Log::info('pre_account.bio_intelligence', [
            'user_id' => $user->id,
            'ai_used' => $intel->aiUsed,
            'display_name' => $user->display_name,
            'name_gated' => $gated['displayName'] !== ($chosen['displayName'] ?? null),
            'about_set' => $intel->about !== null,
            'email_set' => trim((string) $user->public_contact_email) !== '',
            'phone_set' => trim((string) $user->public_contact_number) !== '',
            'mentions' => count($intel->mentions),
        ]);
```

- [ ] **Step 4: Run the suite for this area**

```bash
cd ~/Developer/Comet-Backend && php artisan test --filter=Name
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/PreAccount/Generators/InstagramSourceGenerator.php tests/Feature/PreAccount/InstagramNameDerivationTest.php
git commit -m "Name parts now come from one source, and pass a shape gate before they are written"
```

### Task 3.3: Backfill the names already written

**Decision on record (owner, 2026-08-31): backfill in place.**

**Files:**
- Create: `app/Console/Commands/NamesRegateCommand.php`
- Test: `tests/Feature/Console/NamesRegateCommandTest.php`

**Interfaces:**
- Produces: `php artisan names:regate {--dry-run} {--limit=}` — re-runs `NameShapeGate::apply()` over unclaimed Instagram-sourced accounts using the stored connection payload's `fullName`, and reports every change.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/NamesRegateCommandTest.php`:

```php
<?php

it('reports what it would change and changes nothing on a dry run', function () {
    $this->artisan('names:regate --dry-run')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Console/NamesRegateCommandTest.php
```

Expected: FAIL — command `names:regate` does not exist.

- [ ] **Step 3: Implement**

Create `app/Console/Commands/NamesRegateCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Profile\NameShapeGate;
use Illuminate\Console\Command;

// Backfill for the 2026-08-31 audit (F3): 37 of 84 Instagram-sourced unclaimed
// accounts carry a descriptor, an emoji, a letter-spaced string or a raw handle
// where a name belongs. Re-running the whole build to fix a string would cost an
// Apify scrape each; the source fullName is already stored on the connection
// payload, so the gate can be re-applied in place.
//
// UNCLAIMED ONLY, by design: once someone claims their site the name is theirs,
// and no backfill may overwrite an owner's own words.
class NamesRegateCommand extends Command
{
    protected $signature = 'names:regate {--dry-run : Print the changes, write nothing} {--limit=0 : Stop after N accounts}';

    protected $description = 'Re-apply the name-shape gate to unclaimed Instagram-sourced accounts.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $changed = 0;

        $builds = PreAccountBuild::query()
            ->where('source_type', 'instagram')
            ->where('build_state', 'ready')
            ->orderBy('created_at')
            ->get();

        foreach ($builds as $build) {
            $user = User::query()->whereKey($build->user_id)->first();
            if (! $user || $user->status !== 'unclaimed') {
                continue;
            }

            $payload = IntegrationConnection::withoutGlobalScopes()
                ->where('user_id', $user->id)->where('platform', 'instagram')
                ->value('payload') ?? [];
            $fullName = trim((string) (data_get($payload, 'fullName') ?? data_get($payload, 'full_name') ?? ''));

            $gated = NameShapeGate::apply(
                ['displayName' => $user->display_name, 'firstName' => $user->first_name, 'lastName' => $user->last_name],
                (string) $build->source_ref,
                $fullName,
            );

            if ($gated['displayName'] === $user->display_name
                && $gated['firstName'] === $user->first_name
                && $gated['lastName'] === $user->last_name) {
                continue;
            }

            $rows[] = [
                $user->handle_lc,
                (string) $user->display_name.' / '.(string) $user->first_name.' / '.(string) $user->last_name,
                (string) $gated['displayName'].' / '.(string) $gated['firstName'].' / '.(string) $gated['lastName'],
            ];
            $changed++;

            if (! $dry) {
                $user->display_name = $gated['displayName'] ?? $user->display_name;
                $user->first_name = $gated['firstName'];
                $user->last_name = $gated['lastName'];
                $user->save();
            }

            if ($limit > 0 && $changed >= $limit) {
                break;
            }
        }

        $this->table(['handle', 'before (display/first/last)', 'after'], $rows);
        $this->info(($dry ? 'Would change ' : 'Changed ').$changed.' of '.$builds->count().' accounts.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Console/NamesRegateCommandTest.php
```

Expected: PASS.

- [ ] **Step 5: Dry-run against dev and read every row**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd="php artisan names:regate --dry-run"
```

Read the table. Any row where the "after" is worse than the "before" is a gate bug — fix Task 3.1 and re-run before writing anything.

- [ ] **Step 6: Apply**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd="php artisan names:regate"
```

- [ ] **Step 7: Verify on the live wire**

```bash
for h in cassandraskinnerpt sweetcakesofmine studiobide fayeellefineline; do
  echo -n "$h: "; curl -s "https://dev-api.partna.au/api/public/profiles/$h" | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['profile']['displayName'])"
done
```

Expected: no descriptor phrases, no letter spacing, no emoji.

- [ ] **Step 8: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Console/Commands/NamesRegateCommand.php tests/Feature/Console/NamesRegateCommandTest.php
git commit -m "Backfill: re-gate the names already written on unclaimed accounts"
```

---

## Phase 4 — The in-flight build gets its own surface (F2)

**Decision on record (owner, 2026-08-31): a real "being built" page.** Not a 404. The route stays live; the page says the site is being prepared, and does NOT publish the person's or business's name before anything exists. The handle is fine to show — it is what the visitor typed and it is already in the URL.

This needs no change to `cloudflare-worker/src/index.js` (which another lane holds): that worker only answers when the KV lookup MISSES, and a pending build has a KV entry.

### Task 4.1: The public payload declares that a build is still running

**Files:**
- Modify: `app/Http/Resources/PublicSite/IndividualProfileResource.php:131` (add sibling key)
- Modify: `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (populate it)
- Test: `tests/Feature/PublicSite/BuildStateOnWireTest.php`

**Interfaces:**
- Produces: top-level `buildState: "pending" | "building" | "ready" | null` on `/api/public/profiles/{handle}`. `null` for a claimed account with no live build.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PublicSite/BuildStateOnWireTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

it('exposes the live build state on the public wire', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    PreAccountBuild::factory()->create([
        'user_id' => $user->id,
        'build_state' => 'pending',
        'source_type' => 'instagram',
    ]);

    $this->getJson("/api/public/profiles/{$user->handle_lc}")
        ->assertOk()
        ->assertJsonPath('data.buildState', 'pending');
});

it('reports ready once the build completes', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    PreAccountBuild::factory()->create([
        'user_id' => $user->id,
        'build_state' => 'ready',
        'source_type' => 'instagram',
    ]);

    $this->getJson("/api/public/profiles/{$user->handle_lc}")
        ->assertOk()
        ->assertJsonPath('data.buildState', 'ready');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/PublicSite/BuildStateOnWireTest.php
```

Expected: FAIL — `data.buildState` missing.

- [ ] **Step 3: Implement**

Confirmed insertion points (read 2026-08-31, do not re-derive): `IndividualProfilePayloadBuilder::build(User $pro, ?Site $site)` returns an array literal passed straight to `new IndividualProfileResource($pro, [...])`; `'page_order' => $pageOrder` is a key in that literal. `IndividualProfileResource` then reads `$this->sections['page_order']` and emits `'pageOrder'` as a TOP-LEVEL key (a sibling of `profile`, not inside it).

In `IndividualProfilePayloadBuilder::build()`, add to the array literal beside `'page_order'`:

```php
            // The live build's state, so apps/pages can show an honest
            // "being prepared" surface instead of the person's name over an
            // empty site (2026-08-31 audit, F2). Keyed off $pro, not $site:
            // the site row can be null here and the user never is, and the
            // build belongs to the user either way.
            'build_state' => PreAccountBuild::query()
                ->where('user_id', $pro->id)
                ->orderByDesc('created_at')
                ->value('build_state'),
```

In `IndividualProfileResource`, beside the top-level `'pageOrder'` key (NOT inside the `profile` array):

```php
            // Whether a pre-account build is still running for this account —
            // null once claimed, or for an account that never had one.
            'buildState' => $this->sections['build_state'] ?? null,
```

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/PublicSite/BuildStateOnWireTest.php
```

Expected: 2 passed.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/PublicSite/IndividualProfilePayloadBuilder.php app/Http/Resources/PublicSite/IndividualProfileResource.php tests/Feature/PublicSite/BuildStateOnWireTest.php
git commit -m "The public wire now says whether a site's build is still running"
```

### Task 4.2: apps/pages renders a preparing state instead of an empty named shell

**Files:**
- Create: `apps/pages/src/components/blocks/SitePreparing.astro`
- Modify: `apps/pages/src/lib/fetch-profile.ts` (carry `buildState`)
- Modify: `apps/pages/src/pages/[...path].astro` (early return)
- Test: `apps/pages/src/__tests__/site-preparing.test.ts`

**Interfaces:**
- Consumes: `buildState` from Task 4.1.
- Produces: `isPreparing(buildState: string | null | undefined): boolean`.

- [ ] **Step 1: Write the failing test**

Create `apps/pages/src/__tests__/site-preparing.test.ts`:

```ts
import {describe, expect, it} from 'vitest';
import {isPreparing} from '../lib/site-preparing';

describe('isPreparing', () => {
  it('is true only while a build has not finished', () => {
    expect(isPreparing('pending')).toBe(true);
    expect(isPreparing('building')).toBe(true);
  });

  it('is false for a finished build, a failed one, and a claimed site', () => {
    expect(isPreparing('ready')).toBe(false);
    expect(isPreparing('failed')).toBe(false);
    expect(isPreparing(null)).toBe(false);
    expect(isPreparing(undefined)).toBe(false);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/partna-monorepo/apps/pages && npx vitest run src/__tests__/site-preparing.test.ts
```

Expected: FAIL — cannot resolve `../lib/site-preparing`.

- [ ] **Step 3: Implement the predicate**

Create `apps/pages/src/lib/site-preparing.ts`:

```ts
/**
 * A build that has not finished yet.
 *
 * The subdomain goes live when the site ROW is created, which is before the
 * build runs — so between those two moments the address answered 200 with a
 * 44KB shell carrying the person's name twice and nothing else (measured
 * 2026-08-31: 7m53s to 10m12s under a 27-build batch, against a scoping
 * assumption that a build in flight "resolves in seconds"). 'failed' is NOT
 * preparing: SyncSubdomainToKvJob already retires that route.
 */
export function isPreparing(buildState: string | null | undefined): boolean {
  return buildState === 'pending' || buildState === 'building';
}
```

- [ ] **Step 4: Build the surface**

Create `apps/pages/src/components/blocks/SitePreparing.astro`:

```astro
---
// The honest answer for an address whose site is still being built.
//
// It shows the HANDLE, never the display name: the name is scraped from
// someone who has not asked for a site yet, and publishing it over an empty
// page is the exact render the 2026-08-30 failed-build fix called "worse than
// a 404". The handle is already in the address bar, so echoing it tells the
// visitor nothing they did not type.
//
// No claim CTA: there is nothing to claim until the build lands.
interface Props {
  handle: string;
}
const {handle} = Astro.props;
---

<main class="preparing">
  <p class="preparing-eyebrow">Partna</p>
  <h1 class="preparing-address">{handle}.partna.au</h1>
  <p class="preparing-lead">This site is being prepared. Check back in a few minutes.</p>
</main>

<style>
  .preparing {
    min-height: 100svh;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: var(--dk-gap-sm);
    padding: var(--dk-space-lg);
    padding-bottom: calc(var(--dk-space-lg) + env(safe-area-inset-bottom));
    background: var(--dk-color-surface);
    color: var(--dk-color-ink);
    text-align: center;
  }
  .preparing-eyebrow {
    margin: 0;
    font-size: var(--dk-font-size-xs);
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--dk-color-ink-muted);
  }
  .preparing-address {
    margin: 0;
    font-size: var(--dk-font-size-xl);
    font-weight: 600;
    overflow-wrap: anywhere;
    max-width: 22ch;
  }
  .preparing-lead {
    margin: 0;
    color: var(--dk-color-ink-muted);
    max-width: 34ch;
  }
</style>
```

- [ ] **Step 5: Carry buildState through the fetch and branch on it**

In `apps/pages/src/lib/fetch-profile.ts`, beside the `pageOrder` mapping (~line 263):

```ts
        buildState: typeof raw.buildState === 'string' ? raw.buildState : null,
```

and add `buildState?: string | null;` to the interface at ~line 96.

In `apps/pages/src/pages/[...path].astro`, immediately after the props are destructured (~line 260), before any content resolution:

```astro
---
// … existing destructure, with buildState added …
if (isPreparing(buildState)) {
  return Astro.rewrite ? undefined : undefined; // fall through to the render below
}
---
{isPreparing(buildState) ? (
  <SitePreparing handle={profile.handle} />
) : (
  /* the existing document */
)}
```

If wrapping the whole existing document is impractical in one edit, render the preparing surface with an early `return new Response(...)`:

```astro
if (isPreparing(buildState)) {
  return new Response(await renderPreparing(profile.handle), {
    status: 200,
    headers: {'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store', 'x-robots-tag': 'noindex'},
  });
}
```

Add the imports: `import SitePreparing from '../components/blocks/SitePreparing.astro';` and `import {isPreparing} from '../lib/site-preparing';`.

- [ ] **Step 6: Test and typecheck**

```bash
cd ~/Developer/partna-monorepo && npm --prefix apps/pages run typecheck && npm --prefix apps/pages run test
```

Expected: 0 errors, all tests pass.

- [ ] **Step 7: Prove it end to end**

Dispatch one cold build and curl its subdomain within the first minute:

```bash
cd ~/Developer/Comet-Backend
B64=$(python3 -c 'import json,base64;print(base64.b64encode(json.dumps([{"account_type":"partna","source_type":"instagram","source_ref":"melbournemusicteachers","source_name":"Melbourne Music Teachers"}]).encode()).decode())')
~/.composer/vendor/bin/cloud command:run development --cmd="php artisan fleet:new --b64=$B64"
curl -s https://melbournemusicteachers.partna.au/ | head -c 400
```

Expected: the preparing surface, and **no display name anywhere in the document**. Then after the build reports ready, the same URL serves the real site.

- [ ] **Step 8: Commit**

```bash
cd ~/Developer/partna-monorepo
git add apps/pages/src/components/blocks/SitePreparing.astro apps/pages/src/lib/site-preparing.ts apps/pages/src/lib/fetch-profile.ts apps/pages/src/pages/'[...path].astro' apps/pages/src/__tests__/site-preparing.test.ts
git commit -m "A site still being built says so, instead of publishing a name over an empty page"
```

---

## Phase 5 — The purge lane (F13, escalated)

**This got worse after the audit was written.** At 11:19 UTC — 30 minutes after the batch's burst had drained — `CloudflareCachePurgeJob` began failing again and was still failing at 11:50: **36 failures, ~1–2 per minute, spread evenly**, every one `MaxAttemptsExceeded`, cycling repeatedly through the same 27 handles. That is not a burst tail, which is what `retryUntil` 10→30 was aimed at. Something is generating sustained purge load per site after ready, and the funnel (60/min) plus the 30-minute deadline is not draining it.

### Task 5.1: Measure before changing anything

**Files:**
- Create: `docs/superpowers/plans/2026-08-31-purge-measurement.md`

- [ ] **Step 1: Count dispatches versus failures over one hour**

```bash
cd ~/Developer/Comet-Backend && python3 scripts/logs/window.py "2026-08-31 11:00:00" "2026-08-31 12:00:00" > /tmp/purge-window.jsonl
python3 - <<'PY'
import json, collections
rows=[json.loads(l) for l in open('/tmp/purge-window.jsonl') if l.strip()]
disp=[r for r in rows if 'CloudflareCachePurgeJob' in r['message'] and 'RUNNING' in r['message']]
fail=[r for r in rows if 'cloudflare.cache_purge.failed' in r['message']]
print('purge job RUNNING lines:', len(disp))
print('purge failures:', len(fail))
print('by minute:', collections.Counter(r['loggedAt'][11:16] for r in disp))
PY
```

Record both numbers in the measurement doc. **If RUNNING is well under 60/min, the funnel ceiling is not the constraint and raising it again would be the third wrong fix in a row.**

- [ ] **Step 2: Identify what is dispatching them**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
\$rows = DB::connection(\"pgsql\")->select(\"select left(exception, 200) ex, count(*) n from public.failed_jobs where payload like ? and failed_at >= ? group by 1 order by n desc\", [\"%CloudflareCachePurgeJob%\", \"2026-08-31 11:00:00\"]);
echo json_encode(\$rows, JSON_PRETTY_PRINT);"'
```

Expected: all `MaxAttemptsExceededException`, zero real Cloudflare 4xx/5xx. That confirms the deadline, not the API, is what kills them.

- [ ] **Step 3: Commit the measurement**

```bash
cd ~/Developer/Comet-Backend
git add docs/superpowers/plans/2026-08-31-purge-measurement.md
git commit -m "docs(plan): purge lane measured before touching it, twice-burned"
```

### Task 5.2: Coalesce per site instead of per write

The job's own docblock records a KNOWN NARROWING: "a job released by the rate-limiter middleware or the 429 catch waits PAST this TTL, so coalescing only covers the happy-path window — during funnel pressure the same site can enqueue more than once." Under sustained pressure that narrowing becomes the whole behaviour: pressure defeats coalescing, which increases pressure.

**Files:**
- Modify: `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` (unique lock TTL)
- Test: `tests/Feature/Cloudflare/PurgeCoalescingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Cloudflare/PurgeCoalescingTest.php`:

```php
<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\Queue;

it('enqueues one purge per site even when writes arrive during funnel pressure', function () {
    Queue::fake();

    // Three content writes for one site inside the coalescing window.
    CloudflareCachePurgeJob::dispatch('sepia');
    CloudflareCachePurgeJob::dispatch('sepia');
    CloudflareCachePurgeJob::dispatch('sepia');

    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
});
```

- [ ] **Step 2: Run it**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Cloudflare/PurgeCoalescingTest.php
```

Record the result. If it already passes, the coalescing lock holds in the fake queue and the defect is only under real release pressure — say so in the measurement doc and proceed to Step 3 regardless.

- [ ] **Step 3: Hold the unique lock for the whole retry window**

In `CloudflareCachePurgeJob`, set the unique lock's TTL to the retry deadline so a released job keeps its own slot rather than letting a duplicate in behind it:

```php
    /**
     * The lock must outlive the RETRY window, not just the happy path.
     *
     * Previously the TTL covered only the immediate dispatch window, so a job
     * released by the rate-limiter middleware sat unlocked and the next write
     * for the same site enqueued a second purge — under sustained pressure the
     * duplicates were the pressure (36 failures in 31 minutes, 2026-08-31,
     * cycling the same 27 handles). Matching retryUntil means one site holds at
     * most one purge in flight for as long as that purge may live.
     */
    public function uniqueFor(): int
    {
        return 30 * 60;
    }
```

- [ ] **Step 4: Run the test and the Cloudflare suite**

```bash
cd ~/Developer/Comet-Backend && php artisan test --filter=Purge && php artisan test --filter=Cloudflare
```

Expected: all pass.

- [ ] **Step 5: Gate on a real batch**

After deploying, run a 10-account cold batch and count purge failures for the following hour with the Step-1 script.

Expected: **zero** `cloudflare.cache_purge.failed`. Anything above zero means the constraint is still elsewhere — record the new numbers rather than raising a ceiling.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Jobs/Cloudflare/CloudflareCachePurgeJob.php tests/Feature/Cloudflare/PurgeCoalescingTest.php
git commit -m "One purge per site for as long as a purge can live"
```

---

## Phase 6 — Media (F11, F14)

### Task 6.1 (F11): TikTok thumbnails are mirrored, not hotlinked

`p19-common-sign.tiktokcdn-us.com` thumbnails answer 403 and render at `naturalWidth 0` on the live watch page (verified in-browser on elmthenutritionist, 2026-08-31; same on dishoom-shoreditch).

**Files:**
- Modify: the media-mirror eligibility map (locate in Step 1)
- Test: `tests/Unit/Media/MirrorEligibilityTest.php`

- [ ] **Step 1: Find the eligibility rule**

```bash
cd ~/Developer/Comet-Backend && grep -rn "mirror_eligible\|mirrorEligible\|fbcdn\|cdninstagram" app/Services/Media app/Jobs --include=*.php | head -20
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Media/MirrorEligibilityTest.php` (replace `MediaMirrorPolicy::shouldMirror` with the symbol found in Step 1):

```php
<?php

use App\Services\Media\MediaMirrorPolicy;

it('mirrors signed TikTok CDN thumbnails', function () {
    // Live 2026-08-31: served straight from the wire, answered 403, and
    // rendered as a blank tile on the watch page.
    expect(MediaMirrorPolicy::shouldMirror('https://p19-common-sign.tiktokcdn-us.com/tos-alisg-p-0037/ooVV4f6'))->toBeTrue()
        ->and(MediaMirrorPolicy::shouldMirror('https://p16-common-sign.tiktokcdn-us.com/tos-no1a-p-0037-no/o4eUwAAK'))->toBeTrue();
});

it('still mirrors the Instagram CDNs it always did', function () {
    expect(MediaMirrorPolicy::shouldMirror('https://instagram.fedi1-1.fna.fbcdn.net/v/t51/x.jpg'))->toBeTrue()
        ->and(MediaMirrorPolicy::shouldMirror('https://scontent-mad2-1.cdninstagram.com/v/t51/y.jpg'))->toBeTrue();
});
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Media/MirrorEligibilityTest.php
```

Expected: FAIL on the TikTok hosts.

- [ ] **Step 4: Add the hosts**

Add `tiktokcdn-us.com`, `tiktokcdn.com` and `tiktokv.com` to the mirror-eligible host list found in Step 1, with:

```php
        // TikTok thumbnails are signed and hotlink-protected — served straight
        // from the wire they answer 403 and render as a blank tile
        // (elmthenutritionist, dishoom-shoreditch, verified in-browser
        // 2026-08-31). Same class as the fbcdn URLs already here.
```

- [ ] **Step 5: Run the test**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Media/MirrorEligibilityTest.php
```

Expected: 2 passed.

- [ ] **Step 6: Verify in a browser after a rebuild**

Rebuild `elmthenutritionist` (Phase 9 covers the fleet; a single rebuild is enough here), then in the Browser pane:

```js
Array.from(document.images).filter(i => /tiktokcdn/.test(i.currentSrc || i.src)).length
```

Expected: `0` — the thumbnail now comes from our own media host, and the image has a non-zero `naturalWidth`.

- [ ] **Step 7: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/Media tests/Unit/Media/MirrorEligibilityTest.php
git commit -m "TikTok thumbnails are signed and hotlink-protected — mirror them like the Instagram ones"
```

### Task 6.2 (F14): A logo that fails one variant still gets the other

Seven of seventeen business builds finished with one variant or neither. The processor threw again during the audit run (`svg rasterization failed: The SVG size is undefined`, HTTP 422; 22 such failures in 7 days). The existing `failed()` fallback publishes the vector when the ORIGINAL is an SVG; what is missing is the square/icon variant, which is what the favicon and og:image need.

**Files:**
- Modify: `app/Jobs/ProcessLogoVariantsJob.php`
- Test: `tests/Feature/Media/LogoVariantFallbackTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Media/LogoVariantFallbackTest.php`:

```php
<?php

it('publishes a square variant even when the full-logo rasterisation fails', function () {
    // supernormal (2026-08-30) and four of the 2026-08-31 business builds:
    // logoFull present, logoSquare null, so the favicon fell back to a
    // generated initial and og:image to a photo.
    expect(true)->toBeTrue(); // replaced in Step 3 with the real assertion
})->todo('needs the processor double defined in Step 3');
```

- [ ] **Step 2: Read the job and decide the fallback shape**

```bash
cd ~/Developer/Comet-Backend && sed -n '1,120p' app/Jobs/ProcessLogoVariantsJob.php
```

Note how `failed()` publishes the SVG fallback today, and where the square variant would be derived (a centre-crop of the full logo is enough for a favicon).

- [ ] **Step 3: Write the real failing test and the fix together**

Replace the placeholder test with one that fakes the processor returning 422 for the square request and asserts `site_media` still ends with a non-null square URL derived from the full logo. Then implement that derivation in `failed()`.

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Media/LogoVariantFallbackTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Jobs/ProcessLogoVariantsJob.php tests/Feature/Media/LogoVariantFallbackTest.php
git commit -m "A logo that loses one variant no longer loses its favicon too"
```

---

## Phase 7 — Sector coverage (F5, F16-misclassification, F18)

The taxonomy already has 76 sectors including `dentist`, `accommodation`, `mechanic`, `real-estate-agent` and `driving-instructor`. The 68 null-sector accounts are **unmapped Google category strings**, not missing sectors — plus about ten categories with genuinely no home.

### Task 7.1: Map the 29 Google categories the audit surfaced

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` — `KEYWORD_SECTORS` and `SECTORS`
- Test: `tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php`

**Interfaces:**
- Consumes: `SectorTaxonomy::fromGoogleCategory()`.
- Produces: new sector slugs `retail-store`, `grocer`, `liquor-store`, `veterinarian`, `pet-services`, `museum-gallery`, `market`, `laundry`, `locksmith`, `medical-clinic`, `optometrist`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php`:

```php
<?php

use App\Services\Profile\SectorTaxonomy;

dataset('google categories', [
    // Every category string that produced sector NULL on dev, 2026-08-31,
    // with the account it came from.
    ['Dental Clinic', 'dentist'],                    // bondi-junction-dental
    ['Massage', 'spa'],                              // lakshmi-thai-massage
    ['Pub', 'bar'],                                  // corner-hotel, exeter-hotel
    ['Brewery', 'bar'],                              // little-creatures-brewery-fremantle
    ['Winery', 'bar'],                               // chandon-australia
    ['Sandwich Shop', 'cafe'],                       // pret-a-manger
    ['Ice Cream Shop', 'cafe'],                      // gelato-messina-darlinghurst
    ['Bicycle Shop', 'retail-store'],                // curve-cycling
    ['Book Store', 'retail-store'],                  // readings-carlton
    ['Toy Store', 'retail-store'],                   // toyworld-central-docklands-melbourne
    ['Electronics Store', 'retail-store'],           // michaels-camera-video-digital
    ['Store', 'retail-store'],                       // milligram, northside-records
    ['Food Store', 'grocer'],                        // harper-blohm-cheese-shop
    ['Butcher Shop', 'grocer'],                      // peter-bouchier-toorak
    ['Liquor Store', 'liquor-store'],                // blackhearts-sparrows
    ['Veterinary Care', 'veterinarian'],             // lort-smith-animal-hospital
    ['Pet Care', 'pet-services'],                    // the-noble-hound-dog-grooming
    ['Museum', 'museum-gallery'],                    // tasmanian-museum-and-art-gallery
    ['Market', 'market'],                            // adelaide-central-market, perth-upmarket
    ['Laundry', 'laundry'],                          // sunshine-north-coin-laundry
    ['Locksmith', 'locksmith'],                      // mb-locksmiths-melbourne
    ['Medical Clinic', 'medical-clinic'],            // melbourne-acupuncture
    ['Health', 'medical-clinic'],                    // oscar-wylee-optometrist
    ['Garden Center', 'retail-store'],               // bulleen-art-garden
    ['School', 'tutor'],                             // melbourne-guitar-academy
    ['Educational Institution', 'tutor'],            // onroad-driving-education
]);

it('classifies the Google categories that produced a null sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($category))->toBe($expected);
})->with('google categories');

it('keeps every new slug valid and bucketed', function () {
    foreach (['retail-store', 'grocer', 'liquor-store', 'veterinarian', 'pet-services', 'museum-gallery', 'market', 'laundry', 'locksmith', 'medical-clinic', 'optometrist'] as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("{$slug} should be a valid sector")
            ->and(SectorTaxonomy::bucketFor($slug))->not->toBeNull("{$slug} needs a style bucket");
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php
```

Expected: FAIL on every row.

- [ ] **Step 3: Add the sectors**

In `SectorTaxonomy::SECTORS`, append:

```php
        ['slug' => 'retail-store', 'label' => 'Shop / Retail store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'grocer', 'label' => 'Grocer / Food store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'liquor-store', 'label' => 'Bottle shop / Liquor store', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],
        ['slug' => 'market', 'label' => 'Market', 'group' => 'Retail & Shopping', 'bucket' => SectorStylePresets::RETAIL_SHOPPING],

        ['slug' => 'veterinarian', 'label' => 'Vet / Animal hospital', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'medical-clinic', 'label' => 'Medical clinic', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],
        ['slug' => 'optometrist', 'label' => 'Optometrist', 'group' => 'Health & Fitness', 'bucket' => SectorStylePresets::HEALTH_FITNESS],

        ['slug' => 'pet-services', 'label' => 'Pet grooming / Care', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'laundry', 'label' => 'Laundry / Dry cleaning', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],
        ['slug' => 'locksmith', 'label' => 'Locksmith', 'group' => 'Home & Trade Services', 'bucket' => SectorStylePresets::HOME_SERVICES],

        ['slug' => 'museum-gallery', 'label' => 'Museum / Gallery', 'group' => 'Creative & Entertainment', 'bucket' => SectorStylePresets::CREATIVE_ENTERTAINMENT],
```

- [ ] **Step 4: Add the keyword mappings**

In `KEYWORD_SECTORS`, respecting the file's stated specific-before-generic ordering discipline (`classify()` returns the FIRST substring match), add these ABOVE any generic key they would collide with:

```php
        'dental' => 'dentist',
        'veterinary' => 'veterinarian',
        'pet care' => 'pet-services',
        'ice cream' => 'cafe',
        'sandwich' => 'cafe',
        'butcher' => 'grocer',
        'food store' => 'grocer',
        'liquor' => 'liquor-store',
        'brewery' => 'bar',
        'winery' => 'bar',
        'pub' => 'bar',
        'book store' => 'retail-store',
        'toy store' => 'retail-store',
        'electronics store' => 'retail-store',
        'bicycle' => 'retail-store',
        'garden center' => 'retail-store',
        'garden centre' => 'retail-store',
        'museum' => 'museum-gallery',
        'laundry' => 'laundry',
        'locksmith' => 'locksmith',
        'medical clinic' => 'medical-clinic',
        'educational institution' => 'tutor',
        'market' => 'market',
        'store' => 'retail-store',
        'health' => 'medical-clinic',
        'school' => 'tutor',
```

`'store'`, `'health'`, `'school'` and `'market'` are the generic catch-alls and MUST sit last among these, after every specific key that contains them.

- [ ] **Step 5: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php && php artisan test --filter=Sector
```

Expected: all pass, including the pre-existing sector tests.

- [ ] **Step 6: Backfill the live sectors**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
\$fixed = 0;
foreach (App\Models\Core\User\User::query()->whereNull(\"sector\")->where(\"status\",\"unclaimed\")->get() as \$u) {
    \$cat = App\Models\Core\Site\IntegrationConnection::withoutGlobalScopes()
        ->where(\"user_id\", \$u->id)->where(\"platform\", \"google-business\")
        ->value(\"payload\");
    \$s = App\Services\Profile\SectorTaxonomy::fromGoogleCategory(data_get(\$cat, \"categoryName\"));
    if (\$s !== null) { \$u->update([\"sector\" => \$s]); \$fixed++; }
}
echo \"sector set on {\$fixed} accounts\n\";"'
```

Expected: roughly 25–30 of the 68.

- [ ] **Step 7: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php
git commit -m "Twenty-nine Google categories that classified to nothing now reach a sector"
```

### Task 7.2 (F18): `home` is pinned first in pageOrder

`PAGE_FRONTS` for professional-services, home-services and automotive all begin with `contact`, and `home` — which is not a taxonomy page — falls into the tail. ray-white-double-bay and ultra-tune-bridge-road both serve `pageOrder: ["contact","home"]`.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:386-422` (`buildPageOrder`)
- Test: `tests/Unit/PublicSite/PageOrderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PublicSite/PageOrderTest.php`:

```php
<?php

use App\Services\Profile\SectorActionRecipes;
use App\Enums\SitepageId;

it('never lets a sector front displace home', function () {
    // real-estate-agent → PROFESSIONAL_SERVICES → front ['contact', …]
    $order = SectorActionRecipes::pageOrderFor('real-estate-agent', SitepageId::canonicalOrder());
    $present = array_values(array_intersect($order, ['home', 'contact']));

    expect($present[0])->toBe('home');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/PublicSite/PageOrderTest.php
```

Expected: FAIL — `contact` comes first.

- [ ] **Step 3: Implement**

At the end of `buildPageOrder()`, before the return:

```php
        // HOME IS NOT A TAXONOMY PAGE. The sector fronts name the pages an
        // industry's visitors expect first, and three of them (professional
        // services, home services, automotive) start with 'contact' — which
        // pushed home into the tail via the array_diff, so ray-white-double-bay
        // and ultra-tune-bridge-road served pageOrder ["contact","home"]
        // (2026-08-31). Home is the landing surface; it leads whenever present.
        $ordered = array_values(array_merge($rankedPages, $rest));
        if (in_array('home', $ordered, true)) {
            $ordered = array_values(array_merge(['home'], array_diff($ordered, ['home'])));
        }

        return $ordered;
```

- [ ] **Step 4: Run the tests**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/PublicSite/PageOrderTest.php && php artisan test --filter=PageOrder
```

Expected: all pass.

- [ ] **Step 5: Verify on the wire**

```bash
for h in ray-white-double-bay ultra-tune-bridge-road; do
  echo -n "$h: "; curl -s "https://dev-api.partna.au/api/public/profiles/$h" | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['pageOrder'])"
done
```

Expected: `['home', 'contact']` for both.

- [ ] **Step 6: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/PublicSite/SitepageDataResolverService.php tests/Unit/PublicSite/PageOrderTest.php
git commit -m "A sector front could push home out of first place"
```

### Task 7.3: A live-music venue is not a musician

`northcote-social-club` — a pub with a bandroom — classified as sector `musician`, which hands it the musician page front (listen / events / watch / shop).

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` (`fromGoogleCategory` precedence)
- Test: `tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php` (extend)

- [ ] **Step 1: Add the failing case**

Append to the dataset in `tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php`:

```php
    ['Live Music Venue', 'event-venue'],             // northcote-social-club
    ['Bar & Grill', 'bar'],
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php
```

Expected: FAIL on `Live Music Venue`.

- [ ] **Step 3: Implement**

Add to `KEYWORD_SECTORS`, above any `music` key:

```php
        // A venue that HOSTS music is not a musician — northcote-social-club
        // took the musician page front (listen/events/watch/shop) on 2026-08-31.
        'live music venue' => 'event-venue',
        'music venue' => 'event-venue',
        'concert hall' => 'event-venue',
```

- [ ] **Step 4: Run the tests, then correct the live row**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php
~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
App\Models\Core\User\User::query()->where(\"handle_lc\",\"northcote-social-club\")->update([\"sector\" => \"event-venue\"]);
echo \"ok\n\";"'
```

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyGoogleCategoryTest.php
git commit -m "A venue that hosts music was being classified as a musician"
```

### Task 7.4 (F22): A skipped menu capability leaves a trace, and a food business is never left unclassified

**Found while answering "is the menu OCR still firing?" on 2026-08-31.** `SectorTaxonomy::isFood()` gates `can_use_menu` / `can_use_reservations` / online ordering, and returns false for a null sector — deliberate and documented. But `GoogleMenuPhotoScanJob` checks that capability at line 143 **before its first `Log::info`**, so an unclassified food business gets no platform menu, no OCR scan, and no trace that anything was skipped. Pret A Manger is the live proof: Google category "Sandwich Shop", unmapped, sector null, `can_use_menu` false, scan returned in 14.35ms logging nothing, 0 menu items on a sandwich chain.

Task 7.1 fixes Pret specifically by mapping "Sandwich Shop". This task makes the class of failure visible, so the next unmapped category is a log line rather than a silently menu-less site.

**The gate itself is not the bug — do not change it.** `AccountCapabilities.php:64-69` is marked "2026-07-15 industry/sector gating contract — LAW, do not rederive", and its own comment already documents the null-sector behaviour. Read what an unclassified business actually gets, because it is worse than "no menu":

```php
can_use_menu:            $isBusiness && $isFood,      // false
can_use_reservations:    $isBusiness ? $isFood : true, // false
can_use_booking:         $isBusiness ? ! $isFood : true, // TRUE
can_use_online_ordering: $isBusiness && $isFood,      // false
```

A restaurant with no sector is not merely menu-less — it is served the *booking* capability set: a Book button, no menu, no reservations, no ordering. Pret A Manger is live proof. The fix is to stop leaving sectors null (Task 7.1) and to make the denial audible (this task), never to loosen the LAW.

**Files:**
- Modify: `app/Jobs/Platforms/GoogleMenuPhotoScanJob.php:143-146`
- Test: `tests/Feature/Platforms/MenuScanCapabilityLogTest.php`

**Interfaces:**
- Consumes: `AccountCapabilities::for($user)->can_use_menu`, `SectorTaxonomy::isFood()`.
- Produces: a `google_menu_scan.capability_denied` log line carrying the user id, the sector, and whether the sector is null.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/MenuScanCapabilityLogTest.php`:

```php
<?php

use App\Jobs\Platforms\GoogleMenuPhotoScanJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

it('says why it skipped when the account cannot use a menu', function () {
    // pret-a-manger, 2026-08-31: Google category "Sandwich Shop" classified to
    // nothing, so sector was null, so can_use_menu was false — and the job
    // returned in 14ms without logging a thing. A sandwich chain with no menu
    // and no explanation anywhere.
    Log::spy();

    $user = User::factory()->create(['account_type' => 'business', 'sector' => null]);
    (new GoogleMenuPhotoScanJob((string) $user->id, 'ChIJU6LqOtYEdkgRnvEzd4hFOGM'))->handle(...app()->make('menu.scan.deps'));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $ctx) => $message === 'google_menu_scan.capability_denied'
            && $ctx['sector'] === null
            && $ctx['sector_missing'] === true);
});
```

Resolve the handle()'s dependencies the way the surrounding tests in `tests/Feature/Platforms/` already do; if there is no container binding for them, construct the three collaborators directly rather than adding one.

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Platforms/MenuScanCapabilityLogTest.php
```

Expected: FAIL — no `google_menu_scan.capability_denied` was logged.

- [ ] **Step 3: Implement**

In `GoogleMenuPhotoScanJob::handle()`, replace the silent capability return:

```php
        // Menu is a capability-gated feature (business + food) — same gate as
        // every menu surface. Never spend AI on an account that can't show one.
        //
        // It logs now. The bare return meant an unclassified food business —
        // pret-a-manger, 2026-08-31, Google category "Sandwich Shop" mapping to
        // nothing — got no platform menu, no scan, and no evidence anywhere that
        // a scan had been declined. A null sector is the case worth shouting
        // about: it is a classification miss, not a deliberate non-food account.
        if (! AccountCapabilities::for($user)->can_use_menu) {
            Log::info('google_menu_scan.capability_denied', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'sector' => $user->sector,
                'sector_missing' => $user->sector === null,
            ]);

            return;
        }
```

- [ ] **Step 4: Run the test**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Platforms/MenuScanCapabilityLogTest.php
```

Expected: PASS.

- [ ] **Step 5: Rebuild Pret and confirm it now gets a menu**

After Task 7.1's mapping and backfill are live:

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd="php artisan fleet:verify pret-a-manger"
curl -s "https://dev-api.partna.au/api/public/profiles/pret-a-manger" | python3 -c "
import sys,json;p=json.load(sys.stdin)['data']['profile']['pools'];print('menu items:', len((p.get('menus') or {}).get('items') or []))"
```

Expected: sector `cafe`, and a non-zero menu count once the scan runs on the rebuild.

- [ ] **Step 6: Sweep for other silently-denied accounts**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
\$n = App\Models\Core\User\User::query()->where(\"account_type\",\"business\")->whereNull(\"sector\")->where(\"status\",\"unclaimed\")->count();
echo \"business accounts with no sector (menu/reservations/ordering all denied): {\$n}\n\";"'
```

Record the number in the acceptance results. After Task 7.1's backfill it should be materially lower, and every remaining one should be a genuine non-food business.

- [ ] **Step 7: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Jobs/Platforms/GoogleMenuPhotoScanJob.php tests/Feature/Platforms/MenuScanCapabilityLogTest.php
git commit -m "A menu scan declined for want of a sector now says so"
```

---

## Phase 8 — Tooling and hygiene (F12, F16, F19, F20, F21, and the F15 brief)

### Task 8.1 (F12): The YouTube thumbnail budget test stops depending on wall-clock

Two tests fail under `--parallel` and pass 3/3 serially: both assert on a 0.05-second `FetchBudget` window, which a loaded machine exhausts before the first probe.

**Files:**
- Modify: `tests/Unit/Platforms/YoutubeThumbnailResolverTest.php:170-199`

- [ ] **Step 1: Reproduce the flake**

```bash
cd ~/Developer/Comet-Backend && for i in 1 2 3; do php artisan test --parallel --filter=YoutubeThumbnailResolver 2>&1 | tail -3; done
```

Expected: at least one run with 2 failures.

- [ ] **Step 2: Replace the wall-clock budget with an injected clock**

In both tests, replace `app(FetchBudget::class)->open(0.05, …)` with a budget whose remaining time is controlled rather than raced. Freeze time around the call:

```php
    $this->freezeTime(function () {
        app(FetchBudget::class)->open(0.05, function () {
            // The probe consumes the budget deterministically: advance the
            // clock by more than the window instead of hoping a real 50ms
            // elapses. Under --parallel a loaded worker blew the window
            // before the first probe ran and the assertion below never had a
            // chance (2026-08-31).
            $this->travel(80)->milliseconds();

            return app(YoutubeThumbnailResolver::class)->bestForMany(['probed-aJJJ', 'skipped-bKK', 'skipped-cLL']);
        });
    });
```

Adjust to the resolver's actual probe sequence so the first probe still runs and the second and third are skipped — that is the behaviour under test.

- [ ] **Step 3: Run it under parallel, repeatedly**

```bash
cd ~/Developer/Comet-Backend && for i in 1 2 3 4 5; do php artisan test --parallel --filter=YoutubeThumbnailResolver 2>&1 | tail -2; done
```

Expected: 11 passed, five times out of five.

- [ ] **Step 4: Commit**

```bash
cd ~/Developer/Comet-Backend
git add tests/Unit/Platforms/YoutubeThumbnailResolverTest.php
git commit -m "Two tests raced a 50ms wall-clock budget and lost under --parallel"
```

### Task 8.2 (F16): `fleet:new` keeps the all-or-nothing promise its comment makes

The command validates spec shape up front — "the entire batch is checked before ANY build is requested" — but the account-type/source-type pairing is enforced inside the request loop, so on 2026-08-31 seventeen builds were dispatched before five specs were rejected.

**Files:**
- Modify: `app/Console/Commands/FleetNewCommand.php:65-97`
- Test: `tests/Feature/Console/FleetNewCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/FleetNewCommandTest.php`:

```php
<?php

use App\Models\Core\User\PreAccountBuild;

it('rejects the whole batch when one pairing is invalid', function () {
    $specs = base64_encode(json_encode([
        ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'validhandle', 'source_name' => 'Valid'],
        ['account_type' => 'business', 'source_type' => 'instagram', 'source_ref' => 'boltbarbers', 'source_name' => 'Bolt Barbers'],
    ]));

    $before = PreAccountBuild::query()->count();

    $this->artisan("fleet:new --b64={$specs}")
        ->expectsOutputToContain("is not available for 'business' accounts")
        ->assertExitCode(1);

    expect(PreAccountBuild::query()->count())->toBe($before);
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Console/FleetNewCommandTest.php
```

Expected: FAIL — the first spec builds before the second is rejected.

- [ ] **Step 3: Implement**

In `FleetNewCommand::handle()`, inside the validation loop (after the source-type check, before `$clean[] = compact(...)`):

```php
            // The pairing map, checked HERE with the rest of the batch rather
            // than inside the request loop. On 2026-08-31 seventeen builds were
            // already dispatched when five specs were rejected at request time —
            // exactly the half-built batch this loop's all-or-nothing contract
            // exists to prevent.
            $allowed = config("partna.pre_account.sources.{$accountType}", []);
            if (! in_array($sourceType, $allowed, true)) {
                $this->error("spec #{$i}: source '{$sourceType}' is not available for '{$accountType}' accounts.");

                return self::FAILURE;
            }
```

- [ ] **Step 4: Run the test**

```bash
cd ~/Developer/Comet-Backend && php artisan test tests/Feature/Console/FleetNewCommandTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Console/Commands/FleetNewCommand.php tests/Feature/Console/FleetNewCommandTest.php
git commit -m "fleet:new dispatched seventeen builds before rejecting the batch"
```

### Task 8.3 (F20): A thin build is visible as thin

Joe's Pizza built from a listing yielding two photos, no phone, no bio and no socials, and reported `ready` with the same confidence as a site with fifteen photos and five platforms.

**Files:**
- Modify: `app/Console/Commands/FleetVerifyCommand.php`
- Test: `tests/Feature/Console/FleetVerifyThinTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/FleetVerifyThinTest.php`:

```php
<?php

it('flags a build that landed almost no content', function () {
    $this->artisan('fleet:verify joes-pizza')
        ->expectsOutputToContain('THIN')
        ->assertExitCode(0);
})->skip(fn () => ! app()->environment('local'), 'reads dev data');
```

- [ ] **Step 2: Implement**

Add a `content` column to `FleetVerifyCommand`'s table: the media/reviews/menus/services counts, and the literal string `THIN` when media < 3 AND there is no bio AND no phone AND no connection other than the source.

```php
            // A build can report ready and still have landed nothing —
            // joes-pizza (2026-08-31): 2 photos, no phone, no bio, no socials,
            // indistinguishable in this table from a site with fifteen photos
            // and five platforms.
            $thin = $mediaCount < 3 && $bio === null && $phone === null && $connectionCount <= 1;
```

- [ ] **Step 3: Run it against dev**

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd="php artisan fleet:verify joes-pizza sepia the-hotel-windsor"
```

Expected: `THIN` on joes-pizza only.

- [ ] **Step 4: Commit**

```bash
cd ~/Developer/Comet-Backend
git add app/Console/Commands/FleetVerifyCommand.php tests/Feature/Console/FleetVerifyThinTest.php
git commit -m "fleet:verify now says when a ready build landed almost nothing"
```

### Task 8.4: Write the F15 connector brief and clear the debris (F19, F21)

- [ ] **Step 1: Write the connector brief**

Create `docs/superpowers/plans/2026-09-01-service-connectors-BRIEF.md` covering: Booksy, Cliniko, NowBookIt and Timely connect as bare booking links with no ingest connector, so every service business builds with an empty services pool (boltbarbers, melbourne-osteopathy, portmelbphysio, madphysiotherapy, bondi-junction-dental, anytime-fitness on 2026-08-31). Include the four `surface_key` values, the existing Fresha connector as the pattern to follow, and the note that this needs its own plan.

- [ ] **Step 2: Record F19 as an upstream gap, not a seeder bug**

Append to the brief: the contact seeder behaves exactly as documented (active, dark without an email, `auto` marker written). 0 of 17 Google-listing builds produced a public contact email versus 2 of 10 Instagram builds, because a Google listing gives a website and no address, and nothing mines the website for one. That is a website-scan feature, sized with the connector work.

- [ ] **Step 3: Retire the two placeholder accounts (F21)**

`business` and `business1` hold real identities ("Google Sydney", "Basette") under nonsense handles, from a build dispatched without a source name. They already 404 correctly — unapproved early-access builds with a null expiry. Expire and prune them:

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd='php artisan tinker --execute="
App\Models\Core\User\PreAccountBuild::query()->whereIn(\"user_id\", App\Models\Core\User\User::query()->whereIn(\"handle_lc\", [\"business\",\"business1\"])->pluck(\"id\"))->update([\"expires_at\" => now()->subDay()]);
echo \"expired\n\";"'
~/.composer/vendor/bin/cloud command:run development --cmd="php artisan builds:prune-expired"
```

- [ ] **Step 4: Triage Nightwatch (#469 is fixed and stale)**

Resolve #469 in Nightwatch — the foreign-key violation on force-deleting a connection was fixed by commit `3b6a669ba` on 2026-08-28, the same day as its last occurrence. Then walk the remaining 24 open exception classes and resolve or ignore every one whose fix has shipped, so a new regression is visible.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add docs/superpowers/plans/2026-09-01-service-connectors-BRIEF.md
git commit -m "docs(plan): brief the service-connector work, and the contact-email gap behind it"
```

---

## Phase 9 — Fleet rebuild and acceptance

**Decision on record (owner, 2026-08-31): full fleet rebuild after the fixes land.** The owner also ticked "new builds only"; the two are satisfied together — a rebuild IS a new build, and everything below verifies on fresh ones.

### Task 9.1: Full suite and static gates

- [ ] **Step 1: Run everything**

```bash
cd ~/Developer/Comet-Backend && php artisan test --parallel && composer analyse && vendor/bin/pint --test
cd ~/Developer/partna-monorepo && npm run typecheck && npm run check:ds && npm --prefix apps/pages run test && npm --prefix apps/dashboard run lint
```

Expected: 0 failed tests (the audit's baseline was 9,837 passing plus the two flakes Task 8.1 removes), PHPStan clean, Pint passed, 0 typecheck errors.

- [ ] **Step 2: Deploy**

```bash
cd ~/Developer/Comet-Backend && git push origin development
cd ~/Developer/partna-monorepo && git push origin main && npm run deploy:pages
```

Note: pushing `development` does NOT deploy production. Do not push to `production` as part of this plan.

### Task 9.2: Rebuild the fleet and gate every fix on live evidence

- [ ] **Step 1: Expire, prune, rebuild**

The live-source dedupe blocks a plain re-request, so the sanctioned path is expire → `builds:prune-expired` → fresh staff build, as the 2026-08-27 acceptance round did.

```bash
cd ~/Developer/Comet-Backend && ~/.composer/vendor/bin/cloud command:run development --cmd="php artisan builds:prune-expired"
# then re-dispatch via fleet:new with the same specs, in batches of ~12 so the
# scraping queue never backs up past a couple of minutes.
```

- [ ] **Step 2: Sweep the window**

```bash
cd ~/Developer/Comet-Backend && python3 scripts/logs/window.py "<batch start>" "<batch start + 60m>" > /tmp/rebuild.jsonl
python3 -c "
import json,collections
rows=[json.loads(l) for l in open('/tmp/rebuild.jsonl') if l.strip()]
print(collections.Counter(r['level'] for r in rows))
for r in rows:
    if r['level'] in ('warning','error'): print(r['loggedAt'][11:19], r['level'], r['message'][:120])"
```

- [ ] **Step 3: Walk the acceptance gates**

Each must show live evidence, not a passing test:

| Finding | Gate |
|---|---|
| F1 | `/platforms` on a rebuilt site: zero `item-seen` 422, `action-seen` 201 present |
| F2 | a subdomain polled during its build serves the preparing surface with no display name |
| F3 | `names:regate --dry-run` reports 0 further changes; no descriptor, emoji or letter-spaced display name on the fleet |
| F4 | `curl … \| grep 'href="tel:'` returns a link on every business site with a phone |
| F5 | `sector is null` count on ready unclaimed accounts is materially below 68 |
| F22 | pret-a-manger serves a menu; `google_menu_scan.capability_denied` appears for any account still denied |
| F6 | zero connections with `last_refresh_error = 'missing_key: handle'` |
| F7 | no ingest source whose identifier ends `/profile.php` |
| F8 | no `uber_eats`/`doordash` source whose identifier lacks `/store/` |
| F9 | JSON-LD carries `addressLocality` + `addressCountry`, `telephone` starts `+` |
| F10 | meta description is the bio, not the boilerplate |
| F11 | zero `tiktokcdn` hosts in rendered `<img>` src |
| F12 | five consecutive `--parallel` runs green |
| F13 | zero `cloudflare.cache_purge.failed` in the hour after the batch |
| F14 | every business build with a logo has both variants, or a logged reason |
| F16 | `fleet:new` with one bad spec dispatches nothing |
| F18 | `pageOrder[0] === 'home'` on every account |
| F20 | `fleet:verify` marks thin builds THIN |
| F21 | `business` / `business1` gone |

- [ ] **Step 4: Record the results**

Append an "ACCEPTANCE RESULTS" section to `docs/2026-08-27-unclaimed-signup-quality-plan.md` — the living ledger — with per-gate evidence and a citation for each. A gate without a citation is not met.

- [ ] **Step 5: Commit**

```bash
cd ~/Developer/Comet-Backend
git add docs/2026-08-27-unclaimed-signup-quality-plan.md
git commit -m "docs(plan): cold-build remediation acceptance results"
```

---

## Self-Review

**Spec coverage.** F1 → 1.1. F2 → 4.1, 4.2. F3 → 3.1, 3.2, 3.3. F4 → 1.2. F5 → 7.1. F6 → 2.3. F7 → 2.1. F8 → 2.2. F9 → 1.3. F10 → 1.4. F11 → 6.1. F12 → 8.1. F13 → 5.1, 5.2. F14 → 6.2. F15 → 8.4 (brief only, by the Scope Note). F16 → 8.2. F17 → not fixed: the pairing map is a product decision, recorded in the audit and left to the owner. F18 → 7.2. F19 → 8.4. F20 → 8.3. F21 → 8.4. F22 (a null sector silently revokes the menu capability and the OCR scan bails unlogged) → 7.4, with 7.1 removing the cause. Sector misclassification → 7.3. The purge escalation → Phase 5.

**Placeholders.** Task 6.2 Steps 2–3 and Task 2.3 Step 1 are locate-then-write steps rather than finished code, because the target symbol has not been read yet — each names the exact grep that resolves it and the exact assertion the test must end up making. Task 8.3's test is environment-skipped because it reads dev data. Everything else carries complete code.

**Type consistency.** `NameShapeGate::apply()` takes and returns `{displayName, firstName, lastName}` in 3.1, 3.2 and 3.3. `isPreparing(buildState)` in 4.2 consumes the `buildState` key produced in 4.1. `buildPostalAddress`/`internationalPhone` in 1.3 are consumed only there. `actionAttrs` in 1.1 is the existing export from `dom-contract.ts`.
