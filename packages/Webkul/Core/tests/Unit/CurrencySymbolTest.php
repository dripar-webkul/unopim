<?php

use Webkul\Core\Models\Currency;

afterEach(function () {
    Currency::query()->where('code', 'TEST_FAKE')->delete();
});

it('returns the DB symbol for a currency whose ICU glyph is missing (UAH)', function () {
    $currency = Currency::where('code', 'UAH')->first();

    if (! $currency) {
        $currency = Currency::create(['code' => 'UAH', 'symbol' => '₴', 'status' => 1]);
    } elseif ($currency->symbol !== '₴') {
        $currency->update(['symbol' => '₴']);
    }

    $symbol = core()->currencySymbol('UAH');

    expect($symbol)->toBe('₴');
});

it('still returns the DB symbol when a Currency model is passed directly', function () {
    $currency = Currency::firstOrNew(['code' => 'UAH']);
    $currency->fill(['symbol' => '₴', 'status' => 1])->save();

    $symbol = core()->currencySymbol($currency);

    expect($symbol)->toBe('₴');
});

it('falls back to NumberFormatter when the DB symbol is empty', function () {
    Currency::firstOrCreate(['code' => 'TEST_FAKE'], ['symbol' => null, 'status' => 0]);

    $symbol = core()->currencySymbol('USD');

    expect($symbol)->toBe('$');
});

it('does not regress for currencies that already render via ICU (EUR, USD)', function () {
    $eur = Currency::firstOrNew(['code' => 'EUR']);
    $eur->fill(['symbol' => '€', 'status' => 1])->save();

    $usd = Currency::firstOrNew(['code' => 'USD']);
    $usd->fill(['symbol' => '$', 'status' => 1])->save();

    expect(core()->currencySymbol('EUR'))->toBe('€');
    expect(core()->currencySymbol('USD'))->toBe('$');
});
