<?php

namespace App\Livewire;

use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AiContextBuilder;
use App\Services\AiAssistant;
use App\Services\Leave\EmployeeLeaveOverviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Floating, RBAC-scoped HR assistant. Renders nothing unless an
 * OPENAI_API_KEY is configured. Only ever sends the model data the
 * current role is already allowed to see.
 */
class AiCopilot extends Component
{
    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public string $input = '';

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

    /**
     * Role-scoped factual snapshot the model may answer from. Company/team
     * data is only included for the roles permitted to see it.
     *
     * @return array<string, mixed>
     */
    protected function scopedContext(User $user): array
    {
        $context = ['role' => $user->role->value, 'today' => now()->toDateString()];

        // Organisation figures only for permissions held, over their data scope.
        $context += app(AiContextBuilder::class)->organisation($user);

        // Everyone may see their own data.
        $employee = $user->employee;
        if ($employee) {
            $context['me'] = [
                'name' => $user->name,
                // The Conexus position from the one calculator every screen
                // uses: CSL + Comp Off, never floored; MDL dates are not a balance.
                'leave_balances' => $this->leaveContext($employee),
                'my_pending_leave_requests' => $employee->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count(),
            ];
        }

        return $context;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.ai-copilot', [
            'enabled' => $user ? app(AiAssistant::class)->enabledForUser($user) : false,
        ]);
    }

    /** @return array<string, mixed> */
    private function leaveContext(Employee $employee): array
    {
        $overview = app(EmployeeLeaveOverviewService::class)->for($employee);

        return array_filter([
            'available_leave_csl_plus_comp_off' => $overview['available_leave'] ?? null,
            'casual_sick_leave_available_to_request' => $overview['csl']['summary']['available_to_request'] ?? null,
            'casual_sick_leave_approved_balance' => $overview['csl']['summary']['approved_available'] ?? null,
            'casual_sick_leave_accrued_this_year' => isset($overview['csl']['summary']) ? round($overview['csl']['summary']['base'] + $overview['csl']['summary']['accrued'], 2) : null,
            'casual_sick_leave_rule' => $overview['policy']['grant'] ?? null,
            'comp_off' => $overview['comp_off']['summary']['approved_available'] ?? null,
            'mdl_shutdown_dates' => collect($overview['mdl']['dates'] ?? [])->map(fn ($d) => $d['date']->toDateString())->all(),
        ], fn ($value) => $value !== null);
    }
}
