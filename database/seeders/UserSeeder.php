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
                'email' => 'mazhar@conexus.in',
                'name' => 'Mazhar',
                'role' => UserRole::SuperAdmin,
            ],
            // HR Admin — Shivani (all HR modules except system config, spec §1.4)
            [
                'email' => 'shivani@conexus.in',
                'name' => 'Shivani',
                'role' => UserRole::HrAdmin,
            ],
            // Directors — Rustom, Nick, Nikia (spec §1.4)
            [
                'email' => 'rustom@conexus.in',
                'name' => 'Rustom',
                'role' => UserRole::Director,
            ],
            [
                'email' => 'nick@conexus.in',
                'name' => 'Nick',
                'role' => UserRole::Director,
            ],
            [
                'email' => 'nikia@conexus.in',
                'name' => 'Nikia',
                'role' => UserRole::Director,
            ],
            // Finance — Emad (compensation module + payroll approval, spec §1.4)
            [
                'email' => 'emad@conexus.in',
                'name' => 'Emad',
                'role' => UserRole::Finance,
            ],
            // Demo employee for testing
            [
                'email' => 'employee@conexus.in',
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
