# Backend developer — Claude Code setup for the standalone-pages plan

Before you start Track A work, set up your Claude Code environment so the same plan-enforcing skills, hooks, and CLAUDE.md context run on your machine. This file is a one-time setup checklist — ~15 minutes to complete.

---

## 1. CLAUDE.md updates (already done in `Comet-Backend/CLAUDE.md`)

The Comet-Backend CLAUDE.md has been updated with the architectural ground truth section and backend-specific rules for this plan. When you next start a Claude Code session in `Comet-Backend/`, it will see:

- Account types: `brand` / `partner` / `individual` with all transition rules
- `account_type` is source of truth; dual-write with `professional_type`
- SUBDOMAIN_KV writes/deletes only from `app/Jobs/Cloudflare/`
- Capability gating at dispatch layer
- Ex-partner state via soft-deleted BrandPartnerLink
- `brand_status` lowercase enum: `building | preview | live | systems_down`
- New domain enums in `app/Enums/`
- `lockForUpdate()` in transition service; jobs dispatch AFTER transaction
- Rate-limit IP defensive pattern (CF-Connecting-IP)
- Safe migration pattern (NOT VALID + VALIDATE; CONCURRENTLY in own file)

No action needed from you — just confirm by reading the top of `Comet-Backend/CLAUDE.md` after `git pull`.

---

## 2. Settings hooks (already added to `Comet-Backend/.claude/settings.local.json`)

A `PreToolUse` hook now nudges you to verify plan rules when editing:
- `app/Jobs/Notifications/*` (capability check + `report($e)` in failed())
- `app/Http/Controllers/Api/Professional/*` and `Api/Staff/*` (capability gating)
- `Professional.php` / `BrandPartnerLink.php` (plan-check skill)
- `app/Jobs/Cloudflare/*` (KV writer rule)
- `app/Services/Accounts/*` (transition service rules)
- Plan migrations (safe pattern reminders)

The hooks are `ask`-type, not blocking. They surface a one-line reminder, you confirm and proceed.

To verify: `cat Comet-Backend/.claude/settings.local.json` after `git pull`.

---

## 3. Install the 4 plan-specific skills

The user has created 4 skills at `~/.claude/skills/` on her machine. You need the same 4 on yours.

**Option A — clone via shared file (recommended):** the user will send you a tarball or commit the SKILL.md files somewhere you can pull. Then:

```bash
mkdir -p ~/.claude/skills/partna-plan-check ~/.claude/skills/account-capability-audit ~/.claude/skills/theme-portability-check ~/.claude/skills/partna-handoff-status
# Place each SKILL.md in the matching directory
```

**Option B — recreate from scratch using the descriptions below.** Each SKILL.md needs YAML frontmatter (`name` + `description`) plus a body. Get the user to send you the 4 files — recreation is error-prone.

### What each skill does

