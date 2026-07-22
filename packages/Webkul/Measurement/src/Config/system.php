<?php

return [
    [
        'key'  => 'catalog',
        'name' => 'measurement::app.config.catalog.title',
        'info' => 'measurement::app.config.catalog.info',
        'sort' => 2,
    ], [
        'key'  => 'catalog.measurement',
        'name' => 'measurement::app.config.catalog.measurement.title',
        'info' => 'measurement::app.config.catalog.measurement.info',
        'sort' => 1,
    ], [
        'key'    => 'catalog.measurement.precision',
        'name'   => 'measurement::app.config.catalog.measurement.precision.title',
        'info'   => 'measurement::app.config.catalog.measurement.precision.info',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'strategy',
                'title'         => 'measurement::app.config.catalog.measurement.precision.strategy',
                'type'          => 'blade',
                'path'          => 'measurement::configuration.field.precision-strategy',
                'info'          => 'measurement::app.config.catalog.measurement.precision.strategy-info',
                'default_value' => 'round',
            ], [
                'name'          => 'amount',
                'title'         => 'measurement::app.config.catalog.measurement.precision.amount',
                'type'          => 'number',
                'info'          => 'measurement::app.config.catalog.measurement.precision.amount-info',
                'default_value' => '4',
            ], [
                'name'          => 'base',
                'title'         => 'measurement::app.config.catalog.measurement.precision.base',
                'type'          => 'number',
                'info'          => 'measurement::app.config.catalog.measurement.precision.base-info',
                'default_value' => '6',
            ],
        ],
    ],
];
