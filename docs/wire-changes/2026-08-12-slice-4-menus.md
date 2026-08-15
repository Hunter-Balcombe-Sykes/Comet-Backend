# Wire change — slice 4: menus on `content.*`

**Status:** merged to `development`, pending live verification on dev.
**Spec:** `docs/superpowers/specs/2026-08-12-slice-4-menus-design.md`.
**Consuming repos:** partna-monorepo (sitepage render), Partna-App (dashboard).

The new wire is described on its own terms. Nothing here is a diff from the
legacy menu payload, because the frontend is being rebuilt (owner ruling
2026-08-14, convergence-log F17) and a diff would document a shape nobody is
going to write against.

---

## 1. `GET /api/public/profiles/{handle}` — `pools.menus`

A new pool alongside `watch`, `listen`, `media`, `events`, `services`, `shop`,
`reviews` and `custom_links`. Absent entirely when the owner's selection is
empty, exactly like every other pool.

```jsonc
{
  "pools": {
    "menus": {
      "items": [ /* pool items, see §2 */ ],
      "latestItemId": null,
      "collections": { /* see §3 */ },
      "diningModes": ["DELIVERY", "PICKUP"]
    }
  }
}
```

- **`latestItemId` is always `null`.** Menus are not in `LATEST_TAG_POOLS` — a
  "latest dish" would label whichever dish the vendor last re-listed as new.
- **`collections`** is the existing map slice 5b added for shop store cards.
  Menus put two kinds of entry in it (§3).
- **`diningModes`** is new, and menus-only. Absent when the vendor publishes
  none.

## 2. Pool items

A dish uses the same item shape every pool item has — `PoolResolver::ITEM_KEYS`
is the enforcement point and `tests/Feature/Content/PoolWireShapeTest.php`
fails on additions as well as removals. No key is menu-specific.

What a dish populates:

| Key | Source |
|---|---|
| `id` | `content.items.id` |
| `slug`, `aliases` | `content.item_slugs` — the current slug, then every retired slug that should still 301 to it, with the raw id always last |
| `headline` | the dish name |
| `description` | `f_text.body` |
| `price` | the `base` channel offer |
| `thumbnail` | `item_media` role `cover` |
| `collectionIds` | its categories AND the ordering platforms it is sold on |

`review`, `startsAt`, `venue`, `variants`, `vendor`, `durationSeconds` and the
rest are `null` / `[]` on a dish. The wire shape does not vary by kind — that
contract is why a consumer can destructure one item shape for every pool.

**Permalinks.** A dish is addressable by `slug`, and every retired slug in
`aliases` must 301 to the current one. This is new on the pool lane: the legacy
lane forgot a vendor-renamed dish's slug rather than retiring it, so vendor
renames broke their old URLs. They now redirect.

## 3. The `collections` map

Keyed by collection **uuid**. Every entry carries the full key set —
`externalRef`, `provider`, `url`, `name`, `currency`, `favicon`, `logo`,
`discountCode`, `position` — with `null` where a field does not apply. A
consumer must not branch on which kind of collection it received.

Two kinds appear for menus, distinguished by their `externalRef` prefix:

```jsonc
"collections": {
  "0192…": {                      // a menu category
    "externalRef": "menu:pizzas",
    "name": "Pizzas",
    "position": 0,
    "provider": null, "url": null, "currency": null,
    "favicon": null, "logo": null, "discountCode": null
  },
  "0193…": {                      // an ordering platform
    "externalRef": "order:uber_eats",
    "name": "uber_eats",
    "provider": "uber_eats",
    "url": "https://www.ubereats.com/store/…",
    "currency": "AUD",
    "position": 0,
    "favicon": null, "logo": null, "discountCode": null
  }
}
```

- **Categories group dishes for display.** The pool selects *dishes*;
  categories are not themselves selectable.
- **Ordering platforms are the store cards** — where a visitor can order. They
  carry a `content.storefronts` sidecar, the same shape shop store cards use.
- A collection the owner has deleted is absent, even while its dishes are
  still selected.

## 4. `diningModes`

`["DELIVERY"]`, `["DELIVERY","PICKUP"]`, or absent. Store-level metadata: which
service modes the vendor offers. It describes the restaurant, not any one dish,
which is why it sits on the pool envelope beside the items rather than on each
of them — the same placement `stats` takes on the reviews pool.

## 5. Offers, for a consumer that reads them

`content.offers` is a **set** and is never resolved to a winner. One dish can
carry:

- the aggregate `base` / `pickup` / `delivery` prices (no `url`), and
- one offer per ordering platform per priced mode, each with that platform's
  `url`.

Two platforms selling the same dish at different prices are both true. A
consumer that renders "the price" should read the `base` channel; one that
renders "where to order" should read the per-platform offers with URLs.

`currency` may be `null` where the vendor published none and the menu carries
no default. 93 of 318 dev rows are in that state.

## 6. What does NOT change

- **The legacy menu wire is untouched.** `GET /api/public/profiles/{handle}/menu`
  and the dashboard's `GET /platforms/menu` serve exactly what they served
  before. Both lanes run side by side until slice 7 retires the legacy one, and
  `site.menu_items` is not dropped by this slice.
- **Every other pool's payload is byte-identical.** `diningModes` is spread only
  when non-null, the same additive contract `collections` and `stats` keep.

## 7. Deliberately dropped

Named as decisions, not omissions:

| Dropped | Why |
|---|---|
| `menu_platform_links.status` (`pending`/`ok`/`unavailable`) | Scrape health, not content. `connect_status` on the storefront sidecar means something else, and no public surface read it. |
| Connector-side dish `rating` / `badges` | `MenuRecords::flatten()` carries neither — the legacy merger sourced them from DoorDash's own payload. Backfilled dishes keep theirs; newly scraped ones will not have them until the connector's doc shape grows. |
| Historical menu analytics | `analytics.item_views` rows reference legacy dish uuids. Identity merges delete items, and repointing across a merge would attribute one dish's history to another. Dev-only data, no customers. |

## 8. Edge cache

`CloudflarePurgeService::purgeHandle()` now purges dish pages under **all
three** addressable forms — the legacy uuid, the content item id and the current
slug — because both wires are live and it cannot know which one a consumer
built its href from. Slice 7 removes the legacy third.
