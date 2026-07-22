<?php

use Webkul\Attribute\Models\Attribute;
use Webkul\Measurement\Models\AttributeMeasurement;
use Webkul\Measurement\Models\MeasurementFamily;
use Webkul\Product\Models\Product;

beforeEach(function () {
    $this->loginAsAdmin();
});

function measurementAttribute(): Attribute
{
    $suffix = uniqid();

    $family = MeasurementFamily::factory()->create([
        'units' => [
            ['code' => 'meter', 'labels' => ['en_US' => 'Meter']],
            ['code' => 'cm', 'labels' => ['en_US' => 'Centimeter']],
        ],
    ]);

    $attribute = Attribute::factory()->create([
        'code' => 'depth_'.$suffix,
        'type' => 'measurement',
    ]);

    AttributeMeasurement::create([
        'attribute_id' => $attribute->id,
        'family_code'  => $family->code,
        'unit_code'    => 'meter',
    ]);

    return $attribute;
}

it('renders the bulk edit page with the measurement spreadsheet component', function () {
    $attribute = measurementAttribute();

    $product = Product::factory()->withInitialValues()->create();

    $this->withSession([
        'bulk_edit_product_ids'   => [$product->id],
        'bulk_edit_attribute_ids' => [$attribute->id],
    ])
        ->get(route('admin.catalog.products.bulkedit'))
        ->assertOk()
        ->assertSee('v-spreadsheet-measurement-template', false)
        ->assertSee("case 'measurement': return 'v-spreadsheet-measurement';", false);
});

it('renders the product datagrid with the measurement filter component', function () {
    measurementAttribute();

    $this->get(route('admin.catalog.products.index'))
        ->assertOk()
        ->assertSee('v-measurement-filter-template', false)
        ->assertSee("column.type === 'measurement'", false);
});

it('injects the measurement panel into the attribute edit page', function () {
    $attribute = measurementAttribute();

    $this->get(route('admin.catalog.attributes.edit', $attribute->id))
        ->assertOk();
});

it('renders the measurement family index page', function () {
    $this->get(route('admin.measurement.families.index'))->assertOk();
});

it('renders the comparison operator dropdown in the measurement filter', function () {
    measurementAttribute();

    $this->get(route('admin.catalog.products.index'))
        ->assertOk()
        ->assertSee('operatorOptions', false)
        ->assertSee("value: 'within_range'", false)
        ->assertSee("value: 'gte'", false)
        ->assertSee('isRange', false);
});

it('shows the measurement precision settings on the admin configuration screen', function () {
    $response = $this->get(route('admin.configuration.edit', ['slug' => 'catalog', 'slug2' => 'measurement']));

    $response->assertOk()
        ->assertSee(trans('measurement::app.config.catalog.measurement.precision.strategy-round'), false)
        ->assertSee(trans('measurement::app.config.catalog.measurement.precision.strategy-trim'), false)
        ->assertSee(trans('measurement::app.config.catalog.measurement.precision.title'), false)
        ->assertSee('strategy', false)
        ->assertSee('amount', false)
        ->assertSee('base', false);
});
