<?php

namespace App\Services\Profile;

use App\Models\Document;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\ProfileFieldSetting;

/**
 * Scores how complete a profile is, and — more usefully — says which field to
 * fill in next, so the completion ring can deep-link straight to the gap
 * rather than leaving someone to hunt for it.
 *
 * Only what HR has marked Required (Profile Fields settings) and the employee
 * can act on counts: self-service fields that are not HR-only, and KYC
 * documents HR asks for. A field with a change request waiting for HR counts
 * as submitted, not missing — the employee has done their part. Optional
 * fields are listed separately and never lower the score.
 */
class ProfileCompletionService
{
    /**
     * @return array{percent:int, completed:int, total:int, missing:array<int, array{field:string, label:string, tier:string}>, submitted:array<int, array{field:string, label:string}>, optional_missing:array<int, array{field:string, label:string, tier:string}>}
     */
    public function for(Employee $employee): array
    {
        $employee->loadMissing(['user', 'payrollSettings']);

        $pending = ProfileChangeRequest::where('employee_id', $employee->id)->pending()->pluck('field')->flip();
        $missing = [];
        $submitted = [];
        $optionalMissing = [];
        $required = 0;

        foreach (ProfileFieldRegistry::selfServiceKeys() as $key) {
            $requirement = ProfileFieldRegistry::requirement($key);

            if ($requirement === ProfileFieldSetting::HR_ONLY) {
                continue;
            }

            $value = ProfileFieldRegistry::valueFor($employee, $key);
            $isEmpty = $value === null || $value === '';
            $entry = ['field' => $key, 'label' => ProfileFieldRegistry::label($key), 'tier' => ProfileFieldRegistry::tier($key)];

            if ($requirement === ProfileFieldSetting::OPTIONAL) {
                if ($isEmpty) {
                    $optionalMissing[] = $entry;
                }

                continue;
            }

            $required++;

            if ($isEmpty && $pending->has($key)) {
                $submitted[] = ['field' => $key, 'label' => $entry['label']];
            } elseif ($isEmpty) {
                $missing[] = $entry;
            }
        }

        $uploadedKyc = Document::where('employee_id', $employee->id)->where('category', 'kyc')->pluck('kyc_type')->flip();

        foreach (ProfileFieldRegistry::KYC_TYPES as $type => $label) {
            if (ProfileFieldRegistry::requirement("kyc:{$type}") !== ProfileFieldSetting::REQUIRED) {
                continue;
            }

            $required++;

            if (! $uploadedKyc->has($type)) {
                $missing[] = ['field' => "kyc:{$type}", 'label' => $label, 'tier' => 'kyc'];
            }
        }

        $completed = $required - count($missing);

        return [
            'percent' => $required > 0 ? (int) round($completed / $required * 100) : 100,
            'completed' => $completed,
            'total' => $required,
            'missing' => $missing,
            'submitted' => $submitted,
            'optional_missing' => $optionalMissing,
        ];
    }

    /** The field to prompt for next, or null when nothing is outstanding. */
    public function nextGap(Employee $employee): ?string
    {
        return $this->for($employee)['missing'][0]['field'] ?? null;
    }
}
