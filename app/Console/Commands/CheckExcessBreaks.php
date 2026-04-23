<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use Illuminate\Console\Command;

class CheckExcessBreaks extends Command
{
    protected $signature   = 'hrms:check-excess-breaks';
    protected $description = 'Flag attendance records from yesterday where total break time exceeded 60 minutes.';

    public function handle(): int
    {
        $yesterday = now()->subDay()->toDateString();

        // Flag any record with break_minutes > 60
        $flagged = Attendance::where('date', $yesterday)
            ->where('break_minutes', '>', 60)
            ->get();

        $count = 0;

        foreach ($flagged as $record) {
            $excess = $record->break_minutes - 60;
            $record->update([
                'notes' => trim(($record->notes ?? '') . " [Auto] Excess break: {$record->break_minutes}min (+{$excess}min over limit)."),
            ]);
            $count++;
        }

        $this->info("Flagged {$count} record(s) with excess break time for {$yesterday}.");

        return self::SUCCESS;
    }
}
