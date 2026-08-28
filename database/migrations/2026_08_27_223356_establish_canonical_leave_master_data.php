<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The approved canonical leave configuration.
 *
 * Every step is explicit and enumerated. Nothing here is inferred from a name,
 * nothing is merged, and nothing is deleted: the types being retired hold live
 * balances and requests, and those rows keep pointing at them so historical
 * reports continue to mean what they meant.
 *
 * What this does NOT do, deliberately:
 *   - merge CL and EL (pending HR decision)
 *   - create CSL or MDL (pending policy confirmation)
 *   - alter Maternity 180 or Paternity 15 (pending legal confirmation)
 *   - touch a single leave_balance or leave_request row
 *   - carry anything forward
 */
return new class extends Migration
{
    /** Types retired for future use. History stays attached to them. */
    private const RETIRE_CODES = ['CL', 'EL', 'CUS'];

    public function up(): void
    {
        $now = now();

        // 1. Unauthorized Leave gives up the UL code.
        //
        // Order matters: UL must be free before anything else may claim it, and
        // leave_types.code is uniquely indexed. Sandwich goes off at the same
        // time — bridging the weekend between two unauthorised absences turned
        // two days of absence into four.
        DB::table('leave_types')->where('code', 'UL')->update([
            'code' => 'UNA',
            'is_sandwich_applicable' => false,
            'sandwich_mode' => 'off',
            'payment_mode' => 'unpaid',
            'updated_at' => $now,
        ]);

        // 2. Annual Leave — the single canonical UK annual entitlement.
        //
        // annual_allocation_days stays NULL on purpose. Entitlement comes from
        // UK Standard -> working pattern -> LeaveEntitlementService, and a
        // number here would be a second answer to the same question.
        if (! DB::table('leave_types')->where('code', 'AL')->exists()) {
            DB::table('leave_types')->insert([
                'name' => 'Annual Leave',
                'code' => 'AL',
                'category' => 'annual',
                'annual_allocation_days' => null,
                'is_paid' => true,
                'payment_mode' => 'paid',
                'allow_paid_request' => true,
                'allow_unpaid_request' => false,
                'allow_half_day' => true,
                'is_sandwich_applicable' => false,
                'sandwich_mode' => 'off',
                'allow_carry_forward' => true,
                'carry_forward_mode' => 'hr_approval',
                // Retained for reference only. The cap now comes from the
                // employee's leave policy; see LeaveCarryOverService.
                'carry_forward_limit' => 0,
                'allow_encashment' => true,
                'is_system_controlled' => false,
                'is_monthly_accrual' => false,
                'gender_restriction' => 'none',
                'color' => '#F97316',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. Retire legacy types. Soft delete: the rows remain, and every
        //    balance and request keeps its original leave_type_id.
        DB::table('leave_types')
            ->whereIn('code', self::RETIRE_CODES)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        // The code-less "Unpaid Leave" duplicate. Matched by id-shape rather
        // than name: it has no code to key on, and LWP is the canonical type.
        DB::table('leave_types')
            ->whereNull('code')
            ->where('name', 'Unpaid Leave')
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        // 4. Verified working pattern for employees who have none recorded.
        //
        // Only where it is missing: an employee with a real pattern on file is
        // not overwritten by a company default. contracted_hours_per_week is
        // left alone — it was not confirmed, and inventing it would put an
        // unverified number into an entitlement calculation.
        DB::table('employees')
            ->where('status', 'active')
            ->whereNull('working_days_per_week')
            ->update([
                'working_pattern' => 'regular',
                'working_days_per_week' => 5,
                'working_days' => json_encode(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']),
                'updated_at' => $now,
            ]);

        // 5. Link active employees to the UK policy, without disturbing anyone
        //    already assigned to a different one.
        $ukPolicyId = DB::table('leave_policies')->where('name', 'UK Standard')->value('id');

        if ($ukPolicyId !== null) {
            DB::table('employees')
                ->where('status', 'active')
                ->whereNull('leave_policy_id')
                ->update(['leave_policy_id' => $ukPolicyId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = now();

        // Restore the retired types. They were never removed, only hidden.
        DB::table('leave_types')->whereIn('code', self::RETIRE_CODES)
            ->update(['deleted_at' => null, 'updated_at' => $now]);

        DB::table('leave_types')->whereNull('code')->where('name', 'Unpaid Leave')
            ->update(['deleted_at' => null, 'updated_at' => $now]);

        // Annual Leave is removed only when nothing has been recorded against
        // it. Once it carries balances it is history like any other type.
        $annualId = DB::table('leave_types')->where('code', 'AL')->value('id');

        if ($annualId !== null
            && ! DB::table('leave_balances')->where('leave_type_id', $annualId)->exists()
            && ! DB::table('leave_requests')->where('leave_type_id', $annualId)->exists()) {
            DB::table('leave_types')->where('id', $annualId)->delete();
        }

        DB::table('leave_types')->where('code', 'UNA')->update(['code' => 'UL', 'updated_at' => $now]);

        $ukPolicyId = DB::table('leave_policies')->where('name', 'UK Standard')->value('id');

        if ($ukPolicyId !== null) {
            DB::table('employees')->where('leave_policy_id', $ukPolicyId)
                ->update(['leave_policy_id' => null, 'updated_at' => $now]);
        }
    }
};
