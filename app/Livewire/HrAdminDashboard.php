<?php

namespace App\Livewire;

use App\Services\Dashboards\OrganisationOverview;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * /dashboard/hr-admin — the same HR overview "/" shows HR users, from the one
 * shared, scoped source (OrganisationOverview), so the two can never differ.
 */
class HrAdminDashboard extends Component
{
    public function render()
    {
        return view('livewire.hr-admin-dashboard', OrganisationOverview::viewData(Auth::user()))
            ->layout('layouts.app', ['title' => 'HR Admin Dashboard']);
    }
}
