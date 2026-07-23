# Cross-repo & Frontend Dead Code: unused frontend modules, subsystems the backend deleted, code only provable-dead across both repos

Hunt **dead code on the frontend side and code that is only provably dead when you look at both repos at once** — a React component nothing imports, a whole feature directory for a subsystem the backend removed, a page with no navigation into it, a frontend module reachable only through a capability the backend stopped emitting. This is the **frontend-and-cross-repo** companion to `code-quality-slop`.

**Scope boundary (do not cross it):** backend **intra-repo** dead code — an unused private method, a vestigial `config/partna.php` key, an orphaned service-provider binding, all provable dead within this repo — belongs to `code-quality-slop` (Stage 4), **not here**. This lens only owns dead code that is (a) in the frontend repo, or (b) provable-dead only by combining both repos. If a finding is fully provable inside this backend repo alone, it is a `SLOP` finding — drop it here.

- **Frontend repo:** `$PARTNA_FRONTEND_PATH` (Next.js; default `../partna-frontend`), read-only — never fetch, pull, or check it out.
- **`knip` cross-check:** the frontend root has `knip.json`. Where a finding overlaps knip's own unused-export/file analysis, **run knip and report the agreement or disagreement in the finding** — do not silently pick a side. Knip proves intra-frontend deadness; it cannot see the backend, so cross-repo findings (below) are yours alone.

## Use the lens prefix `XDEAD` for findings

Number them `XDEAD-1`, `XDEAD-2`, … sequentially. Dead code is rarely critical — **P3 by default. P2 only when the dead code is a whole removed subsystem still shipping in the bundle (weight/attack-surface), duplicates live logic that will drift, or actively misleads a reader about what the product does. Never P0/P1** — a live 404 is an `XREPO` finding, not this lens.

## Findings categories

### (1) Unused frontend modules — nothing imports them
- A component, hook, util, or type module in `$PARTNA_FRONTEND_PATH` with **zero importers** across the whole frontend tree. Verify with a repo-wide grep of the frontend for the symbol/path AND cross-check knip. A default export used only in a test, or a barrel-file re-export with no downstream consumer, still counts — say which.

### (2) Dead subsystems the backend deleted
- A whole feature directory whose backend was stripped: `$PARTNA_FRONTEND_PATH/features/commerce`, `$PARTNA_FRONTEND_PATH/lib/shopify`, `$PARTNA_FRONTEND_PATH/lib/square`, `$PARTNA_FRONTEND_PATH/lib/stripe-connect.ts`, `$PARTNA_FRONTEND_PATH/features/booking`, `$PARTNA_FRONTEND_PATH/features/affiliates`, the `commerce`/`shop`/`affiliates` dashboard trees. Confirm the backend has no corresponding surface (grep this repo's `routes/`, `app/`), and that the frontend feature is not still linked from a live page. One finding per subsystem, not per file — name the directory and its total weight.

### (3) Cross-repo-provable dead code
- Frontend code whose only reachability is through a backend contract that no longer exists: a component rendered only on a page that calls a removed route, a store/provider hydrated only from a deleted endpoint, a capability-gated branch where the backend capability is gone. This is the frontend mirror of `XREPO` category 5 — the two lenses meet here. Cite the frontend code AND the backend absence.

### (4) Dead frontend routes / pages
- An `app/**/page.tsx` with no navigation into it — no `<Link>`, `router.push`, redirect, or menu entry anywhere in the frontend points at its route. Verify with a frontend-wide grep of the route path. A page kept intentionally (deep-link landing, external entry) is not dead — respect a comment or config that says so.

### (5) Vestigial frontend config / constants
- `proxy.ts` `RESERVED_PATHS` entries for removed features (`theme-1`, `theme-2`, `unique-themes`, `smart-tools`), env vars / feature toggles referencing a gone backend, dead route-group scaffolding. Confirm the referenced subsystem is gone on both sides.

### (6) Commented-out / stale scaffolding
- Commented-out blocks, `.old`/`.bak` modules, TODO-graveyard files, or generated scaffolding left in the frontend tree with no live reference.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Quote the offending code / name the directory verbatim as Evidence (file:line from `$PARTNA_FRONTEND_PATH`).
- **State the exact verification:** the frontend-wide `Grep` (path/symbol → zero live references) AND, for categories 2–3, the backend `Grep` proving the contract is gone. A deadness claim without the grep(s) is not allowed.
- **Report the knip result** where the finding is intra-frontend (categories 1, 4, 6): agree / disagree / knip-not-applicable.
- Give the fix: "delete `<dir>` (N KB)", "remove the export", "drop the `RESERVED_PATHS` entry".

## Anti-false-positive directive (adjudicator)

You (the Sonnet adjudicator) have `Read`/`Grep`/`Glob` over **both** repos, and can run `knip` in the frontend. The scan tier saw only the scoped frontend files — it **cannot** know whether a component is imported from a file outside scope, lazy-loaded by string, or referenced in a route manifest. Before confirming any XDEAD finding:

- **Dead frontend code:** grep the WHOLE frontend for the symbol/path (imports, dynamic `import()`, string references in route configs). If it has a live reference, **drop it.** Cross-check knip.
- **Dead subsystem (cross-repo):** confirm the backend surface is gone (grep this repo) AND the frontend feature is unlinked. If a live page still renders it, it is not dead — it may be an `XREPO` live-404 instead.
- **Backend intra-repo dead code:** **out of scope** — that is `code-quality-slop`. Drop it here even if you spot it.
- **Respect intentional dormancy.** Code kept on purpose for a deferred feature, or a deliberately-transitional shim, is not dead. When comments or config signal "kept on purpose," drop it.
- **Respect the stale-frontend caveat.** If `CONTRACT-INVENTORY.md` is stamped STALE, an actively-edited frontend may show transient unreferenced files — require a clean source read before confirming.

A short, provable report beats a long one full of "probably unused." When the grep isn't conclusive in both repos, drop it.

## Suggested scope groups

Frontend paths use `$PARTNA_FRONTEND_PATH` (a live, read-only checkout). Pair with the inventory for cross-repo categories.

### Known-dead subsystems (highest hit-rate)
```
--scope audits/cross-repo/CONTRACT-INVENTORY.md
--scope $PARTNA_FRONTEND_PATH/features/commerce
--scope $PARTNA_FRONTEND_PATH/lib/shopify
--scope $PARTNA_FRONTEND_PATH/lib/square
--scope $PARTNA_FRONTEND_PATH/features/booking
--scope $PARTNA_FRONTEND_PATH/features/affiliates
--scope $PARTNA_FRONTEND_PATH/proxy.ts
```

### Frontend sweep (unused modules / pages)
```
--scope $PARTNA_FRONTEND_PATH/components
--scope $PARTNA_FRONTEND_PATH/lib
--scope $PARTNA_FRONTEND_PATH/features
--scope $PARTNA_FRONTEND_PATH/app
```

## Exhaustiveness directive

Read every file in scope. Dead code hides in barrel re-exports, `features/*` trees for subsystems nobody wired down, and pages with no nav. But never invent a finding to pad the list, and never report a backend-only dead-code item here — an empty category is the correct output when the frontend is clean and the boundary is intact.
