<?php

namespace App\Services\V5\Router;

// V5 RouterResult — the determination returned by V5Router::determine().
// Immutable value object.
class RouterResult
{
    /**
     * @param string $action What the user should do: 'connect_platform', 'add_item',
     *                       'add_as_other', 'add_as_link', 'try_again', 'suggestion_gate',
     *                       'invalid_for_platform'
     * @param array|null $platform The matched or selected platform config
     * @param array|null $item The matched item template
     * @param string|null $inputUrl The original URL the user entered
     * @param array $suggestions Suggested next actions [{action, label, platform?}]
     * @param string|null $categoryName The category context
     */
    private function __construct(
        public readonly string $action,
        public readonly ?array $platform = null,
        public readonly ?array $item = null,
        public readonly ?string $inputUrl = null,
        public readonly array $suggestions = [],
        public readonly ?string $categoryName = null,
    ) {}

    // Factory methods for each router outcome

    public static function platformMatch(array $match): self
    {
        return new self(
            action: 'connect_platform',
            platform: $match['platform'],
            inputUrl: $match['matched_value'] ?? null,
        );
    }

    public static function itemMatch(array $match): self
    {
        return new self(
            action: $match['is_platform_syncable'] ? 'connect_platform_and_add_item' : 'add_item',
            item: [
                'template' => $match['template'] ?? null,
                'item_type' => $match['item_type'] ?? null,
                'is_platform_syncable' => $match['is_platform_syncable'] ?? false,
                'source_method' => $match['source_method'] ?? null,
            ],
            platform: $match['is_platform_syncable'] ? ($match['platform'] ?? null) : null,
            inputUrl: $match['matched_value'] ?? null,
        );
    }

    public static function unrecognized(string $url): self
    {
        return new self(
            action: 'unrecognized',
            inputUrl: $url,
            suggestions: [
                ['action' => 'try_again', 'label' => 'Try again'],
                ['action' => 'add_as_link', 'label' => 'Add as a link'],
                ['action' => 'add_as_other', 'label' => 'Add as Other in a category'],
            ],
        );
    }

    public static function unrecognizedInCategory(string $url, string $categoryName): self
    {
        return new self(
            action: 'unrecognized',
            inputUrl: $url,
            categoryName: $categoryName,
            suggestions: [
                ['action' => 'add_as_other', 'label' => "Add as Other in {$categoryName}"],
                ['action' => 'try_again', 'label' => 'Try again'],
            ],
        );
    }

    public static function platformInOtherCategory(array $match, string $selectedCategory): self
    {
        $otherCategory = $match['platform']['primary_category'] ?? 'other';
        return new self(
            action: 'suggestion_gate',
            platform: $match['platform'],
            inputUrl: $match['matched_value'] ?? null,
            categoryName: $selectedCategory,
            suggestions: [
                ['action' => 'connect_in_other_category', 'label' => "Connect as {$otherCategory} platform", 'platform' => $match['platform']],
                ['action' => 'add_as_other', 'label' => "Add as Other in {$selectedCategory}"],
            ],
        );
    }

    public static function suggestionGate(string $url, array $selectedPlatform, array $matchedPlatform, string $categoryName): self
    {
        return new self(
            action: 'suggestion_gate',
            platform: $selectedPlatform,
            inputUrl: $url,
            categoryName: $categoryName,
            suggestions: [
                ['action' => 'connect_matched_platform', 'label' => 'Connect to '.($matchedPlatform['name'] ?? 'matched platform'), 'platform' => $matchedPlatform],
                ['action' => 'add_as_other', 'label' => "Add as Other in {$categoryName}"],
            ],
        );
    }

    public static function otherMatch(array $platform, string $url): self
    {
        return new self(
            action: 'connect_as_other',
            platform: $platform,
            inputUrl: $url,
        );
    }

    public static function invalidForPlatform(string $url, array $platform): self
    {
        $categoryName = $platform['category_names'][0] ?? 'other';
        return new self(
            action: 'invalid_for_platform',
            platform: $platform,
            inputUrl: $url,
            categoryName: $categoryName,
            suggestions: [
                ['action' => 'try_again', 'label' => 'Try again'],
                ['action' => 'add_as_link', 'label' => 'Add as a link'],
                ['action' => 'add_as_other', 'label' => "Add as Other in {$categoryName}"],
            ],
        );
    }

    // Helpers for the frontend

    public function isSuccess(): bool
    {
        return in_array($this->action, ['connect_platform', 'connect_platform_and_add_item', 'add_item', 'connect_as_other']);
    }

    public function isSuggestion(): bool
    {
        return $this->action === 'suggestion_gate';
    }

    public function needsUserChoice(): bool
    {
        return in_array($this->action, ['unrecognized', 'suggestion_gate', 'invalid_for_platform']);
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'platform' => $this->platform,
            'item' => $this->item,
            'input_url' => $this->inputUrl,
            'suggestions' => $this->suggestions,
            'category_name' => $this->categoryName,
        ];
    }
}
