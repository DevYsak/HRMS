<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The UK statutory family leave types the system had no way to express.
 *
 * Adoption Leave, Shared Parental Leave and Parental Leave were all missing,
 * and `leave_types.category` was an enum that could not name them, so they
 * could not have been added through the UI either.
 *
 * Deliberately NOT done here: renaming or re-valuing the existing Maternity
 * (180 days) and Paternity (15 days) records. Those numbers are Indian
 * statutory shapes — 26 weeks and roughly two weeks — and the UK equivalents
 * are 52 weeks and 2 weeks. Changing them silently would rewrite the
 * entitlement of anyone already holding a balance, and the correct figures
 * depend on the employment contract and on eligibility tests this migration
 * has no way to evaluate. They are reported for a decision instead.
 *
 * The statutory durations go in `max_consecutive_days`, NOT
 * `annual_allocation_days`. That distinction matters: LeaveBalanceService
 * auto-creates a balance for every type carrying an annual allocation, so
 * putting 260 there would have given all 9 employees a visible "260 days of
 * Adoption Leave available" — family leave is triggered by an event, not
 * accrued each year like annual leave. HR grants it when the event occurs.
 *
 * Eligibility (service length, notice, earnings) is a policy question and
 * stays out of the schema; these numbers are ceilings, not entitlements.
 *
 * `max_consecutive_days` also has to be widened. It was tinyint unsigned, so
 * it topped out at 255 — and 52 weeks of adoption leave is 260 working days.
 * The column simply could not hold a UK family-leave duration. Widening
 * tinyint to smallint is lossless.
 */
return new class extends Migration
{
    /** Categories the enum held before this migration. */
    private const EXISTING = [
        'annual', 'sick', 'comp_off', 'encashment', 'unpaid', 'other',
        'unauthorized', 'bereavement', 'paternity', 'wfh', 'lwp', 'custom', 'maternity',
    ];

    private const ADDED = ['adoption', 'shared_parental', 'parental'];

    public function up(): void
    {
        $this->setCategoryEnum([...self::EXISTING, ...self::ADDED]);

        DB::statement('ALTER TABLE `leave_types` MODIFY `max_consecutive_days` SMALLINT UNSIGNED NULL');

        foreach ($this->types() as $type) {
            // Guarded on code so re-running is a no-op and an existing
            // hand-created record is never overwritten.
            if (DB::table('leave_types')->where('code', $type['code'])->exists()) {
                continue;
            }

            DB::table('leave_types')->insert($type + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('leave_types')->whereIn('code', ['ADL', 'SPL', 'PARL'])->delete();

        // Safe now that the only rows needing more than 255 are gone.
        DB::statement('ALTER TABLE `leave_types` MODIFY `max_consecutive_days` TINYINT UNSIGNED NULL');

        $this->setCategoryEnum(self::EXISTING);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function types(): array
    {
        return [
            [
                'name' => 'Adoption Leave',
                'code' => 'ADL',
                'category' => 'adoption',
                // Up to 52 weeks, mirroring Statutory Maternity Leave.
                'annual_allocation_days' => null,
                'max_consecutive_days' => 260,
                'is_paid' => true,
                'allow_paid_request' => true,
                'allow_unpaid_request' => false,
                // Either adopter may take it, so no gender restriction.
                'gender_restriction' => 'none',
                'attachment_required' => true,
                'is_system_controlled' => false,
                'allow_half_day' => false,
                'allow_carry_forward' => false,
                'allow_encashment' => false,
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Shared Parental Leave',
                'code' => 'SPL',
                'category' => 'shared_parental',
                // Up to 50 weeks shared between parents — the split is agreed
                // between them, so this is a ceiling rather than an allocation.
                'annual_allocation_days' => null,
                'max_consecutive_days' => 250,
                'is_paid' => true,
                'allow_paid_request' => true,
                'allow_unpaid_request' => false,
                'gender_restriction' => 'none',
                'attachment_required' => true,
                'is_system_controlled' => false,
                'allow_half_day' => false,
                'allow_carry_forward' => false,
                'allow_encashment' => false,
                'color' => '#EC4899',
            ],
            [
                'name' => 'Parental Leave',
                'code' => 'PARL',
                'category' => 'parental',
                // 18 weeks per child, unpaid, up to the child's 18th birthday.
                'annual_allocation_days' => null,
                'max_consecutive_days' => 90,
                'is_paid' => false,
                'allow_paid_request' => false,
                'allow_unpaid_request' => true,
                'gender_restriction' => 'none',
                'attachment_required' => false,
                'is_system_controlled' => false,
                'allow_half_day' => false,
                'allow_carry_forward' => false,
                'allow_encashment' => false,
                'color' => '#06B6D4',
            ],
        ];
    }

    /** @param  array<int, string>  $values */
    private function setCategoryEnum(array $values): void
    {
        $list = implode(',', array_map(fn (string $v) => "'".$v."'", $values));

        DB::statement("ALTER TABLE `leave_types` MODIFY `category` ENUM({$list}) NULL");
    }
};
