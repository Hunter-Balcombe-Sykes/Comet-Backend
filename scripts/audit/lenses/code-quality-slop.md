# AI Slop & Low-Value Code: comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift

Hunt **low-value code that adds volume without adding value** — the texture of machine-generated or hastily-written code that a senior engineer would strip on review. This is *not* a correctness lens (Larastan + the security/lifecycle lenses cover bugs); it is a **taste and maintainability** lens. The bar is the Partna house style in `CLAUDE.md` — every finding must cite the specific rule it violates, not a generic "this could be cleaner."

Partna's stated standards (from `CLAUDE.md`, which is the rubric you grade against):
- **Commenting:** "purposeful, not extensive." Comment the non-obvious WHY, the contract, magic defaults, complex shapes. **Avoid** paragraph essays, comments that restate the next line, decorative banners, TODO graveyards.
- **Simplicity first:** "Make every change as simple as possible. Impact minimal code."
- **Do NOT over-engineer:** "three similar lines > a premature abstraction."
- **Comments:** "Don't drown files in comments."

## Use the lens prefix `SLOP` for findings

Number them `SLOP-1`, `SLOP-2`, … sequentially. Slop is rarely critical — **P3 by default. P2 only when the slop actively obscures a real bug, duplicates logic that will drift out of sync, or is dead code that misleads readers about what the system does. Never P0/P1** — if something is a security or correctness bug, it belongs in a different lens.

## Findings categories

### (1) Comment noise — restating, decorating, or hedging

- Comments that restate the very next line (`// increment the counter` above `$count++`).
- Decorative banner comments (`// ===== HELPERS =====`, ASCII art separators).
- TODO/FIXME graveyards — stale TODOs with no ticket, owner, or date; multiple TODOs that have clearly been ignored for releases.
- Hedging / thinking-out-loud comments left in (`// this should probably handle the edge case`, `// not sure if this is right`).
- Paragraph-length essays where one line would do, or docblocks on trivial getters/setters that add nothing the signature doesn't already say.
- Redundant `@param`/`@return` docblock tags that only repeat the typed signature (no shape, no constraint, no units).

### (2) Premature abstraction / over-engineering

- A trait, interface, abstract base, or factory introduced for a single concrete implementation.
- A config-driven indirection where a literal would do and there is no second caller.
- Generic "manager"/"handler"/"helper" wrappers that only forward to one underlying call.
- Parameterising a method for flexibility that no caller uses (every call passes the same argument).
- A new abstraction that replaces three similar lines with twenty lines of machinery (violates "three similar lines > a premature abstraction").

### (3) Dead code — unused, unreachable, vestigial

- Private methods with **zero callers** (verify by grepping the whole repo, not just the scoped file).
- `use` imports that are never referenced in the file.
- Unused constructor-injected dependencies or unused method parameters.
- Unreachable branches (code after an unconditional `return`/`throw`; `else` after an exhaustive early-return).
- Vestigial config keys / class constants referenced nowhere.
- Commented-out blocks of code left in the file.

### (4) Redundant defensive cruft

- Null checks on values the type system already guarantees non-null (e.g. `if ($x !== null)` where `$x` is a non-nullable typed property just assigned).
- `try`/`catch` that catches, logs nothing useful, and rethrows the same exception unchanged.
- Double-guards — re-validating in a service what a Form Request already validated, with no defence-in-depth rationale stated.
- `isset()`/`??` on an array key the code just wrote on the line above.
- Defensive casts of values already of the target type.

### (5) Ceremony & verbosity

- Needless intermediate variables used exactly once on the next line, adding no naming value.
- Manual `foreach`-accumulate loops where a single Collection method (`map`/`filter`/`pluck`/`sum`) reads clearer.
- Hand-rolled logic that re-implements a framework helper (`Str::`, `Arr::`, `data_get`, `collect()`), often subtly worse.
- Multi-line builder chains that could be one expression, or a one-liner exploded across many lines for no readability gain.

