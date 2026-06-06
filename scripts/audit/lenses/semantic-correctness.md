# Semantic Correctness: code that compiles and type-checks but does the wrong thing

Hunt **plausible-but-wrong code** — the class of bug that survives the compiler and the static analyser because it is syntactically valid and well-typed, but was written from an incorrect mental model of an API, a config value, a spec, or the surrounding intent. This is the **hallucination half that Larastan cannot catch.** Larastan now enforces symbol existence (undefined methods/properties/classes/config). This lens covers what is left: *the symbol exists, the types line up, but the behaviour is wrong.*

This is the highest-value and the **most dangerous** lens to run with an LLM, because the scanner is prone to the very failure it hunts: it can confidently assert "the correct behaviour is X" from a plausible-but-wrong memory of an API. (Real example from this codebase: `Redis::set($k, $v, 'EX', 30, 'NX')` *looks* broken under phpredis but is correct, because Laravel's `PhpRedisConnection::set()` translates the flat args. A four-line empirical test disproved the "bug.") **Every finding must be grounded in evidence read from this repo or proven by a check — never in the model's prior about how a library "should" behave.** See the Anti-hallucination directive below; it overrides the urge to flag.

## Use the lens prefix `SEM` for findings

Number them `SEM-1`, `SEM-2`, … sequentially. Tier by blast radius: **P1 when the wrong behaviour ships incorrect results on a common path (broken guard, inverted condition, wrong unit on a security TTL). P2 for edge-case-only wrongness or behaviour that is currently masked by luck. P3 for harmless-today deviations that will break under a plausible future change.**

## Findings categories

### (1) Real method, wrong contract

- A real method called with arguments in the wrong order, or with the wrong **unit** (seconds vs milliseconds, bytes vs KB) — the call type-checks but the value is wrong.
- Misreading a return contract: treating a `Collection` as a plain array, ignoring that a builder method returns a **new** instance (`$query->where(...)` without reassignment on an immutable builder), assuming `first()` is non-null.
- Calling a method for its return value when it mutates in place (or vice versa).
- Using a method whose name implies one thing but whose documented behaviour is another (`firstOrCreate` vs `updateOrCreate`, `pluck` key/value order).

### (2) Config / feature-flag misuse

- Reading a config key that exists but with a `?? default` that **contradicts** the documented default in `config/partna.php` — so behaviour diverges when the key is unset.
- Checking a feature flag **inversely** (`if ($disabled)` where the flag means "enabled"), or gating on a flag that is always-on/always-off after the standalone strip.
- `env()` called outside `config/` (breaks under `config:cache` — a real Laravel footgun: cached config makes `env()` return null in production).
- Reading a capability/limit directly instead of through `AccountCapabilities::for($user)`, bypassing the project's capability gate.

### (3) Plausible-but-wrong magic values

- A TTL, limit, threshold, or retry count that looks reasonable but **contradicts the spec or an adjacent definition** (e.g. a cache TTL of 3600 next to a sibling that documents 300; a 14-day reclaim window hardcoded as 7).
- A hardcoded literal that should reference an existing config constant — and the literal has drifted from it.
- An enum/status compared against a **string that is not a valid case** of that enum — the comparison silently never matches.

### (4) Logic that contradicts intent

- Inverted conditionals, wrong boolean combinator (`&&` where `||` is meant, or De Morgan mistakes in a negated compound), off-by-one in a range/slice.
- An early `return`/`continue` that skips a side effect the surrounding code depends on (a cache write, an audit log, a counter increment).
- A guard that is structurally present but **semantically inert** — it can never be false (or never true) given the values that reach it, so it provides no protection (distinct from Larastan's always-true/false: this is about *intent*, e.g. a fail-closed check that actually fails open).
- Catching an exception type that the protected call cannot throw, while the one it *can* throw propagates.

### (5) Codebase-idiom drift (looks right elsewhere, wrong here)

- `authorize()` instead of `authorizeForUser($user, ...)` — under Supabase JWT `Auth::user()` is null, so `authorize()` silently passes. Type-valid, security-wrong. (Coordinate with the security lens; report here only if that lens would miss it.)
- Returning `403` where the project standard mandates `404` for missing/not-owned resources on public endpoints (enumeration leak) — valid HTTP, wrong doctrine.
- Writing to a path the architecture forbids (`site.themes`, `settings.design.*` post-skeleton-cleanup; `SUBDOMAIN_KV` from anywhere but `SyncSubdomainToKvJob`) — the code works but violates a single-writer / removed-surface invariant.
- Querying the wrong schema or connection (a model not extending `BaseModel`, a job on the wrong Redis connection).

## Per-finding requirements

For every finding:
- Cite the category number (1–5).
- Quote the offending code **verbatim** as Evidence.
- State **the correct behaviour AND the ground-truth source for it** — the exact file + line of the config/spec/enum/adjacent code that proves what "correct" is. *"The library docs say X" is not acceptable ground truth; cite this repo, or mark the finding for empirical verification.*
- Explain precisely **how the current code deviates** from that ground truth and what the observable wrong effect is.
- Name the fix.
- Append a confidence and, when the claim rests on external library behaviour, an explicit **`[VERIFY: <one-line test or grep the adjudicator must run>]`** tag.

## Anti-hallucination directive (adjudicator — this is the load-bearing rule)

You (the Sonnet adjudicator) have `Read`/`Grep`/`Glob`. The scan tier guesses; you confirm. A semantic-correctness finding is **only as good as its ground truth**, and the scan tier's ground truth is often a confident memory that is wrong. Before confirming any SEM finding:

1. **Ground every "correct behaviour" claim in this repo.** Open the cited config key in `config/partna.php`, the enum case, the spec doc, or the adjacent definition. If you cannot find the source that establishes "correct," **drop the finding** — do not substitute your own prior about the library.
2. **For any claim resting on framework/library behaviour, demand a check.** Prefer to reason from Laravel's actual source in `vendor/` (which you can `Read`), or note the exact empirical test that would settle it. If the behaviour is genuinely ambiguous and unverifiable from the repo, **downgrade to a P3 question, not a P1 bug.**
3. **Assume the existing code is right until proven wrong.** It runs in production. The burden is on the finding to prove deviation with a citation, not on the code to prove innocence. Inverted defaults, flag polarity, and units are the categories worth the effort; vague "this feels wrong" is not.
4. **Respect documented intent and dormancy.** Empty maps, dormant vocab, and gates with nothing to gate after the standalone strip are intentional — not semantic bugs.

A SEM report with three proven findings is worth more than thirty plausible ones. Hallucinated bug reports about working code are the worst possible output of this lens — they waste fix-session time and erode trust in the pipeline. When the ground truth is not in front of you, do not confirm.

## Suggested per-domain scope groups

### Group A — services with external-API / cache / Redis calls (richest semantic surface)
```
--scope app/Services
```

### Group B — jobs (units, retries, idempotency, side-effect ordering)
```
--scope app/Jobs
```

### Group C — controllers + policies (auth idiom, status codes, capability gates)
```
--scope app/Http/Controllers
--scope app/Policies
```

## Exhaustiveness directive

Read every file in scope. Semantic bugs are invisible to a skim — they require understanding what each call is *supposed* to do and checking the code against that intent, grounded in the repo. Examine every config read, every TTL/limit literal, every flag check, every guard, and every external-API call signature. But emit nothing you cannot ground: the correct output for correct code is an empty list.
