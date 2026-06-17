<?php

namespace App\GridIndex;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\DataGrids\Catalog\ProductDataGrid;

/**
 * Drop-in override of the core ProductDataGrid that serves the listing from
 * the flat `product_grid_index` table (small rows, fully indexed) instead of
 * full-scanning + filesorting the bloated `products.values` JSON.
 *
 * For the default columns (sku, image, name, family, status, type,
 * completeness) every value lives in the flat row, so the query touches
 * ONLY `product_grid_index` — no products join, no 17KB JSON decode → ms.
 * The products table is joined lazily only when a visible managed attribute
 * column, or an attribute-value sort/filter, actually needs the raw JSON.
 *
 * When the flat index has no rows for the current channel/locale it falls
 * back to the parent's original query. Only the DB read path changes.
 */
class FlatProductDataGrid extends ProductDataGrid
{
    protected bool $usingFlat = false;

    protected bool $productsJoined = false;

    /** Grid column index => flat (pgi) column, used for sort + simple filters. */
    protected array $flatColumnMap = [
        'updated_at'       => 'pgi.updated_at',
        'created_at'       => 'pgi.created_at',
        'sku'              => 'pgi.sku',
        'name'             => 'pgi.name',
        'status'           => 'pgi.status',
        'type'             => 'pgi.type',
        'completeness'     => 'pgi.completeness',
        'attribute_family' => 'pgi.attribute_family',
        'product_id'       => 'pgi.product_id',
        'parent'           => 'pgi.parent_sku',
    ];

    public function prepareQueryBuilder()
    {
        $channel = core()->getRequestedChannelCode();
        $locale = core()->getRequestedLocaleCode();

        $hasFlat = DB::table('product_grid_index')
            ->where('channel', $channel)
            ->where('locale', $locale)
            ->limit(1)
            ->exists();

        if (! $hasFlat) {
            $this->usingFlat = false;

            return parent::prepareQueryBuilder();
        }

        $this->usingFlat = true;

        $queryBuilder = DB::table('product_grid_index as pgi')
            ->where('pgi.channel', $channel)
            ->where('pgi.locale', $locale)
            ->select(
                'pgi.product_id as product_id',
                'pgi.sku as sku',
                'pgi.status as status',
                'pgi.type as type',
                'pgi.created_at as created_at',
                'pgi.updated_at as updated_at',
                'pgi.parent_sku as parent',
                'pgi.attribute_family as attribute_family',
                'pgi.name as name',
                'pgi.image as image',
                'pgi.completeness as completeness'
            );

        $this->queryBuilder = $queryBuilder;

        // Join products + raw JSON only if a visible managed attribute column
        // (beyond name/image, which the flat row already has) needs it.
        if (! empty($this->visibleManagedAttributeCodes())) {
            $this->ensureProductsJoined();
        }

        return $this->queryBuilder;
    }

    /**
     * Lazily join the products table and expose raw_values for the rare paths
     * that need the original JSON (managed attribute columns, attribute-value
     * sort/filter).
     */
    protected function ensureProductsJoined(): void
    {
        if ($this->productsJoined) {
            return;
        }

        $prefix = DB::getTablePrefix();

        $this->queryBuilder
            ->join('products', 'products.id', '=', 'pgi.product_id')
            ->leftJoin('products as parent_products', 'products.parent_id', '=', 'parent_products.id')
            ->addSelect(DB::raw("COALESCE({$prefix}products.`values`, {$prefix}parent_products.`values`) as raw_values"));

        $this->productsJoined = true;
    }

    /**
     * Visible attribute columns other than name/image (the ones the flat row
     * cannot serve and which therefore require the raw JSON).
     *
     * @return array<int, string>
     */
    protected function visibleManagedAttributeCodes(): array
    {
        $codes = [];

        foreach ($this->columns as $column) {
            if (
                isset($this->attributeColumns[$column->index])
                && ($column->visible ?? true)
                && ! in_array($column->index, ['name', 'image'], true)
            ) {
                $codes[] = $column->index;
            }
        }

        return $codes;
    }

    /**
     * {@inheritdoc}
     */
    public function processRequestedSorting($requestedSort)
    {
        if (! $this->usingFlat) {
            return parent::processRequestedSorting($requestedSort);
        }

        $requested = $requestedSort['column'] ?? $this->sortColumn;
        $requested = $requested === 'products.updated_at' ? 'updated_at' : $requested;
        $order = strtolower($requestedSort['order'] ?? $this->sortOrder) === 'asc' ? 'asc' : 'desc';

        if (isset($this->flatColumnMap[$requested])) {
            return $this->queryBuilder->orderBy($this->flatColumnMap[$requested], $order);
        }

        // Managed attribute column not in the flat index: sort on the JSON value.
        if ($path = $this->getAttributePathForSort($requested)) {
            $this->ensureProductsJoined();
            $expr = DB::rawQueryGrammar()->jsonExtract(DB::getTablePrefix().'products.values', ...$path);

            return $this->queryBuilder->orderByRaw("LOWER($expr) $order");
        }

        return $this->queryBuilder->orderBy('pgi.updated_at', $order);
    }

