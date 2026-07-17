<?php

namespace App\Services\Statutory;

use App\Enums\StatutoryRuleType;
use Carbon\CarbonInterface;

/**
 * No statutory rule is configured for a period the payroll engine needs.
 *
 * Raised instead of defaulting to a built-in rate: an unconfigured period must
 * stop the run and be fixed by an administrator, not quietly produce payslips
 * computed on rates nobody chose.
 */
class MissingStatutoryRuleException extends \DomainException
{
    public static function for(
        StatutoryRuleType $type,
        CarbonInterface $date,
        ?string $jurisdiction = null,
        ?string $regime = null,
    ): self {
        $scope = array_filter([
            $jurisdiction ? "jurisdiction {$jurisdiction}" : null,
            $regime ? "{$regime} regime" : null,
        ]);

        return new self(sprintf(
            'No %s rule is configured for %s%s. Add an effective-dated rule under Payroll Settings → Statutory Rules before running payroll for this period.',
            $type->label(),
            $date->toDateString(),
            $scope ? ' ('.implode(', ', $scope).')' : '',
        ));
    }
}
