<?php

namespace App\Services\Ai;

use App\Enums\DataScope;
use App\Models\AiSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiAssistant;
use App\Services\Security\ScopeResolver;
use Throwable;

/**
 * One answer to one question, shared by the AI Assistant page and the floating
 * copilot. In order:
 *
 *  1. Pulse data tools ({@see AiIntentRouter}) — exact counts and balances,
 *     authorised for this user. No model involved.
 *  2. The optional external model — only when configured and allowed for the
 *     role, given the minimum authorised figures, told to use no other numbers.
 *  3. Help mode — where in Pulse to find it, with links the user can open.
 *
 * Every question is audited (intent, source, whether anything left Pulse);
 * the question text itself is not stored.
 */
class AiConversation
{
    public function __construct(
        private AiIntentRouter $router,
        private AiHelpLinks $links,
        private AiDataTools $tools,
        private AiContextBuilder $organisation,
        private AiAssistant $assistant,
    ) {}

    /**
     * Whether the assistant is switched on for this user. The external model
     * is optional: without a key the assistant still answers from Pulse data
     * and help.
     */
    public function availableFor(User $user): bool
    {
        $setting = AiSetting::current();

        return $setting->enabled && $setting->roleAllowed($user->role->value);
    }

    /**
     * Suggested questions for this user, chosen by what they may see (own
     * data, an approval queue, company figures) — never by role name.
     *
     * @return array<string, list<array{label: string, prompt: string, icon: string}>>
     */
    public function suggestedQuestions(User $user): array
    {
        $groups = [];

        if ($user->employee) {
            $groups['My requests'] = [
                ['label' => 'My OT requests', 'prompt' => 'How many OT requests do I have?', 'icon' => 'bolt'],
                ['label' => 'My pending OT', 'prompt' => 'Do I have any pending OT requests?', 'icon' => 'clock'],
                ['label' => 'My leave balance', 'prompt' => 'What is my leave balance?', 'icon' => 'calendar-days'],
                ['label' => 'My pending requests', 'prompt' => 'Do I have any pending requests waiting for approval?', 'icon' => 'inbox'],
                ['label' => 'Fix a missed punch', 'prompt' => 'Where do I regularise a missed punch?', 'icon' => 'finger-print'],
            ];
        }

        $queue = $this->tools->approvalQueue($user);
        if ($queue !== []) {
            $approvals = [['label' => 'Waiting for my approval', 'prompt' => 'What is waiting for my approval?', 'icon' => 'check-badge']];
            if (isset($queue['overtime'])) {
                $approvals[] = ['label' => 'OT to approve', 'prompt' => 'How many OT requests are pending my approval?', 'icon' => 'bolt'];
            }
            if (isset($queue['leave'])) {
                $approvals[] = ['label' => 'Leave to approve', 'prompt' => 'How many leave requests are pending my approval?', 'icon' => 'calendar-days'];
            }
            $groups['Approvals'] = $approvals;
        }

        $company = array_filter([
            $user->hasPermission('view_employee') ? ['label' => 'Headcount', 'prompt' => 'What is the active headcount and how many are on probation?', 'icon' => 'users'] : null,
            $user->hasPermission('manage_documents') ? ['label' => 'Documents expiring', 'prompt' => 'Are any employee documents expiring in the next 30 days?', 'icon' => 'document-text'] : null,
            $user->hasPermission('manage_pip') ? ['label' => 'Active PIPs', 'prompt' => 'How many employees are currently on a PIP?', 'icon' => 'exclamation-triangle'] : null,
            $user->hasPermission('manage_warning_letters') ? ['label' => 'Active warnings', 'prompt' => 'How many active warning letters are there?', 'icon' => 'shield-exclamation'] : null,
        ]);
        if ($company !== []) {
            $groups['Organisation'] = array_values($company);
        }

        $groups['Help'] = [
            ['label' => 'Change date format', 'prompt' => 'How do I change my date and time format?', 'icon' => 'adjustments-horizontal'],
            ['label' => 'Notification settings', 'prompt' => 'Where do I turn notifications on or off?', 'icon' => 'bell'],
        ];

        return $groups;
    }

