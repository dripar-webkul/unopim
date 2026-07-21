<?php

namespace Webkul\Measurement\Helpers\Exporters;

use Webkul\DataTransfer\Helpers\Exporters\Product\Exporter as CoreExporter;
use Webkul\DataTransfer\Helpers\Formatters\EscapeFormulaOperators;
use Webkul\Measurement\Helpers\MeasurementHelper;

class ProductExporter extends CoreExporter
{
    /**
     * Prepare product attribute values for export.
     *
     * Measurement attributes are withheld from the parent so it emits an empty
     * placeholder column in its usual position, then filled in here as the
     * amount plus a companion "<code>(unit)" column holding the unit label.
     *
     * @return array
     */
    protected function setAttributesValues(array $values, mixed $filePath)
    {
        $measurementAttributes = $this->attributes->where('type', 'measurement');

        $attributeValues = parent::setAttributesValues(
            array_diff_key($values, $measurementAttributes->pluck('code', 'code')->all()),
            $filePath
        );

        foreach ($measurementAttributes as $attribute) {
            $code = $attribute->code;

            [$amount, $unit] = $this->extractMeasurement($values[$code] ?? null);

            $attributeValues[$code] = EscapeFormulaOperators::escapeValue($amount);

            $attributeValues["{$code}(unit)"] = EscapeFormulaOperators::escapeValue(
                app(MeasurementHelper::class)->getUnitLabel($unit, $attribute)
            );
        }

        return $attributeValues;
    }

    /**
     * Resolve the amount and unit from a stored measurement value.
     */
    protected function extractMeasurement(mixed $value): array
    {
        $data = is_array($value) ? $value : [];

        if (isset($data['<all_channels>']['<all_locales>'])) {
            $data = $data['<all_channels>']['<all_locales>'];
        }

        return [
            $data['amount'] ?? null,
            $data['unit'] ?? null,
        ];
    }
}
