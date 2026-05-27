<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            // Super Admin — Mazhar (full system access, spec §1.4)
            [
                'email' => 'mazhar@conexus-ns.com',
                'name' => 'Mazhar',
                'role' => UserRole::SuperAdmin,
            ],
            // HR Admin — Shivani (all HR modules except system config, spec §1.4)
            [
                'email' => 'shivani@conexus-ns.com',
                'name' => 'Shivani',
                'role' => UserRole::HrAdmin,
            ],
            // Directors — Rustom, Nick, Nikia (spec §1.4)
            [
                'email' => 'rustom@conexus-ns.com',
                'name' => 'Rustom',
                'role' => UserRole::Director,
            ],
            [
                'email' => 'nick@conexus-ns.com',
                'name' => 'Nick',
                'role' => UserRole::Director,
            ],
            [
                'email' => 'nikia@conexus-ns.com',
                'name' => 'Nikia',
                'role' => UserRole::Director,
            ],
            // Finance — Emad (compensation module + payroll approval, spec §1.4)
            [
                'email' => 'emad@conexus-ns.com',
                'name' => 'Emad',
                'role' => UserRole::Finance,
            ],
            // Demo employee for testing
            [
                'email' => 'employee@conexus-ns.com',
                'name' => 'Test Employee',
                'role' => UserRole::Employee,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => $data['role']->value,
                ]
            );
        }
    }
}
