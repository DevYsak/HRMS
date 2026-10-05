<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single row of company-wide module switches. Read through
 * ModuleFeatureService, never directly from views.
 */
#[Fillable(['payroll_enabled', 'payslips_enabled', 'updated_by'])]
class ModuleSetting extends Model
{
    protected function casts(): array
    {
        return [
            'payroll_enabled' => 'boolean',
            'payslips_enabled' => 'boolean',
        ];
    }

    /** The single row, created with every module enabled on first use. */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'payroll_enabled' => true,
            'payslips_enabled' => true,
        ]);
    }
}
