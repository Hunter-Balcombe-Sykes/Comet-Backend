<?php

namespace App\Services\Accounts;

/**
 * Snapshot of what a Professional can do RIGHT NOW. Built by {@see AccountCapabilities}.
 *
 * Read-only. Construct once per Professional per request and pass it around — capability
 * checks are pure functions on this value object so a single instance can be reused freely.
 *
 * Standalone-pages model: all accounts are individual. Only `can_edit_design` is true.
 * Capabilities for commerce/payout/brand features were removed in the 2026-05-22 strip
 * and will be re-added as named params here when reintegrated.
 */
final readonly class AccountCapabilitySet
{
    /**
     * @param  string  $notification_categories  Comma-separated list of allowed categories.
     *                                           'full' means every category in the registry.
     * @param  string  $worker_kv_type  Routing tag written to SUBDOMAIN_KV by
     *                                  SyncSubdomainToKvJob. One of: brand|affiliate|individual.
     * @param  bool  $can_submit_feedback  Always true today. The gate exists so a future
     *                                     per-user feedback ban can disable abusers without
     *                                     a controller change.
     */
    public function __construct(
        public bool $can_edit_design,
        public string $notification_categories,
        public string $worker_kv_type,
        public bool $can_submit_feedback,
    ) {}
}
