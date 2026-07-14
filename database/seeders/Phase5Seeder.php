<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobPosting;
use App\Models\ReviewGoal;
use App\Models\User;
use Illuminate\Database\Seeder;

class Phase5Seeder extends Seeder
{
    public function run(): void
    {
        $adminAssignedToEmployee = User::where('email', 'admin@example.com')->first()?->employee;

        // 1. Performance review demo data now lives in the template-driven
        // PerformanceCycle flow (create a template + cycle via the UI or
        // ReviewWorkflowService). Legacy ReviewCycle seeding was retired in
        // the v4 ReviewCycle→PerformanceCycle cutover.

        if ($adminAssignedToEmployee) {
            ReviewGoal::create([
                'employee_id' => $adminAssignedToEmployee->id,
                'title' => 'Launch Phase 5 of HRMS',
                'description' => 'Complete Performance and Recruitment modules.',
                'due_date' => '2026-05-01',
                'is_completed' => false,
            ]);
            ReviewGoal::create([
                'employee_id' => $adminAssignedToEmployee->id,
                'title' => 'Complete Security Audit',
                'description' => 'Review all access controls.',
                'due_date' => '2026-03-15',
                'is_completed' => true,
                'completed_at' => '2026-03-10 10:00:00',
            ]);
        }

        // 2. Recruitment
        $techDept = Department::where('name', 'Technology')->first() ?? Department::first();
        $hrDept = Department::where('name', 'Human Resources')->first() ?? Department::first();

        $job1 = JobPosting::create([
            'title' => 'Senior Backend Engineer (Laravel)',
            'department_id' => $techDept?->id,
            'employment_type' => 'full_time',
            'description' => "We're looking for a senior Laravel dev to head our core systems.",
            'requirements' => "- 5+ years PHP/Laravel\n- Experience with Livewire 3+",
            'location' => 'San Francisco, CA / Remote',
            'salary_min' => 120000,
            'salary_max' => 160000,
            'status' => 'open',
            'closes_at' => now()->addDays(30),
        ]);

        $job2 = JobPosting::create([
            'title' => 'HR Coordinator',
            'department_id' => $hrDept?->id,
            'employment_type' => 'full_time',
            'description' => 'Join our growing People team.',
            'requirements' => "- Prior HR experience\n- Friendly and organized",
            'location' => 'New York, NY',
            'salary_min' => 70000,
            'salary_max' => 90000,
            'status' => 'open',
            'closes_at' => now()->addDays(15),
        ]);

        // Add Candidates
        Candidate::create([
            'job_posting_id' => $job1->id,
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
            'phone' => '555-0101',
            'stage' => 'offer',
            'rating' => 5,
            'notes' => 'Aced the technical interview. Preparing standard offer package.',
            'applied_at' => now()->subDays(10),
        ]);

        Candidate::create([
            'job_posting_id' => $job1->id,
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
            'stage' => 'interview',
            'rating' => 4,
            'notes' => 'Good knowledge of Laravel, taking him to round 2.',
            'applied_at' => now()->subDays(5),
        ]);

        Candidate::create([
            'job_posting_id' => $job1->id,
            'name' => 'Charlie Davis',
            'email' => 'charlie@example.com',
            'stage' => 'rejected',
            'rating' => 2,
            'notes' => 'Lacks required Livewire experience.',
            'applied_at' => now()->subDays(4),
        ]);

        Candidate::create([
            'job_posting_id' => $job2->id,
            'name' => 'Diana Prince',
            'email' => 'diana@example.com',
            'stage' => 'applied',
            'applied_at' => now()->subDays(1),
        ]);
    }
}
