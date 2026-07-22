<?php

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\Attribute;
use Webkul\ElasticSearch\Enums\FilterOperators;
use Webkul\Measurement\Filter\Database\MeasurementFilter;
use Webkul\Measurement\Models\AttributeMeasurement;
use Webkul\Measurement\Models\MeasurementFamily;

beforeEach(function () {
    $this->loginAsAdmin();
});

function filterAttribute(): Attribute
{
    $suffix = uniqid();

    $family = MeasurementFamily::factory()->create([
        'standard_unit' => 'meter',
        'units'         => [
            ['code' => 'meter', 'labels' => ['en_US' => 'Meter'], 'convert_from_standard' => [['operator' => 'mul', 'value' => '1']]],
            ['code' => 'cm', 'labels' => ['en_US' => 'Centimeter'], 'convert_from_standard' => [['operator' => 'mul', 'value' => '100']]],
        ],
    ]);

    $attribute = Attribute::factory()->create([
        'code' => 'len_'.$suffix,
        'type' => 'measurement',
    ]);

    AttributeMeasurement::create([
        'attribute_id' => $attribute->id,
        'family_code'  => $family->code,
        'unit_code'    => 'meter',
    ]);

    return $attribute;
}

function filterSql(Attribute $attribute, $operator, array $value): array
{
    $filter = new MeasurementFilter;
    $qb = DB::table('products');
    $filter->setQueryManager($qb);
    $filter->addAttributeFilter($attribute, $operator, $value);

    return [$qb->toSql(), $qb->getBindings()];
}

it('emits a distinct comparison for each supported operator', function () {
    $attribute = filterAttribute();

    $cases = [
        [FilterOperators::EQUAL, '='],
        [FilterOperators::NOT_EQUAL, '<>'],
        [FilterOperators::GREATER_THAN, '>'],
        [FilterOperators::GREATER_THAN_OR_EQUAL, '>='],
        [FilterOperators::LESS_THAN, '<'],
        [FilterOperators::LESS_THAN_OR_EQUAL, '<='],
    ];

    foreach ($cases as [$operator, $sqlOperator]) {
        [$sql] = filterSql($attribute, $operator, ['meter', '3']);

        expect($sql)->toContain($sqlOperator)
            ->and($sql)->toContain('base_data');
    }
});

it('uses BETWEEN for range and NOT BETWEEN for its inverse', function () {
    $attribute = filterAttribute();

    [$sql] = filterSql($attribute, FilterOperators::RANGE, ['meter', '2', '5']);
    expect($sql)->toContain('BETWEEN')->and($sql)->not->toContain('NOT BETWEEN');

    [$sql] = filterSql($attribute, FilterOperators::NOT_IN_RANGE, ['meter', '2', '5']);
    expect($sql)->toContain('NOT BETWEEN');
});

it('uses IN and NOT IN for list operators', function () {
    $attribute = filterAttribute();

    [$sql, $bindings] = filterSql($attribute, FilterOperators::IN, ['meter', '1', '2', '3']);
    expect($sql)->toContain('IN (?, ?, ?)')->and($bindings)->toHaveCount(3);

    [$sql] = filterSql($attribute, FilterOperators::NOT_IN, ['meter', '1', '2']);
    expect($sql)->toContain('NOT IN');
});

it('converts the filter amount into the standard unit before comparing', function () {
    $attribute = filterAttribute();

    [, $bindings] = filterSql($attribute, FilterOperators::GREATER_THAN, ['cm', '500']);

    expect($bindings[0])->toBe(5.0);
});

it('orders range bounds regardless of the order supplied', function () {
    $attribute = filterAttribute();

    [, $bindings] = filterSql($attribute, FilterOperators::RANGE, ['meter', '9', '2']);

    expect($bindings[0])->toBe(2.0)->and($bindings[1])->toBe(9.0);
});

it('falls back to equality for an unknown operator', function () {
    $attribute = filterAttribute();

    [$sql] = filterSql($attribute, FilterOperators::WILDCARD, ['meter', '3']);

    expect($sql)->toContain('=');
});

it('filters on the unit alone when no amount is supplied', function () {
    $attribute = filterAttribute();

    [$sql, $bindings] = filterSql($attribute, FilterOperators::EQUAL, ['meter']);

    expect($sql)->toContain('unit')->and($bindings)->toBe(['meter']);
});
