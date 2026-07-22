<?php

namespace Webkul\Measurement\Filter\Database;

use Illuminate\Support\Facades\DB;
use Webkul\ElasticSearch\Enums\FilterOperators;
use Webkul\Measurement\Helpers\MeasurementHelper;
use Webkul\Measurement\Repositories\AttributeMeasurementRepository;
use Webkul\Product\Filter\Database\AbstractDatabaseAttributeFilter;

/**
 * Measurement attribute filter for a database query.
 *
 * Measurement values are stored as { unit, amount, family, base_data, base_unit }.
 * Comparisons run against `base_data`, the amount normalised to the family's
 * standard unit, so a product saved in centimetres still matches a filter
 * expressed in metres.
 */
class MeasurementFilter extends AbstractDatabaseAttributeFilter
{
    public function __construct(
        array $supportedAttributeTypes = ['measurement'],
        array $allowedOperators = [
            FilterOperators::IN,
            FilterOperators::NOT_IN,
            FilterOperators::EQUAL,
            FilterOperators::NOT_EQUAL,
            FilterOperators::GREATER_THAN,
            FilterOperators::GREATER_THAN_OR_EQUAL,
            FilterOperators::LESS_THAN,
            FilterOperators::LESS_THAN_OR_EQUAL,
            FilterOperators::RANGE,
            FilterOperators::NOT_IN_RANGE,
        ]
    ) {
        $this->supportedAttributeTypes = $supportedAttributeTypes;
        $this->allowedOperators = $allowedOperators;
    }

    /**
     * {@inheritdoc}
     */
    public function addAttributeFilter(
        $attribute,
        $operator,
        $value,
        $locale = null,
        $channel = null,
        $options = []
    ) {
        if ($this->queryBuilder === null) {
            throw new \LogicException('The search query builder is not initialized in the filter.');
        }

        [$unit, $amounts] = $this->parseValue($value);

        if (empty($amounts) && empty($unit)) {
            return $this;
        }

        $scopedPath = $this->getScopedAttributePath($attribute, $locale, $channel);

        $grammar = DB::rawQueryGrammar();
        $tablePath = $this->getSearchTablePath($options);

        $basePath = $grammar->jsonExtract($tablePath, ...array_merge($scopedPath, ['base_data']));
        $unitPath = $grammar->jsonExtract($tablePath, ...array_merge($scopedPath, ['unit']));

        $baseColumn = "CAST($basePath AS DECIMAL(20,6))";

        $bases = $this->toBaseValues($amounts, $unit, $attribute);

        if (empty($bases)) {
            $this->queryBuilder->whereRaw("$unitPath = ?", [$unit]);

            return $this;
        }

        match ($operator) {
            FilterOperators::NOT_EQUAL             => $this->queryBuilder->whereRaw("$baseColumn <> ?", [$bases[0]]),
            FilterOperators::GREATER_THAN          => $this->queryBuilder->whereRaw("$baseColumn > ?", [$bases[0]]),
            FilterOperators::GREATER_THAN_OR_EQUAL => $this->queryBuilder->whereRaw("$baseColumn >= ?", [$bases[0]]),
            FilterOperators::LESS_THAN             => $this->queryBuilder->whereRaw("$baseColumn < ?", [$bases[0]]),
            FilterOperators::LESS_THAN_OR_EQUAL    => $this->queryBuilder->whereRaw("$baseColumn <= ?", [$bases[0]]),
            FilterOperators::RANGE                 => $this->applyRange($baseColumn, $bases, false),
            FilterOperators::NOT_IN_RANGE          => $this->applyRange($baseColumn, $bases, true),
            FilterOperators::NOT_IN                => $this->applyIn($baseColumn, $bases, true),
            FilterOperators::IN                    => $this->applyIn($baseColumn, $bases, false),
            default                                => $this->queryBuilder->whereRaw("$baseColumn = ?", [$bases[0]]),
        };

        return $this;
    }

    /**
     * Split the incoming filter value into a unit and one or more amounts.
     *
     * The datagrid sends [unit, amount] for single-value operators and
     * [unit, amountFrom, amountTo] for range operators.
     */
    protected function parseValue($value): array
    {
        if (! is_array($value)) {
            return [null, $this->cleanAmounts([$value])];
        }

        $unit = $value[0] ?? null;

        $amounts = array_slice($value, 1);

        if (count($amounts) === 1 && is_array($amounts[0])) {
            $amounts = $amounts[0];
        }

        return [$unit, $this->cleanAmounts($amounts)];
    }

    /**
     * Drop empty entries and keep only numeric amounts.
     */
    protected function cleanAmounts(array $amounts): array
    {
        return array_values(array_filter(
            $amounts,
            fn ($amount) => $amount !== null && $amount !== '' && is_numeric($amount)
        ));
    }

    /**
     * Convert each filter amount from the selected unit into the family's
     * standard unit, so it can be compared against the stored base value.
     */
    protected function toBaseValues(array $amounts, ?string $unit, $attribute): array
    {
        if (empty($amounts)) {
            return [];
        }

        if (empty($unit)) {
            return array_map(fn ($amount) => (float) $amount, $amounts);
        }

        $measurement = app(AttributeMeasurementRepository::class)->getByAttributeId($attribute->id);

        if (! $measurement || ! $measurement->family) {
            return array_map(fn ($amount) => (float) $amount, $amounts);
        }

        $helper = app(MeasurementHelper::class);

        return array_map(
            fn ($amount) => (float) $helper->calculateBaseValue($amount, $unit, $measurement->family),
            $amounts
        );
    }

    /**
     * Apply a between / not between comparison.
     */
    protected function applyRange(string $column, array $bases, bool $negate): void
    {
        if (count($bases) < 2) {
            $this->queryBuilder->whereRaw("$column ".($negate ? '<>' : '=').' ?', [$bases[0]]);

            return;
        }

        $from = min($bases[0], $bases[1]);
        $to = max($bases[0], $bases[1]);

        $this->queryBuilder->whereRaw(
            "$column ".($negate ? 'NOT BETWEEN' : 'BETWEEN').' ? AND ?',
            [$from, $to]
        );
    }

    /**
     * Apply an in / not in comparison.
     */
    protected function applyIn(string $column, array $bases, bool $negate): void
    {
        $placeholders = implode(', ', array_fill(0, count($bases), '?'));

        $this->queryBuilder->whereRaw(
            "$column ".($negate ? 'NOT IN' : 'IN')." ($placeholders)",
            $bases
        );
    }
}
