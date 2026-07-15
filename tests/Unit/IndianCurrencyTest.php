<?php

use App\Support\IndianCurrency;

test('spells amounts in the Indian system', function (float $amount, string $expected) {
    expect(IndianCurrency::words($amount))->toBe($expected);
})->with([
    [0.0, 'Zero Rupees Only'],
    [1.0, 'One Rupee Only'],
    [51254.0, 'Fifty One Thousand Two Hundred Fifty Four Rupees Only'],
    [100000.0, 'One Lakh Rupees Only'],
    [123315.0, 'One Lakh Twenty Three Thousand Three Hundred Fifteen Rupees Only'],
    [12500000.0, 'One Crore Twenty Five Lakh Rupees Only'],
    [1250.50, 'One Thousand Two Hundred Fifty Rupees and Fifty Paise Only'],
    [0.75, 'Seventy Five Paise Only'],
]);

test('groups digits in the Indian 2,2,3 pattern', function (float $amount, string $expected) {
    expect(IndianCurrency::format($amount))->toBe($expected);
})->with([
    [125000.0, '1,25,000.00'],
    [51254.0, '51,254.00'],
    [12500000.0, '1,25,00,000.00'],
    [999.0, '999.00'],
    [1000.0, '1,000.00'],
    [-45000.5, '-45,000.50'],
    [0.0, '0.00'],
]);
