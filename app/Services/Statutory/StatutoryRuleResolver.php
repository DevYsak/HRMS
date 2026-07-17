<?php

namespace App\Services\Statutory;

use App\Enums\StatutoryRuleType;
use App\Models\StatutoryRule;
use Carbon\CarbonInterface;

/**
 * Resolves the statutory rule in force for a given date.
 *
 * A missing or malformed rule is a configuration fault, never a zero-rupee
 * deduction: this throws rather than falling back to a default, because a silent
 * fallback is what let stale rates reach payslips unnoticed in the first place.
 *
 * Resolved rules are memoised per request — a payroll run over N employees asks
 * for the same four rules N times, and these rows change at most a few times a year.
 */
class StatutoryRuleResolver
{
    /** @var array<string, StatutoryRule> */
    private array $memo = [];

    /**
     * The rule of $type in force on $date.
     *
     * @throws MissingStatutoryRuleException when nothing is configured for the period
     */
    public function resolve(
        StatutoryRuleType $type,
        CarbonInterface $date,
        ?string $jurisdiction = null,
        ?string $regime = null,
    ): StatutoryRule {
        $key = implode('|', [$type->value, $date->toDateString(), $jurisdiction ?? '-', $regime ?? '-']);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $query = StatutoryRule::query()
            ->where('type', $type->value)
            ->inForceOn($date);

        if ($type->isJurisdictional()) {
            $query->where('jurisdiction', $jurisdiction);
        }

        if ($type->isRegimeScoped()) {
            $query->where('regime', $regime);
        }

        // Latest window wins when overlapping rows exist, so a correction opened
        // today supersedes an older open-ended row without needing it closed first.
        $rule = $query->orderByDesc('effective_from')->orderByDesc('id')->first();

        if (! $rule) {
            throw MissingStatutoryRuleException::for($type, $date, $jurisdiction, $regime);
        }

        $this->assertConfigComplete($rule);

        return $this->memo[$key] = $rule;
    }

    /** Config payload of the rule in force, as a plain array. */
    public function config(
        StatutoryRuleType $type,
        CarbonInterface $date,
        ?string $jurisdiction = null,
        ?string $regime = null,
    ): array {
        return $this->resolve($type, $date, $jurisdiction, $regime)->config;
    }

    private function assertConfigComplete(StatutoryRule $rule): void
    {
        $missing = array_diff($rule->type->requiredConfigKeys(), array_keys($rule->config ?? []));

        if ($missing !== []) {
            throw new \DomainException(sprintf(
                'Statutory rule #%d (%s) is missing required config keys: %s.',
                $rule->id,
                $rule->type->label(),
                implode(', ', $missing),
            ));
        }
    }
}
