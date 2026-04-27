<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'email' => 'admin@conexus.in',
                'name' => 'Super Admin',
                'role' => UserRole::SuperAdmin,
            ],
            [
                'email' => 'pristia@conexus.in',
                'name' => 'Pristia Candra',
                'role' => UserRole::HrAdmin,
            ],
            [
                'email' => 'rayna@conexus.in',
                'name' => 'Rayna Torff',
                'role' => UserRole::Manager,
            ],
            [
                'email' => 'test@example.com',
                'name' => 'Test Employee',
                'role' => UserRole::Employee,
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('12345678'),
                    'role' => $user['role']->value,
                ]
            );
        }
    }
}
