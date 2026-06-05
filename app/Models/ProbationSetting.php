<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationSetting extends Model
{
    protected $fillable = [
        'employment_type_id', 'probation_days', 'auto_calculate',
        'requires_manager_review', 'requires_hr_approval',
        'extension_max_days', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'probation_days' => 'integer',
            'auto_calculate' => 'boolean',
            'requires_manager_review' => 'boolean',
            'requires_hr_approval' => 'boolean',
            'extension_max_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }
}
