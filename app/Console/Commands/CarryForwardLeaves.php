<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CarryForwardLeaves extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hrms:carry-forward-leaves {year?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resets leave balances for the new year and applies carry-forward limits.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $year = $this->argument('year') ?: now()->year;
        
        $this->info("Processing leave carry-forward for year: {$year}");

        $activeEmployees = Employee::where('status', 'active')->get();
        $leaveTypes = LeaveType::all();

        DB::transaction(function () use ($activeEmployees, $leaveTypes) {
            foreach ($activeEmployees as $employee) {
                foreach ($leaveTypes as $type) {
                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->first();

                    if (!$balance) {
                        continue;
                    }

                    $remaining = max(0, $balance->allocated_days - $balance->used_days);
                    $carryForward = 0;

                    if ($type->allow_carry_forward) {
                        $carryForward = min($remaining, (float)$type->carry_forward_limit);
                    }

                    // Reset for the new cycle
                    // We keep the allocated_days as is (base allotment) and reset used_days
                    // We add carryForward to the base allotment for this employee
                    $newAllocation = (float)$type->base_entitlement ?? $balance->allocated_days; // Default to current if not set
                    
                    $balance->update([
                        'allocated_days' => $newAllocation + $carryForward,
                        'used_days'      => 0,
                    ]);
                }
            }
        });

        $this->info('Leave carry-forward completed successfully.');
    }
}
