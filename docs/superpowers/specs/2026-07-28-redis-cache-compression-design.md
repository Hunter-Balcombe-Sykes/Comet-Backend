# Redis Cache Value Compression

**Date:** 2026-07-28
**Status:** Design approved, implementation pending
**Origin:** `docs/superpowers/research/cache-gold-standard-2026-07-28.md` §2.5 (P2), recommendation #4
**Scope:** The `redis.cache` connection's phpredis value-encoding options (`config/database.php`). Every other Redis connection, Cloudflare CDN, edge cache, and KV are out of scope.

---

## 1. Problem

`config/database.php` wires an igbinary serializer **and** LZ4 compression onto the cache connection behind a single `REDIS_IGBINARY` flag, defaulting `false`. The flag is set in neither Cloud environment, so cached values are stored as raw PHP `serialize()` output today.

The inline comment claims "~3× memory savings on large payloads" and lists two prerequisites: confirm phpredis was compiled with both extensions, and flush the cache on deploy.

Valkey is a single 250 MB instance shared by all five Redis databases (queue + Horizon on DB 0, cache on DB 1, sessions on DB 2, dormant queue-override on DB 3, cache locks on DB 4). Memory is the binding constraint on that instance; CPU is not. Reducing the cache's footprint therefore buys headroom for the databases that cannot tolerate eviction.

## 2. Corrected premises

Three corrections to §2.5, established by measurement on `development` (phpredis 6.3.0, PHP 8.4) on 2026-07-28. Raw figures in §3.

### 2.1 igbinary is a no-op — the entire win is LZ4

`Illuminate\Cache\RedisStore::serialize()` (`vendor/laravel/framework/src/Illuminate/Cache/RedisStore.php:473`) already calls `serialize($value)` and hands phpredis a **string**. By the time the phpredis serializer runs, there is no object graph left to encode — only a string to re-wrap.

Measured on a real `pro:model:{id}` payload:

| Encoding | `MEMORY USAGE` | vs. today |
|---|---|---|
| PHP `serialize()`, no compression (today) | 8,256 B | — |
| `SERIALIZER_IGBINARY` only | 8,248 B | 0.1% |
| `COMPRESSION_LZ4` only | 2,616 B | **3.16×** |
| `SERIALIZER_IGBINARY` + `COMPRESSION_LZ4` | 2,624 B | 3.15× |

`igbinary_serialize()` of the 7,442-byte serialized string is 7,449 bytes — a header plus the same bytes. igbinary applied to the **model** would have produced 2,305 B (3.2×), but it never sees the model.

The serializer is not merely useless: paired with compression it is 8 bytes *worse* than compression alone, and it costs CPU on the authenticated hot path. The `~3×` figure in the comment is real but is attributable entirely to the compression half of the pair.

### 2.2 The flush prerequisite guards the wrong direction

Mixed-encoding reads were tested directly:

- A legacy **uncompressed** value read with compression **on** returned the original string intact (1,028 B) and `unserialize()`d successfully. phpredis passes through payloads that do not look compressed.
- A **compressed** value read with compression **off** returned 37 bytes of raw compressed data.

So rolling *forward* is safe without any flush — pre-existing entries stay readable and age out on their own TTLs. Rolling *back* is the unsafe direction, and §2.5 does not mention it.

The pass-through behaviour is empirically verified on phpredis 6.3.0, not contractually guaranteed. The rollout in §6 does not depend on it for correctness, only for avoiding an unnecessary flush.

### 2.3 `pro:model:{id}` is not the largest payload in the keyspace

§2.5 calls it "the single largest serialised payload in the keyspace". A scan of all 151 string keys on the dev cache DB puts it third:

| Key group | Keys | `MEMORY USAGE` | Largest value |
|---|---|---|---|
| `platforms:itunes:*` | 6 | 81,792 B | 42,158 B |
| `pro:{uuid}:*` | 3 | 41,344 B | 17,709 B |
| `pro:model:*` | 1 | 10,352 B | 10,122 B |

The claim that it is rewritten every 60 s per active user is correct (`partna.cache.ttls.professional_model`), so it is the most write-heavy large payload — but sizing the change on it alone understates the benefit. Compression is a keyspace-wide property, and the keyspace-wide figure is what §3 uses.

