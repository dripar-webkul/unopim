<?php

namespace Webkul\Product\Presenters;

use Webkul\HistoryControl\Presenters\JsonDataPresenter;

class ProductValuesPresenter extends JsonDataPresenter
{
    public static $sections = [
        'locale_specific',
        'channel_specific',
        'channel_locale_specific',
        'associations',
    ];

    public static $otherSections = [
        'categories',
    ];

    /**
     * Represent Value For History Method
     */
    public static function representValueForHistory(mixed $oldValues, mixed $newValues, string $fieldName): array
    {
        $oldArray = is_string($oldValues) ? json_decode($oldValues, true) : [];
        $newArray = is_string($newValues) ? json_decode($newValues, true) : [];

        $normalizedData = [];
        $changes = [];

        if (isset($oldArray['common']) || isset($newArray['common'])) {
            $changes += self::compareCommonFields($oldArray['common'] ?? [], $newArray['common'] ?? []);
        }

        if (isset($oldArray['categories']) || isset($newArray['categories'])) {
            $changes += self::compareCategoriesFields($oldArray['categories'] ?? [], $newArray['categories'] ?? []);
        }

        if (isset($oldArray['channel_specific']) || isset($newArray['channel_specific'])) {
            $changes += self::compareChannelSpecificFields($oldArray['channel_specific'] ?? [], $newArray['channel_specific'] ?? []);
        }

        if (isset($oldArray['channel_locale_specific']) || isset($newArray['channel_locale_specific'])) {
            $changes += self::compareChannelLocaleSpecificFields($oldArray['channel_locale_specific'] ?? [], $newArray['channel_locale_specific'] ?? []);
        }

        foreach ($changes as $field => $change) {
            $normalizedData[$field] = [
                'name' => $field,
                'old'  => $change['old'] ?? '',
                'new'  => $change['new'] ?? '',
            ];
        }

        return $normalizedData;
    }

    private static function compareCommonFields(array $old, array $new): array
    {
        $changes = [];
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($fields as $field) {
            $oldValue = $old[$field] ?? '';
            $newValue = $new[$field] ?? '';

            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    private static function compareCategoriesFields(array $old, array $new): array
    {
        $oldCategories = implode(', ', $old);
        $newCategories = implode(', ', $new);

        if ($oldCategories !== $newCategories) {
            return ['categories' => [
                'old' => $oldCategories,
                'new' => $newCategories,
            ]];
        }

        return [];
    }

    private static function compareChannelSpecificFields(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $channel => $channelData) {
            foreach ($channelData as $field => $values) {
                if (is_array($values)) {
                    foreach ($values as $currency => $value) {
                        $oldValue = $old[$channel][$field][$currency] ?? '';
                        if ($oldValue !== $value) {
                            $changes["{$field} ({$currency}) - {$channel}"] = [
                                'old' => $oldValue,
                                'new' => $value,
                            ];
                        }
                    }
                }
            }
        }

        return $changes;
    }

    private static function compareChannelLocaleSpecificFields(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $channel => $locales) {
            foreach ($locales as $locale => $fields) {
                foreach ($fields as $field => $value) {
                    if (is_array($value)) {

                        foreach ($value as $currency => $amount) {
                            $oldValue = $old[$channel][$locale][$field][$currency] ?? '';
                            if ($oldValue !== $amount) {
                                $changes["{$field} ({$currency}) - {$channel}/{$locale}"] = [
                                    'old' => $oldValue,
                                    'new' => $amount,
                                ];
                            }
                        }
                    } else {
                        $oldValue = $old[$channel][$locale][$field] ?? '';
                        if ($oldValue !== $value) {
                            $changes["{$field} - {$channel}/{$locale}"] = [
                                'old' => $oldValue,
                                'new' => $value,
                            ];
                        }
                    }
                }
            }
        }

        return $changes;
    }
}
