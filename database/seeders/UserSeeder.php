<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Core system users — no demo accounts.
     * These are the only users that should exist in a production environment.
     */
    public function run(): void
    {
        $users = [
            // Super Admin — Mazhar (full system access)
            [
                'email' => 'mazhar@conexus-ns.com',
                'name' => 'Mazhar',
                'role' => UserRole::SuperAdmin,
            ],
            // HR Admin — Shivani (all HR modules)
            [
                'email' => 'shivani@conexus-ns.com',
                'name' => 'Shivani',
                'role' => UserRole::HrAdmin,
            ],
            // Directors
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
                'email' => 'nikita@conexus-ns.com',
                'name' => 'Nikita',
                'role' => UserRole::Director,
            ],
            // Finance — Emad (payroll processing + finance approval)
            [
                'email' => 'emad@conexus-ns.com',
                'name' => 'Emad',
                'role' => UserRole::Finance,
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
