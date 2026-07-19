<?php

use App\Services\WebsiteScan\MenuTextExtractor;

it('extracts menu items from a single-section schema.org Menu', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {
      "@context": "https://schema.org", "@type": "Menu",
      "hasMenuSection": [{
        "name": "Mains",
        "hasMenuItem": [
          {"name": "Margherita", "description": "Tomato, mozzarella, basil.", "offers": {"price": "18.00"}},
          {"name": "Carbonara", "offers": {"price": 20}}
        ]
      }]
    }
    </script>
    HTML;

    $items = app(MenuTextExtractor::class)->extract($html, 'https://venue.example');

    expect($items)->toHaveCount(2);
    expect($items[0])->toBe(['name' => 'Margherita', 'description' => 'Tomato, mozzarella, basil.', 'price' => 18.0, 'category' => 'Mains']);
    expect($items[1])->toBe(['name' => 'Carbonara', 'description' => null, 'price' => 20.0, 'category' => 'Mains']);
});

it('extracts items across multiple sections, tagging each with its own section name', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Menu", "hasMenuSection": [
      {"name": "Starters", "hasMenuItem": [{"name": "Garlic Bread", "offers": {"price": "8"}}]},
      {"name": "Mains", "hasMenuItem": [{"name": "Steak", "offers": {"price": "35"}}]}
    ]}
    </script>
    HTML;

    $items = app(MenuTextExtractor::class)->extract($html, 'https://venue.example');

    expect(collect($items)->pluck('category')->all())->toBe(['Starters', 'Mains']);
});

it('walks nested hasMenuSection subsections', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Menu", "hasMenuSection": [
      {"name": "Food", "hasMenuSection": [
        {"name": "Pizza", "hasMenuItem": [{"name": "Margherita", "offers": {"price": "18"}}]}
      ]}
    ]}
    </script>
    HTML;

    $items = app(MenuTextExtractor::class)->extract($html, 'https://venue.example');

    expect($items)->toHaveCount(1);
    expect($items[0]['name'])->toBe('Margherita');
    expect($items[0]['category'])->toBe('Pizza');
});

it('handles a menu item with no offers/price', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Menu", "hasMenuSection": [{"name": "Specials", "hasMenuItem": [{"name": "Ask your server"}]}]}
    </script>
    HTML;

    $items = app(MenuTextExtractor::class)->extract($html, 'https://venue.example');

    expect($items[0])->toBe(['name' => 'Ask your server', 'description' => null, 'price' => null, 'category' => 'Specials']);
});

it('skips a menu item with no name', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Menu", "hasMenuSection": [{"name": "Mains", "hasMenuItem": [{"description": "no name here"}]}]}
    </script>
    HTML;

    expect(app(MenuTextExtractor::class)->extract($html, 'https://venue.example'))->toBe([]);
});

it('returns an empty array when there is no Menu JSON-LD at all', function () {
    expect(app(MenuTextExtractor::class)->extract('<html><body>No menu here</body></html>', 'https://venue.example'))->toBe([]);
});

it('offers as a list picks the first numeric price', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Menu", "hasMenuSection": [{"name": "Mains", "hasMenuItem": [
      {"name": "Pasta", "offers": [{"price": "16"}, {"price": "22"}]}
    ]}]}
    </script>
    HTML;

    $items = app(MenuTextExtractor::class)->extract($html, 'https://venue.example');

    expect($items[0]['price'])->toBe(16.0);
});