### (6) Copy-paste duplication & drift

- Two or more near-identical blocks that differ only by a constant or a single token — a candidate for one parameterised path (only when it genuinely reduces risk, not to over-abstract per category 2).
- Parallel methods that were copied and have since drifted (one fixed a bug the other still has).
- Repeated magic literals (the same string/number copy-pasted) that should be one named constant.

### (7) AI-tell patterns (the texture of generated code)

- Over-explanatory variable names that encode the type (`$userArrayList`, `$stringResult`).
- "Example usage" or tutorial-style comments inside production code.
- Style that diverges from the immediately adjacent code (spacing, naming convention, quote style) — a sign code was pasted from elsewhere without matching house idiom.
- Symmetry padding: empty `catch` arms, no-op `default` cases, or stub methods added "for completeness" with no caller.

## Per-finding requirements

For every finding:
- Cite the category number (1–7).
- Quote the offending code **verbatim** as Evidence.
- Name the **specific `CLAUDE.md` rule** it violates (e.g. "Commenting: comments that restate the next line", "Do NOT over-engineer").
- Give the **leaner replacement** — the actual shorter code, or "delete lines X–Y".
- **For any "dead code" / "unused" claim (category 3):** state the exact verification — `Grep` pattern across the whole repo (`app/`, `routes/`, `config/`, `tests/`) showing zero references. A claim of "unused" without a repo-wide grep is not allowed.

## Anti-false-positive directive (adjudicator)

You (the Sonnet adjudicator) have `Read`/`Grep`/`Glob`. The scan tier only saw the scoped files and **cannot** know whether a private method is called elsewhere, whether a "redundant" abstraction has a second implementer outside scope, or whether a comment documents a non-obvious constraint that *is* load-bearing. Before confirming any SLOP finding:

- **Dead code:** grep the whole repo for the symbol. If it has callers, **drop the finding.**
- **Premature abstraction:** check for a second implementer/caller. If one exists, **drop it.**
- **"Redundant" comment:** if the comment explains a WHY that isn't obvious from the code (a workaround, an ordering constraint, a deliberately-dormant feature like the post-strip CSAM vocab or empty capability maps), it is **not** slop — drop it.
- Respect intentional dormancy: code kept on purpose for a deferred feature is not dead code. When the surrounding comments or `CLAUDE.md` signal "kept on purpose," **do not** flag it.

A short, clean SLOP report beats a long one full of taste-policing. When in doubt, drop it.

## Suggested per-domain scope groups

This is a **breadth lens**: in `--codebase` mode it maps the whole product surface,
because dead code is only findable in files that were actually read. The groups below
are for targeted manual runs; the full sweep map lives in `codebase_chunks()`.

### Group A — services (richest slop surface)
```
--scope app/Services
--scope app/Mail
```

### Group B — HTTP surface (controllers, requests, resources)
```
--scope app/Http/Controllers
--scope app/Http/Requests
--scope app/Http/Resources
```

### Group C — jobs, observers, console
```
--scope app/Jobs
--scope app/Observers
--scope app/Notifications
--scope app/Console
```

### Group D — models, policies, support
```
--scope app/Models
--scope app/Policies
--scope app/Support
--scope app/Rules
```

### Group E — wiring (where vestigial features hide in plain sight)
```
--scope routes
--scope config
--scope app/Providers
```

### Group F — Catalog + link-router data (new pure-definition subsystem)
```
--scope app/Catalog
--scope app/Routing
--scope app/Ingest
--scope app/Content
--scope app/Site
```
(Controllers for this subsystem are already covered by Group B's `app/Http/Controllers` scope.)

## Exhaustiveness directive

Read every file in scope top to bottom. Slop hides in the gaps between the "real" code — the helper at the bottom of the class, the docblock nobody reads, the `catch` that does nothing. But never invent a finding to pad the list: an empty category is the correct output when the code is clean.
