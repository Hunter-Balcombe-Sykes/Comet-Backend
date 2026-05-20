<?php

use App\Services\Professional\AccountTypeDefaultsService;

// Audit #1: the new account_type entries (individual / partner) in
// config/partna.php were written as flat dictionaries instead of inheriting
// from 'professional'. The flat shape is identical to the resolved
// 'professional' shape today, but a future edit to 'influencer' or
// 'professional' wouldn't propagate. This test catches that drift.

function expectAccountTypeMatchesProfessional(string $accountType): void
{
    $service = app(AccountTypeDefaultsService::class);
    $resolved = $service->resolveDefaults($accountType);
    $professional = $service->resolveDefaults('professional');

    expect($resolved['allowed_sections'])->toEqual($professional['allowed_sections']);
    expect($resolved['default_sections'])->toEqual($professional['default_sections']);
    expect($resolved['custom_links_allowed'])->toEqual($professional['custom_links_allowed']);
    expect($resolved['is_published'])->toEqual($professional['is_published']);
    expect($resolved['allowed_theme_count'])->toEqual($professional['allowed_theme_count']);
    expect($resolved['default_contact'])->toEqual($professional['default_contact']);
}

it('individual matches the resolved professional shape', function () {
    expectAccountTypeMatchesProfessional('individual');
});

it('partner matches the resolved professional shape', function () {
    expectAccountTypeMatchesProfessional('partner');
});
