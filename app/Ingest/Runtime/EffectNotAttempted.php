<?php

namespace App\Ingest\Runtime;

/**
 * A billed-effect DRIVER did not send a request at all: a denied budget claim,
 * or a credential that was never configured.
 *
 * "No request left the process" is the entire contract, and it is why
 * EffectLedger::once() may remove the claim it just took — nothing can have been
 * charged, so there is nothing to protect against a re-run. Raise this after a
 * request has gone out and that request becomes re-billable.
 *
 * Named for the fact rather than the reason (the design spec called this
 * BudgetRefused): a missing API key is exactly as pre-vendor-call as an exhausted
 * budget, and settling it 'failed' would lock every affected digest for the rest
 * of the freshness window over a config typo — and keep it locked after the typo
 * was fixed.
 *
 * Extends EffectRefused so RunExecutor's existing catch folds it to
 * 'budget_skipped'. The plain EffectRefused it inherits from keeps its own
 * broader meaning (off-manifest host, ledger refusal) and its own
 * claim-RETAINING handling — that difference is the whole point of the subclass.
 */
class EffectNotAttempted extends EffectRefused {}
