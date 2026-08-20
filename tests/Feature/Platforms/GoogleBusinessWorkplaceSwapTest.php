<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;

// FI-15 (2026-08-20, T9 live): after seven listing swaps the ST. ALi
// workplace still carried Kings Domain's description — machine-sourced
// workplace fields survived the disconnect and every later listing
// inherited them. Disconnecting the listing clears fields whose
// field_sources stamp names a machine source; user-typed values stay.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('clears machine-sourced workplace fields when the Google Business listing disconnects', function () {
    $pro = createTenant('fi15-swap', ['account_type' => 'business']);
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $workplace = new Workplace([
        'site_id' => (string) $site->id,
        'description' => 'Superior Cuts, Fit for a King.',
        'previous_website' => 'https://kingsdomain.com.au/',
        'category' => 'Barber shop',
    ]);
    // field_sources is deliberately NOT fillable — system-written property.
    $workplace->field_sources = [
        'description' => ['source' => 'website-scan', 'at' => now()->toIso8601String()],
        'previous_website' => ['source' => 'google-business', 'at' => now()->toIso8601String()],
        // category typed by the USER — no machine stamp — must survive.
    ];
    $workplace->save();

    $connection = new IntegrationConnection([
        'surface_key' => 'google_business.listing', 'routing_class' => 'social',
        'resource_id' => 'google-business', 'payload' => ['name' => 'Kings Domain'], 'is_active' => true,
    ]);
    $connection->user_id = $pro->id;
    $connection->save();

    $connection->delete();

    $workplace = Workplace::query()->where('site_id', (string) $site->id)->firstOrFail();
    expect($workplace->description)->toBeNull()
        ->and($workplace->previous_website)->toBeNull()
        ->and($workplace->category)->toBe('Barber shop')
        ->and($workplace->field_sources)->not->toHaveKey('description');
});
