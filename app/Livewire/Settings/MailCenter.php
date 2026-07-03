<?php

namespace App\Livewire\Settings;

use App\Mail\CustomBroadcastMail;
use App\Models\EmailLog;
use App\Models\MailSetting;
use App\Models\User;
use App\Services\AiAssistant;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * Settings → Mail Center: a global master kill switch for all outgoing email,
 * plus an ad-hoc broadcast composer (subject + body, "Draft with AI" from a
 * short instruction, and recipient selection) for Super Admins and HR Admins.
 */
class MailCenter extends Component
{
    /** Mirror of the global master switch, hydrated in {@see mount()}. */
    public bool $mailEnabled = true;

    // ── Composer ────────────────────────────────────────────────────────────────
    public string $subject = '';

    public string $body = '';

    public string $aiPrompt = '';

    // ── Recipients ──────────────────────────────────────────────────────────────
    public string $search = '';

    /** @var array<int, string> selected user ids */
    public array $selected = [];

    public bool $selectAll = false;

    public function mount(): void
    {
        $this->authorize('manage-settings');
        $this->mailEnabled = MailSetting::current()->mail_enabled;
    }

    /**
     * Flip the global master kill switch for all outgoing email.
     */
    public function toggleMaster(): void
    {
        $this->authorize('manage-settings');

        $setting = MailSetting::current();
        $setting->update(['mail_enabled' => ! $setting->mail_enabled]);
        $this->mailEnabled = $setting->mail_enabled;

        \Flux::toast(
            $this->mailEnabled ? 'All outgoing email is now enabled.' : 'All outgoing email is now paused.',
            variant: $this->mailEnabled ? 'success' : 'warning',
        );
    }

    /**
     * Draft a subject + body from a short instruction using the AI assistant.
     */
    public function draftWithAi(): void
    {
        $this->authorize('manage-settings');

        $this->validate(
            ['aiPrompt' => ['required', 'string', 'max:500']],
            [],
            ['aiPrompt' => 'instruction'],
        );

        $ai = app(AiAssistant::class);
        $user = Auth::user();

        if (! $ai->enabledForUser($user)) {
            \Flux::toast('AI assistant is not enabled. Configure it in Settings → AI Assistant.', variant: 'danger');

            return;
        }

        $system = 'You draft internal company emails for an HR team. '
            .'Return ONLY a JSON object with exactly two string keys: "subject" and "body". '
            .'The subject must be under 120 characters. The body should be a warm, professional, '
            .'ready-to-send announcement addressed to "Dear team," and signed off from the HR team. '
            .'Do not wrap the JSON in code fences and do not use markdown or placeholders.';

        try {
            $draft = $this->parseAiDraft($ai->ask($system, trim($this->aiPrompt)));
        } catch (\Throwable) {
            \Flux::toast('AI could not draft that right now. Please try again.', variant: 'danger');

            return;
        }

        if ($draft === null) {
            \Flux::toast('AI returned an unexpected response. Please try again.', variant: 'danger');

            return;
        }

        $this->subject = $draft['subject'];
        $this->body = $draft['body'];

        \Flux::toast('Draft ready — review and edit before sending.', variant: 'success');
    }

    /**
     * Parse the AI's JSON reply into a subject/body pair, tolerating stray code
     * fences the model may add.
     *
     * @return array{subject: string, body: string}|null
     */
    protected function parseAiDraft(string $raw): ?array
    {
        $clean = trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));
        $data = json_decode($clean, true);

        if (is_array($data) && isset($data['subject'], $data['body'])) {
            return [
                'subject' => trim((string) $data['subject']),
                'body' => trim((string) $data['body']),
            ];
        }

        return null;
    }

    /**
     * Keep the selection in sync when the "select all" box is toggled.
     */
    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->recipientQuery()->pluck('id')->map(fn ($id): string => (string) $id)->all()
            : [];
    }

    /**
     * Send the composed broadcast to every selected recipient.
     */
    public function send(): void
    {
        $this->authorize('manage-settings');

        $this->validate(
            [
                'subject' => ['required', 'string', 'max:255'],
                'body' => ['required', 'string', 'max:5000'],
                'selected' => ['required', 'array', 'min:1'],
            ],
            [],
            ['selected' => 'recipients'],
        );

        if (! MailSetting::current()->mail_enabled) {
            \Flux::toast('Outgoing email is paused by the master switch. Enable it before sending.', variant: 'danger');

            return;
        }

        $recipients = User::query()
            ->whereIn('id', $this->selected)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email, $recipient->name)
                    ->send(new CustomBroadcastMail($this->subject, $this->body));
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->reset(['subject', 'body', 'aiPrompt', 'selected', 'selectAll', 'search']);

        \Flux::toast(
            $failed === 0
                ? "Broadcast sent to {$sent} recipient(s)."
                : "Sent to {$sent}, {$failed} failed. Check the email log.",
            variant: $failed === 0 ? 'success' : 'warning',
        );
    }

    /**
     * Base query for selectable recipients: every employee-linked user that has
     * an email address, regardless of employment status, filtered by search.
     *
     * @return Builder<User>
     */
    protected function recipientQuery(): Builder
    {
        return User::query()
            ->whereHas('employee')
            ->whereNotNull('email')
            ->when($this->search !== '', function (Builder $q): void {
                $q->where(function (Builder $w): void {
                    $w->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name');
    }

    public function render()
    {
        $recipients = $this->recipientQuery()
            ->with(['employee:id,user_id,employee_code,department_id', 'employee.department:id,name'])
            ->get(['id', 'name', 'email']);

        $logs = EmailLog::query()
            ->where('notification_key', 'custom.broadcast')
            ->latest()
            ->limit(15)
            ->get();

        return view('livewire.settings.mail-center', [
            'recipients' => $recipients,
            'logs' => $logs,
            'mailer' => config('mail.default'),
            'aiEnabled' => Auth::user() ? app(AiAssistant::class)->enabledForUser(Auth::user()) : false,
        ])->layout('layouts.app', ['title' => 'Mail Center']);
    }
}
