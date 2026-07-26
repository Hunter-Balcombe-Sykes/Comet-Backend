<?php

namespace App\Services\V5;

use App\Models\V5\ContentPool;
use App\Models\V5\Item;
use App\Models\V5\ItemSource;
use App\Models\V5\ItemValue;
use App\Models\V5\UserPlatform;
use Illuminate\Support\Str;

// V5 ItemService — creates/merges items from scraper output and links them to content pools.
class ItemService
{
    /**
     * Ingest scraper output for a user platform. Each item is either:
     * - NEW: created and linked to the pool and source
     * - EXISTING: merged by identifier, values updated, source added
     *
     * @param UserPlatform $up The user's platform connection
     * @param string $poolName The content pool to feed into (e.g. 'music', 'watch')
     * @param array $items Array of scraper items: [{identifier, name, item_type, values: [{field_name, value, format}]}]
     * @param array $profileFields Optional profile fields to store as user_column_sources: {display_name, profile_pic_url, follower_count, bio}
     * @return array{created: int, updated: int, profile_updated: bool}
     */
    public function ingest(UserPlatform $up, string $poolName, array $items, array $profileFields = []): array
    {
        $pool = ContentPool::where('name', $poolName)->first();
        if (! $pool) {
            throw new \InvalidArgumentException("Content pool '{$poolName}' not found");
        }

        $created = 0;
        $updated = 0;

        foreach ($items as $itemData) {
            $identifier = $itemData['identifier'] ?? null;
            $name = $itemData['name'] ?? 'Untitled';
            $itemType = $itemData['item_type'] ?? 'link';

            if (! $identifier) continue;

            // Find or create the item (merge by identifier for this user)
            $item = Item::firstOrCreate(
                ['user_id' => $up->user_id, 'identifier' => $identifier],
                ['name' => $name, 'item_type' => $itemType]
            );

            $isNew = $item->wasRecentlyCreated;
            if ($isNew) $created++; else $updated++;

            // Update name if the new one is longer (better quality)
            if (! $isNew && strlen($name) > strlen($item->name ?? '')) {
                $item->update(['name' => $name]);
            }

            // Link to pool
            if (! $item->pools()->where('content_pool_id', $pool->id)->exists()) {
                $item->pools()->attach($pool->id);
            }

            // Find or create item source
            $source = ItemSource::firstOrCreate(
                ['item_id' => $item->id, 'user_platform_id' => $up->id],
                ['is_enabled' => true]
            );

            // Upsert values
            foreach ($itemData['values'] ?? [] as $v) {
                $fieldName = $v['field_name'] ?? null;
                $value = $v['value'] ?? null;
                $format = $v['format'] ?? 'text';

                if (! $fieldName || $value === null) continue;

                // Only update if not manually set
                $existing = ItemValue::where('item_id', $item->id)
                    ->where('field_name', $fieldName)
                    ->where('is_manually_set', true)
                    ->first();

                if ($existing) continue; // User has manually set this — don't overwrite

                ItemValue::updateOrCreate(
                    ['item_id' => $item->id, 'item_source_id' => $source->id, 'field_name' => $fieldName],
                    ['value' => (string) $value, 'format' => $format, 'is_resolved' => true]
                );
            }

            // Resolve values: pick the winning value for each field
            $this->resolveItem($item);
        }

        // Update profile fields on platform definition
        $profileUpdated = false;
        if (! empty($profileFields)) {
            $def = $up->platformDefinition;
            if ($def) {
                $updates = [];
                if (isset($profileFields['display_name'])) $updates['display_name'] = $profileFields['display_name'];
                if (isset($profileFields['profile_pic_url'])) $updates['profile_pic_url'] = $profileFields['profile_pic_url'];
                if (isset($profileFields['follower_count'])) $updates['follower_count'] = (int) $profileFields['follower_count'];
                if (isset($profileFields['bio'])) $updates['bio'] = $profileFields['bio'];
                if (! empty($updates)) {
                    $def->update($updates);
                    $profileUpdated = true;
                }
            }
        }

        return compact('created', 'updated', 'profileUpdated');
    }

    /**
     * Resolve which value wins for each field on an item.
     * Rule: manual > most recently updated > non-null.
     * Marks the winning value with is_resolved=true.
     */
    private function resolveItem(Item $item): void
    {
        $values = $item->values()->orderBy('field_name')->orderBy('updated_at', 'desc')->get();
        $seen = [];

        foreach ($values as $value) {
            $key = $value->field_name;
            if (isset($seen[$key])) continue; // Already have a winner (first = most recent)
            if ($value->is_manually_set) {
                $seen[$key] = $value->id;
            } elseif ($value->value !== null && $value->value !== '') {
                $seen[$key] = $value->id;
            }
        }

        // Mark resolved
        ItemValue::where('item_id', $item->id)->update(['is_resolved' => false]);
        foreach ($seen as $valueId) {
            ItemValue::where('id', $valueId)->update(['is_resolved' => true]);
        }

        // Build resolved_values JSON cache
        $resolved = ItemValue::where('item_id', $item->id)
            ->where('is_resolved', true)
            ->pluck('value', 'field_name')
            ->toArray();

        $item->update(['resolved_values' => $resolved]);
    }
}
