<?php

use App\Services\FormulaEvaluator;

test('evaluates a simple percentage formula', function () {
    expect(FormulaEvaluator::evaluate('BASIC * 0.12', ['BASIC' => 30000.0]))->toBe(3600.0);
});

test('evaluates a formula with parentheses and multiple variables', function () {
    expect(FormulaEvaluator::evaluate('(BASIC + HRA) * 0.10', [
        'BASIC' => 30000.0,
        'HRA' => 15000.0,
    ]))->toBe(4500.0);
});

test('treats unknown variables as zero', function () {
    expect(FormulaEvaluator::evaluate('FOO + 100', []))->toBe(100.0);
});

test('division by zero returns zero', function () {
    expect(FormulaEvaluator::evaluate('BASIC / ZERO', ['BASIC' => 30000.0, 'ZERO' => 0.0]))->toBe(0.0);
});

test('throws on malformed expression', function () {
    FormulaEvaluator::evaluate('BASIC * (0.12', ['BASIC' => 30000.0]);
})->throws(RuntimeException::class);

test('throws on unexpected character', function () {
    FormulaEvaluator::evaluate('BASIC % 10', ['BASIC' => 30000.0]);
})->throws(RuntimeException::class);
