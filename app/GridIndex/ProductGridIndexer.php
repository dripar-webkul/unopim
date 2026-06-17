<?php

namespace App\GridIndex;

use Illuminate\Support\Facades\DB;

/**
 * Builds and maintains the flat `product_grid_index` rows used by the
 * product datagrid for fast (indexed) sorting / filtering / search.
 *
 * One row is produced per (channel, locale) that actually carries data in
 * a product's `values.channel_locale_specific` map (falling back to the
 * default context when a product has none), so the index only grows for
 * contexts that are really used.
 */
class ProductGridIndexer
{
    /** @var array<int, array<string,string>> family_id => [locale => name] */
    protected array $familyNames = [];

    /** @var array<int, string> family_id => code */
    protected array $familyCodes = [];

    protected bool $familyLoaded = false;

    /**
     * Rebuild the whole index. Streams products in id chunks and bulk-inserts
     * flat rows. Optionally restrict to a single locale (for testing/partial).
     */
    public function reindexAll(?string $onlyLocale = null, int $chunk = 1000, ?callable $progress = null): int
    {
        $this->loadFamilies();

        DB::table('product_grid_index')->truncate();

        $total = 0;
        $buffer = [];

        DB::table('products as p')
            ->leftJoin('products as pp', 'p.parent_id', '=', 'pp.id')
            ->select('p.id', 'p.sku', 'p.status', 'p.type', 'p.attribute_family_id', 'p.avg_completeness_score', 'p.values', 'p.created_at', 'p.updated_at', 'pp.sku as parent_sku')
            ->orderBy('p.id')
            ->chunkById($chunk, function ($products) use (&$buffer, &$total, $onlyLocale, $progress) {
                foreach ($products as $product) {
                    foreach ($this->buildRows($product, $onlyLocale) as $row) {
                        $buffer[] = $row;
                    }
                }

                if (count($buffer) >= 5000) {
                    foreach (array_chunk($buffer, 2000) as $slice) {
                        DB::table('product_grid_index')->insert($slice);
                        $total += count($slice);
                    }
                    $buffer = [];
                    if ($progress) {
                        $progress($total);
                    }
                }
            }, 'p.id', 'id');

        if (! empty($buffer)) {
            foreach (array_chunk($buffer, 2000) as $slice) {
                DB::table('product_grid_index')->insert($slice);
                $total += count($slice);
            }
            if ($progress) {
                $progress($total);
            }
        }

        return $total;
    }

    /**
     * Re-sync a single product (used by the save observer).
     */
    public function reindexProduct(int $productId): void
    {
        $this->reindexProducts([$productId]);
    }

    /**
     * Re-sync a batch of products (used by the import batch event).
     *
     * @param  array<int>  $productIds
     */
    public function reindexProducts(array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }

        $this->loadFamilies();

        $products = DB::table('products as p')
            ->leftJoin('products as pp', 'p.parent_id', '=', 'pp.id')
            ->whereIn('p.id', $productIds)
            ->select('p.id', 'p.sku', 'p.status', 'p.type', 'p.attribute_family_id', 'p.avg_completeness_score', 'p.values', 'p.created_at', 'p.updated_at', 'pp.sku as parent_sku')
            ->get();

        $rows = [];
        foreach ($products as $product) {
            foreach ($this->buildRows($product) as $row) {
                $rows[] = $row;
            }
        }

        DB::transaction(function () use ($productIds, $rows) {
            DB::table('product_grid_index')->whereIn('product_id', $productIds)->delete();

            foreach (array_chunk($rows, 2000) as $slice) {
                DB::table('product_grid_index')->insert($slice);
            }
        });
    }

    public function removeProduct(int $productId): void
    {
        DB::table('product_grid_index')->where('product_id', $productId)->delete();
    }

    /**
     * Build the flat rows for a single product row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildRows(object $product, ?string $onlyLocale = null): array
    {
        $values = is_string($product->values) ? (json_decode($product->values, true) ?: []) : (array) $product->values;

        $cls = $values['channel_locale_specific'] ?? [];

        // Contexts that actually carry data; fall back to the default context.
        $contexts = [];
        foreach ($cls as $channel => $locales) {
            foreach ((array) $locales as $locale => $_) {
                $contexts[] = [$channel, $locale];
            }
        }
        if (empty($contexts)) {
            $contexts[] = [core()->getDefaultChannelCode(), core()->getRequestedLocaleCode()];
        }

        $rows = [];
        foreach ($contexts as [$channel, $locale]) {
            if ($onlyLocale !== null && $locale !== $onlyLocale) {
                continue;
            }

            $name = $values['channel_locale_specific'][$channel][$locale]['name']
                ?? $values['locale_specific'][$locale]['name']
                ?? $values['common']['name']
                ?? null;

            $familyId = $product->attribute_family_id ? (int) $product->attribute_family_id : null;
            $familyName = $familyId ? ($this->familyNames[$familyId][$locale] ?? null) : null;
            $familyCode = $familyId ? ($this->familyCodes[$familyId] ?? null) : null;
            $familyDisplay = ($familyName !== null && trim($familyName) !== '')
                ? $familyName
                : ($familyCode ? '['.$familyCode.']' : null);

            $rows[] = [
                'product_id'          => (int) $product->id,
                'channel'             => $channel,
                'locale'              => $locale,
                'sku'                 => $product->sku,
                'name'                => $name !== null ? mb_substr((string) $name, 0, 255) : null,
                'image'               => $values['common']['image'] ?? null,
                'status'              => (int) $product->status,
                'type'                => $product->type,
                'attribute_family_id' => $familyId,
                'attribute_family'    => $familyDisplay,
                'parent_sku'          => $product->parent_sku,
                'completeness'        => $product->avg_completeness_score !== null ? (int) $product->avg_completeness_score : null,
                'created_at'          => $product->{'created_at'} ?? null,
                'updated_at'          => $product->{'updated_at'} ?? null,
            ];
        }

        return $rows;
    }

    protected function loadFamilies(): void
    {
        if ($this->familyLoaded) {
            return;
        }

        $this->familyCodes = DB::table('attribute_families')->pluck('code', 'id')->all();

        foreach (DB::table('attribute_family_translations')->select('attribute_family_id', 'locale', 'name')->get() as $t) {
            $this->familyNames[$t->attribute_family_id][$t->locale] = $t->name;
        }

        $this->familyLoaded = true;
    }
}
