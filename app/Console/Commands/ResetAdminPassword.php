<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Console\Command;

/**
 * Reset any user's password from the CLI (e.g. a locked-out admin). Generates a
 * secure password unless one is supplied, and records it in password history.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'hrms:reset-admin
                            {email : The account email}
                            {--password= : Use a specific password (otherwise a secure one is generated)}';

    protected $description = "Reset a user's password (e.g. a locked-out admin) and record it in password history.";

    public function handle(PasswordService $passwords): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $plain = $passwords->resetPassword($user, $this->option('password') ?: null);

        $this->info("Password reset for {$user->name} <{$user->email}> ({$user->role?->value}).");
        $this->newLine();
        $this->line('  New password:  '.$plain);
        $this->newLine();
        $this->warn('Share this securely and change it after logging in.');

        return self::SUCCESS;
    }
}
