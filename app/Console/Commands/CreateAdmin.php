<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pulse:create-admin {--name=Admin} {--email=admin@example.com} {--password=password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a super admin user for the Pulse HRMS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        if (User::where('email', $email)->exists()) {
            $this->error("User with email {$email} already exists.");

            return 1;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::SuperAdmin,
            'email_verified_at' => now(),
        ]);

        Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'ADMIN-001',
            'joining_date' => now(),
            'status' => 'active',
            'employment_type' => 'full-time',
        ]);

        $this->info('Super Admin created successfully!');
        $this->line("Email: {$email}");
        $this->line("Password: {$password}");

        return 0;
    }
}
