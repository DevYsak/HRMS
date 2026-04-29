<?php

namespace App\Console\Commands;

use App\Services\LeaveService;
use Illuminate\Console\Command;

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
        $year = (int) ($this->argument('year') ?: now()->year);
        $this->info("Processing leave carry-forward for year: {$year}");
        app(LeaveService::class)->carryForwardBalances($year);

        $this->info('Leave carry-forward completed successfully.');
    }
}