    /**
     * The widest data scope behind anything the assistant may answer for this
     * user — shown as the page's scope badge.
     */
    public function scopeLabel(User $user): string
    {
        $scopes = app(ScopeResolver::class);

        $widest = collect(['view_employee', 'approve_leave', 'approve_overtime', 'approve_wfh', 'hr_approve_regularisation'])
            ->filter(fn (string $permission) => $user->hasPermission($permission))
            ->map(fn (string $permission) => $scopes->scopeFor($user, $permission))
            ->sortByDesc(fn (DataScope $scope) => $scope->rank())
            ->first();

        return match ($widest) {
            DataScope::All => 'Company-wide',
            DataScope::Department => 'Your department',
            DataScope::SelectedDepartments => 'Selected departments',
            DataScope::Team => 'Your team',
            default => 'Your data only',
        };
    }

    /**
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}
     */
    public function respond(User $user, string $question): array
    {
        $question = trim($question);

        $answer = $this->router->answer($user, $question);
        $contextKeys = [];

        if ($answer === null && $this->assistant->enabledForUser($user)) {
            $context = $this->context($user);
            $contextKeys = array_keys($context);

            try {
                $reply = $this->assistant->ask($this->systemPrompt($user, $context), $question);

                $answer = [
                    'content' => $reply !== '' ? $reply : $this->helpText($user, $question),
                    'links' => $this->links->pagesForQuestion($user, $question),
                    'source' => 'ai',
                    'intent' => 'model',
                ];
            } catch (Throwable $e) {
                report($e);
                $answer = $this->help($user, $question, 'model_unavailable');
            }
        }

        $answer ??= $this->help($user, $question, 'help');

        AuditLog::record($user, 'ai.asked', null, [
            'intent' => $answer['intent'],
            'source' => $answer['source'],
            'sent_to_external_model' => $answer['source'] === 'ai',
            'context_sections' => $contextKeys,
        ], context: ['module' => 'ai', 'category' => 'assistant', 'event' => 'ai.asked']);

        return $answer;
    }

    /**
     * The minimum authorised figures a model may phrase an answer from: no
     * names, no other employee's records, aggregate counts only, and only for
     * permissions the user holds over their data scope.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $context = ['role' => $user->role->value, 'today' => now()->toDateString()];

        $context += $this->organisation->organisation($user);

        if ($user->employee) {
            $context['me'] = array_filter([
                'leave_balances' => $this->tools->myLeave($user),
                'my_pending_requests' => $this->tools->myPendingRequests($user),
                'my_overtime' => $this->tools->myOvertime($user),
            ], fn ($value) => $value !== null);
        }

        $queue = $this->tools->approvalQueue($user);
        if ($queue !== []) {
            $context['waiting_for_my_approval'] = $queue;
        }

        // Aggregates only (never a per-person row), from the same services as the screens.
        if ($user->hasPermission('view_attendance')) {
            $attendance = $this->tools->attendanceToday($user);
            $context['attendance_today'] = ['scope' => $attendance['scope']] + collect($attendance['summary'])->except('rows')->all();
        }

        if ($reviews = $this->tools->reviews($user)) {
            $context['reviews'] = collect($reviews)->except(['by_employee', 'cycle'])->all() + ['cycle' => $reviews['cycle']?->name];
        }

        return $context;
    }

    /** @param  array<string, mixed>  $context */
    private function systemPrompt(User $user, array $context): string
    {
        return 'You are the Pulse HR assistant inside a single-company HRMS. '
            ."The current user's role is {$user->role->value}. "
            .'Answer ONLY from the CONTEXT JSON below. Never invent, estimate or recompute a number: quote figures exactly as they appear in CONTEXT. '
            .'"me.my_pending_requests" are requests the user submitted; "waiting_for_my_approval" are other people\'s requests waiting for the user — never mix them. '
            .'If the answer is not in CONTEXT, say Pulse has not shared that with you and suggest the relevant page; do not guess. '
            .'Never reveal information about individual employees. Keep answers short and factual.'
            ."\n\nCONTEXT:\n".json_encode($context, JSON_PRETTY_PRINT);
    }

    /**
     * @return array{content: string, links: list<array{label: string, url: string, path: string}>, source: string, intent: string}
     */
    private function help(User $user, string $question, string $intent): array
    {
        return [
            'content' => $this->helpText($user, $question),
            'links' => $this->links->pagesForQuestion($user, $question),
            'source' => 'help',
            'intent' => $intent,
        ];
    }

    private function helpText(User $user, string $question): string
    {
        $pages = $this->links->pagesForQuestion($user, $question);

        if ($pages === []) {
            return "I can't answer that from your Pulse data. Please contact HR if you need help.";
        }

        return "I can't answer that from your Pulse data yet. You can check it under: ".$pages[0]['path'].'.';
    }
}
