<?php

namespace App\Services\Profile;

use App\Models\Employee;

/**
 * Scores how complete a profile is, and — more usefully — says which field to
 * fill in next, so the completion ring can deep-link straight to the gap
 * rather than leaving someone to hunt for it.
 *
 * Only fields the employee can actually act on count toward the score. Holding
 * someone at 80% because HR hasn't set their office is not a nudge, it's noise.
 */
class ProfileCompletionService
{
    /**
     * @return array{percent:int, completed:int, total:int, missing:array<int, array{field:string, label:string, tier:string}>}
     */
    public function for(Employee $employee): array
    {
        $employee->loadMissing(['user', 'payrollSettings']);

        $keys = array_merge(
            ProfileFieldRegistry::keysByTier(ProfileFieldRegistry::TIER_EDITABLE),
            ProfileFieldRegistry::keysByTier(ProfileFieldRegistry::TIER_APPROVAL),
        );

        $missing = [];

        foreach ($keys as $key) {
            $value = ProfileFieldRegistry::valueFor($employee, $key);

            if ($value === null || $value === '') {
                $missing[] = [
                    'field' => $key,
                    'label' => ProfileFieldRegistry::label($key),
                    'tier' => ProfileFieldRegistry::tier($key),
                ];
            }
        }

        $total = count($keys);
        $completed = $total - count($missing);

        return [
            'percent' => $total > 0 ? (int) round($completed / $total * 100) : 100,
            'completed' => $completed,
            'total' => $total,
            'missing' => $missing,
        ];
    }

    /** The field to prompt for next, or null when nothing is outstanding. */
    public function nextGap(Employee $employee): ?string
    {
        return $this->for($employee)['missing'][0]['field'] ?? null;
    }
}
