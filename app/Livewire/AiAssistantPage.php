<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OtRequest;
use App\Models\PipRecord;
use App\Models\User;
use App\Models\WarningLetter;
use App\Services\AiAssistant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('HR AI Assistant')]
class AiAssistantPage extends Component
{
    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public string $input = '';

    public ?string $activeQuestion = null;

    /** @var array<int, array{label: string, prompt: string, icon: string}> */
    public array $quickQuestions = [
        ['label' => 'My Leave Balance', 'prompt' => 'What is my current leave balance?', 'icon' => 'calendar-days'],
        ['label' => 'Pending Approvals', 'prompt' => 'How many leave or OT requests are pending my action?', 'icon' => 'clock'],
        ['label' => 'Headcount Today', 'prompt' => 'What is the active headcount and how many are on probation?', 'icon' => 'users'],
        ['label' => 'Documents Expiring', 'prompt' => 'Are there any employee documents expiring in the next 30 days?', 'icon' => 'document-text'],
        ['label' => 'Active PIPs', 'prompt' => 'How many employees are currently on a PIP?', 'icon' => 'exclamation-triangle'],
        ['label' => 'Active Warnings', 'prompt' => 'Are there any active warning letters issued to employees?', 'icon' => 'shield-exclamation'],
        ['label' => 'My Pending Requests', 'prompt' => 'Do I have any pending leave requests waiting for approval?', 'icon' => 'inbox'],
        ['label' => 'Team Overview', 'prompt' => 'Give me a quick overview of my team size and any pending requests.', 'icon' => 'presentation-chart-line'],
    ];

    public function sendQuick(string $prompt): void
    {
        $this->activeQuestion = $prompt;
        $this->input = $prompt;
        $this->send();
    }

    public function send(): void
    {
        $ai = app(AiAssistant::class);
        $question = trim($this->input);
        $user = Auth::user();

        if (! $ai->enabledForUser($user) || $question === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->input = '';

        $system = 'You are Pulse HR Copilot, an assistant inside a single-company HRMS. '
            ."The current user's role is {$user->role->value}. "
            .'Answer ONLY using the CONTEXT JSON below. If the answer is not present in the context, say you do not have that data and point the user to the relevant module. '
            .'Never reveal information about other employees to a non-manager/non-admin. Keep answers short and factual.'
            ."\n\nCONTEXT:\n".json_encode($this->scopedContext($user), JSON_PRETTY_PRINT);

        try {
            $reply = $ai->ask($system, $question);
        } catch (\Throwable $e) {
            $reply = 'Sorry, I could not process that right now. Please try again later.';
        }

        $this->messages[] = ['role' => 'assistant', 'content' => $reply];
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->activeQuestion = null;
    }

    /** @return array<string, mixed> */
    protected function scopedContext(User $user): array
    {
        $context = ['role' => $user->role->value, 'today' => now()->toDateString()];

        if ($user->isSuperAdmin() || $user->isHrAdmin()) {
            $context['company'] = [
                'active_headcount' => Employee::where('status', 'active')->count(),
                'on_probation' => Employee::where('status', 'probation')->count(),
                'pending_leave_requests' => LeaveRequest::where('status', 'pending')->count(),
                'pending_ot_requests' => OtRequest::where('status', 'pending')->count(),
                'documents_expiring_30d' => Document::query()->expiringSoon(30)->count(),
                'employees_on_pip' => PipRecord::whereIn('status', ['active', 'under_review', 'extended'])->count(),
                'active_warnings' => WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review'])->count(),
            ];
        } elseif ($user->isManager() || $user->role === UserRole::Director) {
            $teamIds = Employee::where('manager_id', $user->id)->pluck('id');
            $context['team'] = [
                'team_size' => $teamIds->count(),
                'pending_leave_requests' => LeaveRequest::whereIn('employee_id', $teamIds)->where('status', 'pending')->count(),
                'pending_ot_requests' => OtRequest::whereIn('employee_id', $teamIds)->where('status', 'pending')->count(),
            ];
        }

        $employee = $user->employee;
        if ($employee) {
            $context['me'] = [
                'name' => $user->name,
                'leave_balances' => $employee->leaveBalances()->with('leaveType')->where('year', now()->year)->get()
                    ->mapWithKeys(fn ($b) => [($b->leaveType?->name ?? 'Leave') => max(0, (float) $b->allocated_days - (float) $b->used_days)]),
                'my_pending_leave_requests' => $employee->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count(),
            ];
        }

        return $context;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.ai-assistant-page', [
            'enabled' => $user ? app(AiAssistant::class)->enabledForUser($user) : false,
        ])->layout('layouts.app');
    }
}
