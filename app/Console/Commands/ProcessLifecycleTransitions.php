<?php

namespace App\Console\Commands;

use App\Services\EmployeeLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hrms:process-lifecycle-transitions')]
#[Description('Auto-advance date-driven employee lifecycle transitions (notice period → resigned, etc.)')]
class ProcessLifecycleTransitions extends Command
{
    public function handle(EmployeeLifecycleService $lifecycle): int
    {
        $count = $lifecycle->processDueTransitions();

        $this->info("Processed {$count} lifecycle transition(s).");

        return self::SUCCESS;
    }
}
