<?php

namespace App\Livewire\Performance;

use App\Models\PromotionRecommendation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyPromotions extends Component
{
    public bool $showViewModal = false;

    public ?PromotionRecommendation $activeRecommendation = null;

    public function viewRecommendation(int $id): void
    {
        $user = Auth::user();
        if (! $user->employee) {
            abort(403);
        }

        $recommendation = PromotionRecommendation::with(['recommendedBy', 'targetDepartment', 'documents'])->findOrFail($id);

        if ($recommendation->employee_id !== $user->employee->id) {
            abort(403);
        }

        $this->activeRecommendation = $recommendation;
        $this->showViewModal = true;
    }

    public function render()
    {
        $user = Auth::user();

        $recommendations = collect();
        if ($user->employee) {
            $recommendations = PromotionRecommendation::with(['recommendedBy', 'targetDepartment'])
                ->where('employee_id', $user->employee->id)
                ->whereNotIn('status', ['draft'])
                ->latest()
                ->get();
        }

        return view('livewire.performance.my-promotions', [
            'recommendations' => $recommendations,
        ])->layout('layouts.app', ['title' => 'My Promotions & Rewards']);
    }
}