## 3. Measurements

Whole dev cache DB, 151 string keys, 2026-07-28:

| | Total `MEMORY USAGE` | Ratio |
|---|---|---|
| Today | 157,968 B | — |
| LZ4 | 45,560 B | **3.47×** |
| ZSTD | 35,752 B | 4.42× |

No key group grew, including 1-byte values — compression drops values below Redis allocator bucket boundaries often enough that the per-key overhead dominates the rest. Two groups showed ZSTD marginally worse than LZ4 (120 B vs 112 B on a 44-byte value); the difference is noise.

Dev carries light traffic and a small keyspace, so these ratios are indicative rather than a prod projection. Prod currently holds no customer data (`core.users` = 0) and its cache is near-empty, so no better sample exists.

Prerequisite 1 from the original comment is satisfied: dev phpredis 6.3.0 reports `SERIALIZER_IGBINARY`, `COMPRESSION_LZ4` and `COMPRESSION_ZSTD` all available, and `ext-igbinary` loaded.

## 4. Decisions

| Decision | Choice | Reasoning |
|---|---|---|
| Serializer | **Delete the igbinary branch entirely** | Measured no-op (§2.1). A knob that does nothing, next to a comment claiming it does 3×, is an invitation to flip it later expecting a win. |
| Custom `RedisStore` subclass to let igbinary see the object graph | **Rejected** | Reaches 2,305 B, which is worse than LZ4's 2,616 B, in exchange for a custom store to maintain and a Laravel-upgrade liability. |
| Codec | **LZ4** | 3.47× at near-zero CPU (~4 GB/s decompress) on the authenticated hot path. ZSTD's extra 22% is real but spends ~4× the decompression cost for headroom not currently needed. Documented as the upgrade if memory later binds. |
| Flag shape | **Boolean**, not a codec name | Switching to ZSTD is a one-line code change. A codec-name string is configuration for a decision that has been made. |
| Rollout | **Dev first, gated on correctness** | Sizing evidence is already collected (§3), so the promote gate is "every consumer round-trips", not "we measured a saving". A prod control carries no signal at 0 users. |
| Rollback | **Prefix bump, not flush** | See §7. |
| k6 load run before deciding | **Rejected** | A reversible env var does not warrant a load-test cycle. |

## 5. The change

### 5.1 `config/database.php`

The `redis.cache.options` block loses the `serializer` key and renames the flag:

```php
'options' => array_filter([
    'compression' => env('REDIS_CACHE_COMPRESSION', false) && defined('Redis::COMPRESSION_LZ4')
        ? Redis::COMPRESSION_LZ4
        : null,
]),
```

`array_filter` stays — it drops the `null` so the option is absent rather than explicitly unset when the flag is off.

The comment above it must carry, at minimum:

- the measured keyspace ratio and the date, so the next reader does not re-derive it;
- **why there is no serializer** — that `RedisStore` serializes to a string first, with the 8,248-vs-8,256-byte figure — so nobody re-adds it;
- that rolling forward needs no flush but **rolling back does**, pointing at §7;
- ZSTD as the documented upgrade path with its measured 4.42×.

### 5.2 Guard test

New `tests/Feature/Cache/CacheCompressionConfigTest.php` — config-shape assertions only, no Redis instance required, so it runs in the standard SQLite lane alongside `CacheKeyspaceConstraintsTest`:

1. **The `cache` connection declares no `serializer` key.** The regression this exists to catch is someone re-adding igbinary. The failure message must carry the 8,248-vs-8,256-byte measurement, so the test explains itself without requiring the reader to find this spec.
2. **The `default`, `session`, `queue` and `cache_locks` connections declare no `compression` key.** Asymmetric encoding *across* connections is the genuine footgun: DB 0 holds Horizon job state, DB 4 holds locks whose values are read by a different store binding.
3. **The flag is read from `REDIS_CACHE_COMPRESSION`.** Pins the name so a rename cannot silently disable compression in an environment that still sets the old one.

