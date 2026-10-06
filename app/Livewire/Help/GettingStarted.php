<?php

namespace App\Livewire\Help;

use App\Services\Help\GettingStarted as GettingStartedContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The new-employee tutorial: first sign-in, password, profile and leave, as
 * numbered steps with live "done" ticks. Read-only — it links to features
 * but never grants access to them.
 */
class GettingStarted extends Component
{
    public function render(GettingStartedContent $content): View
    {
        $steps = $content->steps(auth()->user());
        $tracked = collect($steps)->filter(fn (array $step) => $step['done'] !== null);

        return view('livewire.help.getting-started', [
            'steps' => $steps,
            'doneCount' => $tracked->where('done', true)->count(),
            'trackedCount' => $tracked->count(),
        ])->layout('layouts.app', ['title' => 'Getting Started']);
    }
}
