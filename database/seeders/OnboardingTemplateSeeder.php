<?php

namespace Database\Seeders;

use App\Models\OnboardingTemplate;
use Illuminate\Database\Seeder;

class OnboardingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (OnboardingTemplate::exists()) {
            return;
        }

        /** @var OnboardingTemplate $template */
        $template = OnboardingTemplate::create([
            'name' => 'Default Onboarding Template',
            'description' => 'Standard onboarding checklist applied to all new hires.',
            'is_default' => true,
            'is_active' => true,
        ]);

        $tasks = [
            ['title' => 'Complete personal profile',  'category' => 'hr',       'owner_role' => 'employee', 'sort_order' => 1,  'auto_trigger' => 'account_create', 'due_days' => 1],
            ['title' => 'Upload KYC documents',       'category' => 'hr',       'owner_role' => 'employee', 'sort_order' => 2,  'auto_trigger' => 'kyc_upload',     'due_days' => 3],
            ['title' => 'Bank account setup',         'category' => 'finance',  'owner_role' => 'finance',  'sort_order' => 3,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'IT hardware assignment',     'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 4,  'auto_trigger' => 'asset_assign',   'due_days' => 5],
            ['title' => 'Email & Slack setup',        'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 5,  'auto_trigger' => 'account_create', 'due_days' => 1],
            ['title' => 'Biometric enrollment',       'category' => 'it_setup', 'owner_role' => 'it',       'sort_order' => 6,  'auto_trigger' => 'biometric_sync', 'due_days' => 5],
            ['title' => 'Company policy induction',   'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 7,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Team introduction',          'category' => 'general',  'owner_role' => 'manager',  'sort_order' => 8,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Role expectation setting',   'category' => 'general',  'owner_role' => 'manager',  'sort_order' => 9,  'auto_trigger' => null,             'due_days' => 7],
            ['title' => 'Access card issuance',       'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 10, 'auto_trigger' => null,             'due_days' => 3],
            ['title' => 'Welcome kit handover',       'category' => 'hr',       'owner_role' => 'hr',       'sort_order' => 11, 'auto_trigger' => null,             'due_days' => 1],
        ];

        foreach ($tasks as $task) {
            $template->tasks()->create(array_merge($task, ['phase' => 'onboarding']));
        }
    }
}