Assertion 1 is a negative assertion — it fails when a config key is *added*. That is deliberate and mirrors how `CacheKeyspaceConstraintsTest` enforces the no-`forever()` rule: the invariant lives in a test because comments have already proven insufficient here.

### 5.3 Research-doc correction

`docs/superpowers/research/cache-gold-standard-2026-07-28.md` is corrected in place, following the precedent of `946f57f3 docs(cache): correct the eviction-policy research premise`:

- §2.5 (line ~169) — rewrite around LZ4; drop the igbinary recommendation; correct the flush direction; correct the "largest payload" claim.
- Line 101 summary-table row — "igbinary/MessagePack + compression" becomes compression-only.
- Line 250 recommendation #4 and line 326 — rename the env var, replace "~3× on `pro:model:*`" with the measured keyspace figure.
- §2.5's Plain English block must be rewritten too, not just the technical paragraph — it currently teaches the wrong mechanism.

## 6. Rollout

1. **Merge the config change with the flag defaulting `false`.** Zero behaviour change; this commit is independently safe and can sit on `development` unenabled.
2. **Set `REDIS_CACHE_COMPRESSION=true` on `development`.** Trap: `cloud environment:variables` **replaces the entire set**. Read the current variables first and re-send them with the addition, or the environment is wiped.
3. **Deploy restarts the instances. Run no flush** (§2.2).
4. **Verify on dev — this is the promote gate.** Per consumer:
   - `Cache::put`/`Cache::get` round-trip on a `pro:model` shaped payload;
   - the idempotency **set** in `app/Http/Middleware/IdempotencyKey.php` — `sadd` then `smembers`. Set *members* pass through the codec, and this is the only non-string-value consumer on the connection;
   - `AccountDeletionService::purgeIdempotencyCache()` reading those members back and forgetting them;
   - `php artisan cache:stats` — uses `INFO` and `DBSIZE`, expected unaffected;
   - a pre-existing (uncompressed) key still reads correctly, confirming §2.2 in situ.
5. **Soak, then promote to `production`** by the same mechanism, with the same trap.

## 7. Rollback

Unset `REDIS_CACHE_COMPRESSION` **and bump `CACHE_PREFIX`** in the same deploy. Do **not** rely on `cache:clear`.

A flush has a race. During the rollback deploy, compression-on and compression-off instances coexist briefly; the compression-off instances read compressed bytes, `unserialize()` returns `false`, and Laravel caches a *falsy hit* rather than registering a miss. A flush issued before the last old instance drains is also simply refilled by it.

A prefix bump has no such window: the old compressed keys become unreachable immediately and expire on their own TTLs. That every key has a TTL is guaranteed by `CacheKeyspaceConstraintsTest`, which forbids `Cache::forever()`.

**`CACHE_PREFIX` (`config/cache.php:124`) is per-store. `REDIS_PREFIX` (`config/database.php`) is global across all five databases.** Bumping the latter orphans in-flight Horizon jobs on DB 0.

`cache:clear` may follow as cleanup once the rollback deploy has fully drained, to reclaim the orphaned keys ahead of their TTLs. It is optional, and safe at that point because the lock connection is isolated on DB 4.

## 8. Testing

- `composer test` — the new guard test plus the existing cache suite.
- The dev verification checklist in §6.4 is manual, executed via `cloud command:run development`. It is not automatable in CI: the test lane has no Redis instance, and the behaviour under test is a property of phpredis's wire encoding rather than of application code.

## 9. Out of scope

- ZSTD. Documented in the config comment as the upgrade path with its measured ratio; adopting it is a separate one-line decision if the 250 MB instance later binds.
- Compression on any connection other than `cache`. Sessions (DB 2) would be a plausible future candidate; queue (DB 0) would not, because Horizon reads job payloads through tooling that does not share these options.
- The `pro:model:{id}` payload shape itself. Caching a hydrated Eloquent model with an eager-loaded relation is what makes it large; §2.5 does not challenge that design and neither does this spec.
- The doubled key prefix observed during measurement (`partna_database_partna-cache-…`, `REDIS_PREFIX` concatenated with `CACHE_PREFIX`). Harmless, pre-existing, and changing it would orphan the entire keyspace.
