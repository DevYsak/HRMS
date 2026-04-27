<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoEmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed 50 more random employees
        User::factory(50)->create(['role' => UserRole::Employee->value])->each(function ($user) {
            Employee::factory()->create([
                'user_id' => $user->id,
            ]);
        });
    }
}
