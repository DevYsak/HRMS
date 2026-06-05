<?php

namespace Database\Seeders;

use App\Models\EmploymentType;
use App\Models\ProbationSetting;
use Illuminate\Database\Seeder;

class ProbationSettingSeeder extends Seeder
{
    public function run(): void
    {
        // Global default (no employment_type_id)
        ProbationSetting::updateOrCreate(
            ['employment_type_id' => null],
            [
                'probation_days' => 90,
                'auto_calculate' => true,
                'requires_manager_review' => true,
                'requires_hr_approval' => true,
                'extension_max_days' => 90,
                'is_active' => true,
            ]
        );

        // Per-type overrides mapped from employment_types seeder slugs
        $overrides = [
            'full-time' => ['probation_days' => 90,  'requires_manager_review' => true,  'requires_hr_approval' => true],
            'part-time' => ['probation_days' => 60,  'requires_manager_review' => true,  'requires_hr_approval' => false],
            'contract' => ['probation_days' => 0,   'requires_manager_review' => false, 'requires_hr_approval' => false],
            'internship' => ['probation_days' => 30,  'requires_manager_review' => true,  'requires_hr_approval' => false],
        ];

        foreach ($overrides as $slug => $data) {
            $type = EmploymentType::where('slug', $slug)->first();
            if (! $type) {
                continue;
            }

            ProbationSetting::updateOrCreate(
                ['employment_type_id' => $type->id],
                array_merge([
                    'auto_calculate' => true,
                    'extension_max_days' => 90,
                    'is_active' => true,
                ], $data)
            );
        }
    }
}
