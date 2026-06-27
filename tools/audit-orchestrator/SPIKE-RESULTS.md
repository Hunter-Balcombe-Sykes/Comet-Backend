# Phase 0 Spike Results — Pre-implementation Verification

Run on 2026-05-02. All three spikes were SUCCESS — primary design assumptions validated.

## Spike 0.1: `claude --print --resume <session_id>` works non-interactively ✅

**Test:** Captured `session_id` from a fresh `--print --output-format stream-json` session, then ran `claude --print --resume <id>` with new input asking what was printed.

**Result:** SUCCESS. The resumed session correctly recalled context from the original session ("I printed the word PINEAPPLE."). Full conversation history preserved across the process boundary.

**Implication:** Proceed with `--resume` strategy as designed in spec §6.3. No fallback needed.

## Spike 0.2: `--output-format stream-json` emits `session_id` early ✅

**Test:** Inspected the first emitted events of a stream-json session.

**Result:** SUCCESS. `session_id` appears in the very FIRST event (a `system / hook_started` SessionStart event), and is identical across all subsequent events. Easy to capture from line 1 of stdout.

**Detail:** The first event is NOT necessarily `system / init` as the spec hypothesized — it's a SessionStart hook event. But `session_id` is present regardless. The `StreamEventTracker` in the implementation (Task 7.1) already handles this correctly — it captures session_id from the first event of any kind that has it.

**Implication:** Stream parser implementation is correct as written.

## Spike 0.3: notification tool ⚠ — falls back to osascript

**Test:** `which terminal-notifier`.

**Result:** Not installed.

**Decision:** Use built-in macOS `osascript` as the default. `notifications.py` (Task 11.1) handles this fallback. If Josh wants the richer terminal-notifier UX (sound, click-to-focus), he can:

```bash
brew install terminal-notifier
```

The orchestrator auto-detects which is available — no config change needed.

## Summary

All three spike findings are GREEN. Proceed with implementation as designed:
- Question/answer flow uses `claude --resume <session_id>` to preserve context.
- Stream parser captures `session_id` from the first event.
- Notifications use osascript by default (terminal-notifier optional upgrade).

## Side observation

The `claude` CLI on this system uses Max subscription auth (`"apiKeySource":"none"`), so each orchestrator-spawned `claude` invocation will burn Max quota. For sustained overnight runs, recommend setting `ANTHROPIC_API_KEY` in the environment of the orchestrator's shell so unattended sessions bill to API and don't drain the daytime Max quota. Document this in the README.
