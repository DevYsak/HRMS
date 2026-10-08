<?php

namespace App\Livewire\Help;

use App\Services\Help\EmployeeGuide as EmployeeGuideContent;
use App\Services\Help\RoleGuides;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Help & Guide: a searchable walkthrough per role journey — Employee (with
 * real screenshots), Manager, Department Head, HR Admin, Finance and Super
 * Admin. A reader sees only the journeys and sections whose pages they can
 * open, with menu paths taken from their own sidebar. Read-only — it links to
 * features but never grants access to them.
 */
class EmployeeGuide extends Component
{
    /** Which journey is open (?journey=manager); defaults to the reader's main role. */
    #[Url]
    public string $journey = '';

    public function render(EmployeeGuideContent $guide, RoleGuides $roleGuides): View
    {
        $user = auth()->user();
        $journeys = $roleGuides->journeys($user);

        if (! isset($journeys[$this->journey])) {
            $this->journey = (string) $roleGuides->defaultJourney($journeys);
        }

        $current = $journeys[$this->journey] ?? ['id' => '', 'label' => 'Guide', 'title' => 'Help & Guide', 'intro' => '', 'sections' => []];
        $isEmployee = $current['id'] === 'employee';

        return view('livewire.help.employee-guide', [
            'journeys' => collect($journeys)->map(fn (array $j) => ['id' => $j['id'], 'label' => $j['label']])->values()->all(),
            'current' => $current,
            'isEmployeeJourney' => $isEmployee,
            'sections' => $current['sections'],
            'shots' => $isEmployee ? $guide->shots() : [],
            'capturedAt' => $isEmployee ? $guide->capturedAt() : null,
            'profileTiers' => $guide->profileTiers(),
            'capabilities' => $isEmployee ? $guide->capabilities($user) : [],
            'requestTypes' => $isEmployee ? $guide->requestTypes($user) : [],
            'faqs' => $isEmployee ? $guide->faqs() : [],
            'assetBase' => asset(EmployeeGuideContent::ASSET_DIR),
        ])->layout('layouts.app', ['title' => 'Help & Guide']);
    }
}
