<?php

namespace App\Livewire\Help;

use App\Services\Help\EmployeeGuide as EmployeeGuideContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The in-app Employee Guide: a searchable walkthrough of every employee
 * feature, illustrated with real screenshots captured from an Employee-role
 * account. Read-only — it links to features but never grants access to them.
 */
class EmployeeGuide extends Component
{
    public function render(EmployeeGuideContent $guide): View
    {
        $user = auth()->user();

        return view('livewire.help.employee-guide', [
            'sections' => $guide->sections($user),
            'shots' => $guide->shots(),
            'capturedAt' => $guide->capturedAt(),
            'profileTiers' => $guide->profileTiers(),
            'capabilities' => $guide->capabilities($user),
            'requestTypes' => $guide->requestTypes($user),
            'faqs' => $guide->faqs(),
            'assetBase' => asset(EmployeeGuideContent::ASSET_DIR),
        ])->layout('layouts.app', ['title' => 'Employee Guide']);
    }
}
