<?php

use App\Services\Content\ManualMenuWriter;
use App\Services\Content\ManualPoolWriter;
use App\Services\Platforms\MenuProjectionMapper;

// Slice 7 unit A. The load-bearing property is that this writer derives coords
// and projections through slice 4's mapper, NOT through a second derivation —
// a second one would mint a duplicate content item for every one of the 318
// dishes the backfill already landed.

it('derives the coord slice 4 used, not a second scheme', function () {
    $writer = app(ManualMenuWriter::class);

    expect($writer->coordFor('menu-1', 'Pizza  Margherita'))
        ->toBe(MenuProjectionMapper::coordFor('menu-1', 'Pizza  Margherita'))
        ->toStartWith('manual:menu:menu-1:');
});

it('normalises the dish name into the coord so a re-scrape is idempotent', function () {
    $writer = app(ManualMenuWriter::class);

    // MenuFetchJob deletes and re-inserts every dish per scrape; the coord is
    // name-derived precisely so the same dish resolves to the same item.
    expect($writer->coordFor('menu-1', '  PIZZA   margherita '))
        ->toBe($writer->coordFor('menu-1', 'Pizza Margherita'));
});

it('scopes the coord to the menu so two vendors keep distinct items', function () {
    $writer = app(ManualMenuWriter::class);

    expect($writer->coordFor('menu-1', 'Garlic Bread'))
        ->not->toBe($writer->coordFor('menu-2', 'Garlic Bread'));
});

it('projects a dish through the slice-4 mapper', function () {
    $dish = (object) ['name' => 'Iced Latte', 'base_price' => 5.50, 'description' => 'Cold'];
    $menu = (object) ['currency' => 'AUD'];

    $projection = app(ManualMenuWriter::class)->projectionFor($dish, [], [], $menu);

    expect($projection['kind'])->toBe('menu_item')
        ->and($projection['headline'])->toBe('Iced Latte')
        ->and($projection['facets']['f_text']['body'])->toBe('Cold')
        ->and($projection['offers'][0]['channel'])->toBe('base')
        ->and($projection['offers'][0]['amount_minor'])->toBe(550)
        ->and($projection['offers'][0]['currency'])->toBe('AUD');
});

it('curates against the menus pool, not services', function () {
    // The parent class provisions curation against its constructed pool key;
    // binding it wrong would pin dishes into the services section.
    $reflection = new ReflectionClass(ManualPoolWriter::class);
    $pool = $reflection->getProperty('pool');

    expect($pool->getValue(app(ManualMenuWriter::class)))->toBe('menus');
});
