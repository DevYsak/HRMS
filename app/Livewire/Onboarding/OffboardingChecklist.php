<?php

namespace App\Livewire\Onboarding;

class OffboardingChecklist extends OnboardingChecklist
{
    public string $phase = 'offboarding';

    public function mount(int $employee, string $phase = 'offboarding'): void
    {
        parent::mount($employee, 'offboarding');
    }
}
