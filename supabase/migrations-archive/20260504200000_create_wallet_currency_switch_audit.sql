-- Audit trail for wallet currency switches. A switch is financially meaningful:
-- it resets the wallet denomination on an empty balance, so any future top-up
-- or payout operates in the new currency. Rows are append-only; no updates.

create table core.wallet_currency_switch_audit (
    id               uuid        primary key default gen_random_uuid(),
    professional_id  uuid        not null references core.professionals(id) on delete cascade,
    previous_currency char(3)    not null,
    new_currency      char(3)    not null,
    actor_type        text        not null,
    actor_id          uuid,
    topup_id          uuid,       -- BrandCommissionTopup that triggered the switch
    metadata          jsonb,
    created_at        timestamptz not null default now()
);

create index wallet_currency_switch_audit_professional_idx
    on core.wallet_currency_switch_audit (professional_id, created_at desc);
