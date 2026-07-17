<?php

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Database\QueryException;

test('both salary cycles can hold a payroll in the same month', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $cycleA = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'draft', 'processed_by' => $admin->id,
    ]);

    $cycleB = Payroll::create([
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_b',
        'status' => 'draft', 'processed_by' => $admin->id,
    ]);

    expect($cycleA->id)->not->toBe($cycleB->id)
        ->and(Payroll::where('month', 'July')->where('year', 2026)->count())->toBe(2);
});

test('the same cycle cannot be duplicated within a month', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);

    $attributes = [
        'month' => 'July', 'year' => 2026, 'cycle' => 'cycle_a',
        'status' => 'draft', 'processed_by' => $admin->id,
    ];

    Payroll::create($attributes);

    expect(fn () => Payroll::create($attributes))
        ->toThrow(QueryException::class);
});
