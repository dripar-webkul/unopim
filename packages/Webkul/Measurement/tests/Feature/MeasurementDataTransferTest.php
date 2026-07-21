<?php

use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\DataTransfer\Helpers\Exporters\Product\Exporter as CoreExporter;
use Webkul\DataTransfer\Helpers\Importers\Product\Importer as CoreImporter;
use Webkul\DataTransfer\Models\JobTrack;
use Webkul\Measurement\Models\AttributeMeasurement;
use Webkul\Measurement\Models\MeasurementFamily;

beforeEach(function () {
    $this->loginAsAdmin();

    foreach ([CoreImporter::class, CoreExporter::class] as $class) {
        $cache = new ReflectionProperty($class, 'staticInitCache');
        $cache->setAccessible(true);
        $cache->setValue(null, null);
    }
});

function measurementAttributeInFamily(): array
{
    $suffix = uniqid();

    $measurementFamily = MeasurementFamily::factory()->create([
        'units' => [
            ['code' => 'meter', 'labels' => ['en_US' => 'Meter']],
            ['code' => 'cm', 'labels' => ['en_US' => 'Centimeter']],
        ],
    ]);

    $measurement = Attribute::factory()->create([
        'code' => 'width_'.$suffix,
        'type' => 'measurement',
    ]);

    AttributeMeasurement::create([
        'attribute_id' => $measurement->id,
        'family_code'  => $measurementFamily->code,
        'unit_code'    => 'meter',
    ]);

    $plain = Attribute::factory()->create([
        'code' => 'note_'.$suffix,
        'type' => 'text',
    ]);

    $unitNamed = Attribute::factory()->create([
        'code' => 'pack_'.$suffix.'_unit',
        'type' => 'text',
    ]);

    $family = AttributeFamily::factory()->create();
    AttributeFamily::factory()->linkAttributeGroupToFamily($family);
    $family->refresh();
    AttributeFamily::factory()->linkAttributesToFamily($family, Attribute::whereIn('code', ['sku', 'status'])->get());
    AttributeFamily::factory()->linkAttributesToFamily($family, collect([$measurement, $plain, $unitNamed]));

    return [$family->fresh(), $measurement, $plain, $unitNamed];
}

it('imports a measurement column together with its (unit) column', function () {
    [$family, $measurement, $plain] = measurementAttributeInFamily();

    $importer = app(CoreImporter::class)->setImport(JobTrack::factory()->create());

    $rowData = [
        'sku'                              => 'measurement-import-'.uniqid(),
        'type'                             => 'simple',
        'attribute_family'                 => $family->code,
        $measurement->code                 => '12.5',
        $measurement->code.'(unit)'        => 'Centimeter',
        $plain->code                       => 'plain value',
    ];

    $attributeValues = [];

    $importer->prepareAttributeValues($rowData, $attributeValues);

    $stored = $attributeValues['common'][$measurement->code] ?? null;

    expect($stored)->toBeArray()
        ->and($stored['unit'] ?? null)->toBe('cm')
        ->and((float) ($stored['amount'] ?? $stored['value'] ?? 0))->toBe(12.5);

    expect($attributeValues['common'][$plain->code] ?? null)->toBe('plain value');

    expect($attributeValues['common'])->not->toHaveKey($measurement->code.'(unit)');
});

it('does not let the (unit) column overwrite the measurement amount', function () {
    [$family, $measurement] = measurementAttributeInFamily();

    $importer = app(CoreImporter::class)->setImport(JobTrack::factory()->create());

    $rowData = [
        'sku'                       => 'measurement-import-'.uniqid(),
        'type'                      => 'simple',
        'attribute_family'          => $family->code,
        $measurement->code          => '3',
        $measurement->code.'(unit)' => 'Meter',
    ];

    $attributeValues = [];

    $importer->prepareAttributeValues($rowData, $attributeValues);

    $stored = $attributeValues['common'][$measurement->code] ?? null;

    expect($stored)->toBeArray()
        ->and($stored['unit'] ?? null)->toBe('meter');
});

it('still imports a plain attribute whose code ends in _unit', function () {
    [$family, , , $unitNamed] = measurementAttributeInFamily();

    $importer = app(CoreImporter::class)->setImport(JobTrack::factory()->create());

    $rowData = [
        'sku'              => 'measurement-import-'.uniqid(),
        'type'             => 'simple',
        'attribute_family' => $family->code,
        $unitNamed->code   => 'box',
    ];

    $attributeValues = [];

    $importer->prepareAttributeValues($rowData, $attributeValues);

    expect($attributeValues['common'][$unitNamed->code] ?? null)->toBe('box');
});

it('exports non-measurement attribute columns alongside the measurement pair', function () {
    [, $measurement, $plain] = measurementAttributeInFamily();

    $exporter = app(CoreExporter::class);

    $reflection = new ReflectionClass($exporter);

    $attributes = $reflection->getParentClass()->getProperty('attributes');
    $attributes->setAccessible(true);
    $attributes->setValue($exporter, collect([$measurement, $plain]));

    $currencies = $reflection->getParentClass()->getProperty('currencies');
    $currencies->setAccessible(true);
    $currencies->setValue($exporter, ['USD']);

    $method = $reflection->getMethod('setAttributesValues');
    $method->setAccessible(true);

    $columns = $method->invoke($exporter, [
        $plain->code       => 'plain value',
        $measurement->code => ['amount' => '7', 'unit' => 'cm'],
    ], null);

    expect($columns[$plain->code] ?? null)->toBe('plain value')
        ->and($columns[$measurement->code] ?? null)->toBe('7')
        ->and($columns[$measurement->code.'(unit)'] ?? null)->toBe('Centimeter');
});