| Skill | Trigger | Purpose |
|-------|---------|---------|
| `partna-plan-check` | Editing Professional model, BrandPartnerLink, SyncSubdomainToKvJob, AccountCapabilities, BootstrapController, plan migrations | Reads the plan's non-negotiable rules and forbidden patterns; flags violations before edit lands |
| `account-capability-audit` | Adding/modifying notification jobs, API endpoints, policies, middleware | Verifies `AccountCapabilities::for($pro)` is consulted before acting (defence-in-depth) |
| `theme-portability-check` | Editing files under Hydrogen `app/themes/` (you'll mostly skip this — Track B owns themes) | Greps for forbidden imports |
| `partna-handoff-status` | Manual — invoke after merging a PR, deploying a migration, hitting a blocker | Updates `~/Developer/IMPLEMENTATION-STATUS.md` so the other developer's Claude session sees current state |

You'll use `account-capability-audit` and `partna-plan-check` daily during Phase 1. `partna-handoff-status` after every meaningful unit of work.

---

## 4. MCP parity check

The plan assumes both developers have these MCPs installed in Claude Code:

**Both developers need:**
- `claude-mem` (mcp-search) — cross-session memory; helps you check "did the other developer already figure out X?" without reading their diff
- `context7` — current library docs; first-line lookup before WebSearch
- `github` — PR creation/review and cross-repo coordination

**Backend-side specifically:**
- `laravel-boost` — should already be installed (referenced extensively in `Comet-Backend/CLAUDE.md`)
- `supabase` — should already be installed (migrations + schema management)

To verify which MCPs you have:
```bash
claude mcp list
```

If any of the "both" MCPs are missing, install via:
```bash
claude mcp add claude-mem npx -- -y @grahamlea/claude-mem
claude mcp add context7 npx -- -y @upstash/context7-mcp@latest
# GitHub MCP — varies by installation method; see https://github.com/modelcontextprotocol/servers
```

The user's full MCP config is documented in `~/Developer/CLAUDE-REFERENCE.md` (your `~/Developer/` after `git pull` if the user commits it; otherwise ask).

---

## 5. The shared status file (created at Phase 1 kickoff)

`~/Developer/IMPLEMENTATION-STATUS.md` will be created when Phase 1 starts. Both developers update it via the `partna-handoff-status` skill after meaningful work. Format documented in plan §54 (canonical template).

Do NOT create this file before Phase 1 starts — it's a working artifact, not a planning one.

---

## 6. Read order before starting Track A

1. `~/Developer/Comet-Backend/CLAUDE.md` — architectural ground truth
2. `~/Developer/CLAUDE.md` — cross-repo conventions

If your Claude session asks you to make a non-trivial decision not covered by the plan, follow the `[STOP — PLAN DECISION NEEDED]` protocol documented in plan §48. Don't burn turns silently working around plan ambiguities — ping the user.

---

## 7. The big-decision STOP protocol

When your Claude session encounters a decision that wasn't anticipated in the plan, format the question as:

```
[STOP — PLAN DECISION NEEDED]

Context: <one sentence describing where in the work you are>
Decision: <one sentence describing the decision required>
Options:
  A) <option A> — implications: <...>
  B) <option B> — implications: <...>
Recommendation: <which one and one-sentence why>
Affects other track: <yes/no — if yes, what>
Plan section impacted: <e.g. "§39.2 table row for SyncSubdomainToKvJob">
```

The user reads it, decides, replies. If "affects other track: yes" she'll ping me before unblocking you. After the decision lands: update the plan + log it in IMPLEMENTATION-STATUS.md.

---

## 8. Track A scope summary

Per plan §46 + §44, your scope:
- All migrations (account_type 3-step, BrandPartnerLink soft-delete, brand_signup_code, audit table)
- `app/Enums/AccountType.php`
- `app/Services/Accounts/*` (Capabilities, TransitionService, Set)
- `AccountTypeTransitionEvent` + listeners
- `app/Services/Cloudflare/CloudflarePurgeService.php` + Job
- `SyncSubdomainToKvJob` individual branch (5-line change) + `ShouldBeUnique` trait
- Public profile API endpoint
- Brand signup code mechanism (service at `app/Services/Professional/Brand/`, model at `app/Models/Core/Professional/`, controller, audit table)
- Notification preferences capability filter
- **Capability gating across ~40–55 sites** (Stripe controllers/services/CommissionPolicy/middleware/webhook/resources/controllers) — long pole
- Bootstrap flow update
- Brand invite acceptance → AccountTypeTransitionService wiring
- Individual waitlist flag
- Several existing-code defects absorbed (PolicyCoverageTest fix, 5 jobs missing `report($e)`, DATA-1/DATA-2 CASCADE fixes, DATA-4 PurgeSoftDeleted gap, LIFE-1 webhook key, LIFE-2 BrandStatusService race)
- ~100–150 test cases (capability matrix + transition + soft-delete + signup-code + architecture)

Realistic effort: **5–7 weeks of focused solo work** per the consolidated audit's revised estimate (originally "3–5 weeks" before audit). Confirm with user before scheduling.

---

Questions? Ping the user before starting work and she'll loop me in if needed.
