<?php

namespace App\Livewire\Performance;

use App\Models\ReviewParticipant;
use App\Services\Performance\ParticipantService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * A reviewer's queue (v4 Phase D): every performance review where the
 * signed-in user is a participant — team lead, department head, or an
 * additional reviewer — with independent per-component score entry.
 * Self-assessments continue to live on the My Review page but appear here
 * too so one screen shows everything waiting on the user.
 */
class ReviewTasks extends Component
{
    public ?int $activeParticipantId = null;

    public bool $showForm = false;

    /** @var array<int, array{score: mixed, comment: string}> keyed by component id */
    public array $entries = [];

    public function openTask(int $participantId): void
    {
        $participant = $this->ownParticipant($participantId);
        $review = $participant->review;

        $this->activeParticipantId = $participant->id;
        $this->entries = [];

        foreach ($this->manualComponents($participant) as $component) {
            $existing = $participant->scores->firstWhere('component_id', $component->id);
            $this->entries[$component->id] = [
                'score' => $existing?->score,
                'comment' => $existing?->comment ?? '',
            ];
        }

        $this->showForm = true;
    }

    public function submit(ParticipantService $service): void
    {
        $participant = $this->ownParticipant($this->activeParticipantId);
        $components = $this->manualComponents($participant)->keyBy('id');

        $this->validate(
            collect($this->entries)->mapWithKeys(fn ($entry, $componentId) => [
                "entries.{$componentId}.score" => 'required|numeric|min:0|max:'.($components[$componentId]->max_score ?? 100),
            ])->all(),
            [],
            collect($this->entries)->mapWithKeys(fn ($entry, $componentId) => [
                "entries.{$componentId}.score" => $components[$componentId]->name ?? 'score',
            ])->all(),
        );

        $scores = collect($this->entries)->map(fn ($entry, $componentId) => [
            'component_id' => (int) $componentId,
            'score' => (float) $entry['score'],
            'comment' => trim($entry['comment']) ?: null,
        ])->values()->all();

        try {
            $service->submit($participant, $scores);
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showForm = false;
        $this->reset(['activeParticipantId', 'entries']);
        \Flux::toast('Review submitted — thank you.');
    }

    public function render()
    {
        $tasks = ReviewParticipant::with([
            'review.employee.user', 'review.employee.department',
            'review.performanceCycle', 'scores',
        ])
            ->where('reviewer_id', Auth::id())
            ->whereHas('review', fn ($q) => $q->where('status', '!=', 'locked'))
            ->orderByRaw("status = 'submitted'")
            ->orderByDesc('id')
            ->get();

        $active = $this->activeParticipantId
            ? $tasks->firstWhere('id', $this->activeParticipantId)
            : null;

        return view('livewire.performance.review-tasks', [
            'pending' => $tasks->where('status', 'pending'),
            'submitted' => $tasks->where('status', 'submitted'),
            'active' => $active,
            'activeComponents' => $active ? $this->manualComponents($active) : collect(),
        ])->layout('layouts.app', ['title' => 'Review Tasks']);
    }

    private function ownParticipant(?int $id): ReviewParticipant
    {
        return ReviewParticipant::with(['review.performanceCycle.template.components', 'review.template.components', 'scores'])
            ->where('reviewer_id', Auth::id())
            ->findOrFail($id);
    }

    /** Manual (human-scored) components of the review's template. */
    private function manualComponents(ReviewParticipant $participant)
    {
        $template = $participant->review->template ?? $participant->review->performanceCycle?->template;

        return $template
            ? $template->components->reject(fn ($c) => $c->isAutoScored())->values()
            : collect();
    }
}