    /**
     * {@inheritdoc}
     */
    public function processRequestedFilters(array $requestedFilters)
    {
        if (! $this->usingFlat) {
            return parent::processRequestedFilters($requestedFilters);
        }

        foreach ($requestedFilters as $column => $values) {
            if (in_array($column, ['channel', 'locale'], true)) {
                continue;
            }

            $values = (array) $values;

            if ($column === 'all') {
                $this->queryBuilder->where(function ($q) use ($values) {
                    foreach ($values as $v) {
                        $q->orWhere('pgi.sku', 'LIKE', '%'.$v.'%')
                            ->orWhere('pgi.name', 'LIKE', '%'.$v.'%');
                    }
                });

                continue;
            }

            switch ($column) {
                case 'sku':
                case 'name':
                case 'parent':
                    $flat = $this->flatColumnMap[$column];
                    $this->queryBuilder->where(function ($q) use ($flat, $values) {
                        foreach ($values as $v) {
                            $q->orWhere($flat, 'LIKE', '%'.$v.'%');
                        }
                    });
                    break;

                case 'status':
                    $this->queryBuilder->whereIn('pgi.status', array_map('intval', $values));
                    break;

                case 'type':
                    $this->queryBuilder->whereIn('pgi.type', $values);
                    break;

                case 'product_id':
                    $this->queryBuilder->whereIn('pgi.product_id', $values);
                    break;

                case 'attribute_family':
                    $this->queryBuilder->whereIn('pgi.attribute_family_id', $values);
                    break;

                case 'created_at':
                case 'updated_at':
                    foreach ($values as $range) {
                        $this->queryBuilder->whereBetween('pgi.'.$column, [
                            ($range[0] ?? '').' 00:00:01',
                            ($range[1] ?? '').' 23:59:59',
                        ]);
                    }
                    break;

                default:
                    $this->applyJsonAttributeFilter($column, $values);
                    break;
            }
        }

        return $this->queryBuilder;
    }

    /**
     * Filter on an attribute value stored in products.values JSON, using the
     * attribute's scope for the current channel/locale.
     */
    protected function applyJsonAttributeFilter(string $code, array $values): void
    {
        if (! ($path = $this->getAttributePathForSort($code))) {
            return;
        }

        $this->ensureProductsJoined();
        $expr = DB::rawQueryGrammar()->jsonExtract(DB::getTablePrefix().'products.values', ...$path);

        $this->queryBuilder->where(function ($q) use ($expr, $values) {
            foreach ($values as $v) {
                $q->orWhereRaw("$expr = ?", [$v]);
            }
        });
    }

    /**
     * {@inheritdoc}
     *
     * Serve name/image straight from the flat row; only decode the raw JSON
     * for visible managed attribute columns (which forced the products join).
     */
    protected function processRawValues(object &$record): void
    {
        if (! $this->usingFlat) {
            parent::processRawValues($record);

            return;
        }

        if (empty($this->attributeColumns)) {
            return;
        }

        $flatServed = [
            'name'  => $record->name ?? null,
            'image' => $record->image ?? null,
        ];

        $managed = $this->visibleManagedAttributeCodes();

        $values = [];
        if (! empty($managed) && ! empty($record->raw_values)) {
            $rawValues = json_decode($record->raw_values, true);
            $values = $this->attributeValueNormalizer->normalize($rawValues, [
                'locale'                 => core()->getRequestedLocaleCode(),
                'channel'                => core()->getRequestedChannelCode(),
                'format'                 => 'datagrid',
                'processed_on_attribute' => true,
                'attribute_codes'        => $managed,
            ]);
        }

        if (property_exists($record, 'raw_values')) {
            unset($record->raw_values);
        }

        foreach ($this->columns as $column) {
            $code = $column->index;

            if (! isset($this->attributeColumns[$code])) {
                continue;
            }

            $value = array_key_exists($code, $flatServed)
                ? $flatServed[$code]
                : ($values[$code] ?? null);

            if ($closure = $column->closure) {
                $record->{$code} = $closure($value, $record);

                continue;
            }

            $attribute = $this->attributeService->findAttributeByCode($code);

            if ($this->isSwatchAttribute($attribute)) {
                $record->{$code} = $this->processSwatchAttribute($attribute, $value, $record, $code);

                continue;
            }

            $record->{$code} = $value;
        }
    }
}
