<?php

use App\Support\Nano;

it('parses decimal strings into exact integer nanodollars without floats', function (string $input, int $expected) {
    expect(Nano::fromDecimalString($input))->toBe($expected);
})->with([
    'exact 9 decimals from docs' => ['0.046125000', 46_125_000],
    'zero' => ['0', 0],
    'zero with decimals' => ['0.000000000', 0],
    'one dollar' => ['1', 1_000_000_000],
    'rate with two decimals' => ['12.50', 12_500_000_000],
    'single nanodollar' => ['0.000000001', 1],
    'max Cost example' => ['0.373875', 373_875_000],
]);

it('rejects malformed decimal strings', function (string $invalid) {
    expect(fn () => Nano::fromDecimalString($invalid))->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'negative value' => ['-0.05'],
    'plus prefix' => ['+1.00'],
    'leading dot' => ['.5'],
    'trailing dot' => ['5.'],
    'more than 9 decimals' => ['0.0000000001'],
    'scientific notation' => ['1e-5'],
    'currency symbol' => ['$1.00'],
    'whitespace' => [' 1.00 '],
    'non-numeric' => ['abc'],
]);

it('converts integer nanodollars to fixed-scale decimal strings', function () {
    expect(Nano::toDecimalString(46_125_000))->toBe('0.046125000')
        ->and(Nano::toDecimalString(46_125_000, 6))->toBe('0.046125')
        ->and(Nano::toDecimalString(1_500_000_000, 2))->toBe('1.50')
        ->and(Nano::toDecimalString(2_000_000_000, 0))->toBe('2')
        ->and(Nano::toDecimalString(0))->toBe('0.000000000');
});

it('formats nanodollars for display with $ prefix, min 4 decimals, and trimmed trailing zeros up to 9', function (int $nano, string $expected) {
    expect(Nano::format($nano))->toBe($expected);
})->with([
    'zero uses 4 decimals' => [0, '$0.0000'],
    'whole dollar uses 4 decimals' => [1_000_000_000, '$1.0000'],
    '46.125m nano trims to 6 decimals' => [46_125_000, '$0.046125'],
    '373.875m nano trims to 6 decimals' => [373_875_000, '$0.373875'],
    '305.51m nano trims to 5 decimals' => [305_510_000, '$0.30551'],
    '381.8875m nano trims to 7 decimals' => [381_887_500, '$0.3818875'],
    'single nanodollar shows all 9 decimals' => [1, '$0.000000001'],
]);
