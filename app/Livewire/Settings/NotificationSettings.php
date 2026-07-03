<?php

namespace App\Livewire\Settings;

use App\Mail\CustomBroadcastMail;
use App\Models\EmailLog;
use App\Models\MailSetting;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\AiAssistant;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * Admin control panel for every transactional email / notification:
 * per-type channel toggles + subject/body overrides, test send, SMTP status,
 * queue status with retry, and an outgoing-email log. (Phase 2 — Feature 1.)
 */
class NotificationSettings extends Component
{
    public string $search = '';

    // ── Edit modal ────────────────────────────────────────────────────────────
    public bool $showEditModal = false;

    public ?int $editingId = null;

    public string $custom_subject = '';

    public string $custom_body = '';

    // ── Test email ──────────────────────────────────────────────────────────────
    public string $testEmail = '';

    // ── Master mail switch ───────────────────────────────────────────────────────
    public bool $mailEnabled = true;

    // ── Compose & send broadcast ─────────────────────────────────────────────────
    public bool $showComposeModal = false;

    public string $composeSubject = '';

    public string $composeBody = '';

    public string $aiPrompt = '';

    public string $recipientSearch = '';

    /** @var array<int, string> selected user ids */
    public array $selectedRecipients = [];

    public bool $selectAllRecipients = false;

    public function mount(): void
    {
        $this->authorize('manage-settings');
        $this->testEmail = (string) (auth()->user()?->email ?? '');
        $this->mailEnabled = MailSetting::current()->mail_enabled;
    }

    /**
     * Flip the global master kill switch for all outgoing email.
     */
    public function toggleMasterMail(): void
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
     * Open a clean compose modal for a one-off broadcast.
     */
    public function openCompose(): void
    {
        $this->authorize('manage-settings');

        $this->reset([
            'composeSubject', 'composeBody', 'aiPrompt',
            'selectedRecipients', 'selectAllRecipients', 'recipientSearch',
        ]);
        $this->resetValidation();
        $this->showComposeModal = true;
    }

    /**
     * Keep the selection in sync when the "select all" box is toggled.
     */
    public function updatedSelectAllRecipients(bool $value): void
    {
        $this->selectedRecipients = $value
            ? $this->recipientQuery()->pluck('id')->map(fn ($id): string => (string) $id)->all()
            : [];
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

        $this->composeSubject = $draft['subject'];
        $this->composeBody = $draft['body'];

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
     * Send the composed broadcast to every selected recipient.
     */
    public function sendBroadcast(): void
    {
        $this->authorize('manage-settings');

        $this->validate(
            [
                'composeSubject' => ['required', 'string', 'max:255'],
                'composeBody' => ['required', 'string', 'max:5000'],
                'selectedRecipients' => ['required', 'array', 'min:1'],
            ],
            [],
            ['selectedRecipients' => 'recipients'],
        );

        if (! MailSetting::current()->mail_enabled) {
            \Flux::toast('Outgoing email is paused by the master switch. Enable it before sending.', variant: 'danger');

            return;
        }

        $users = User::query()
            ->whereIn('id', $this->selectedRecipients)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        $sent = 0;
        $failed = 0;

        foreach ($users as $recipient) {
            try {
                Mail::to($recipient->email, $recipient->name)
                    ->send(new CustomBroadcastMail($this->composeSubject, $this->composeBody));
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->showComposeModal = false;
        $this->reset([
            'composeSubject', 'composeBody', 'aiPrompt',
            'selectedRecipients', 'selectAllRecipients', 'recipientSearch',
        ]);

        \Flux::toast(
            $failed === 0
                ? "Broadcast sent to {$sent} recipient(s)."
                : "Sent to {$sent}, {$failed} failed. Check the email log below.",
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
            ->when($this->recipientSearch !== '', function (Builder $q): void {
                $q->where(function (Builder $w): void {
                    $w->where('name', 'like', '%'.$this->recipientSearch.'%')
                        ->orWhere('email', 'like', '%'.$this->recipientSearch.'%');
                });
            })
            ->orderBy('name');
    }

    /**
     * Flip a boolean channel flag for a single notification type.
     */
    public function toggle(int $id, string $field): void
    {
        $this->authorize('manage-settings');

        if (! in_array($field, ['mail_enabled', 'database_enabled', 'is_automatic'], true)) {
            return;
        }

        $setting = NotificationSetting::findOrFail($id);
        $setting->update([$field => ! $setting->{$field}]);

        \Flux::toast('Setting updated.', variant: 'success');
    }

    public function openEdit(int $id): void
    {
        $this->authorize('manage-settings');

        $setting = NotificationSetting::findOrFail($id);
        $this->editingId = $setting->id;
        $this->custom_subject = (string) ($setting->custom_subject ?? '');
        $this->custom_body = (string) ($setting->custom_body ?? '');
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('manage-settings');

        $this->validate([
            'custom_subject' => ['nullable', 'string', 'max:255'],
            'custom_body' => ['nullable', 'string', 'max:2000'],
        ]);

        $setting = NotificationSetting::findOrFail($this->editingId);
        $setting->update([
            'custom_subject' => $this->custom_subject ?: null,
            'custom_body' => $this->custom_body ?: null,
        ]);

        $this->showEditModal = false;
        \Flux::toast('Template saved.', variant: 'success');
    }

    /**
     * Send a one-off test email through the real mail pipeline (also exercises
     * SMTP and writes to the email log).
     */
    public function sendTest(): void
    {
        $this->authorize('manage-settings');

        $this->validate(['testEmail' => ['required', 'email']]);

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').'. If you received it, your SMTP configuration is working.',
                function ($message): void {
                    $message->to($this->testEmail)
                        ->subject('['.config('app.name').'] Test Email');
                    $message->getHeaders()->addTextHeader('X-Notification-Key', 'system.test');
                }
            );

            \Flux::toast('Test email sent to '.$this->testEmail, variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast('SMTP error: '.$e->getMessage(), variant: 'danger');
        }
    }

    public function retryFailed(): void
    {
        $this->authorize('manage-settings');

        Artisan::call('queue:retry', ['id' => ['all']]);
        \Flux::toast('Failed jobs re-queued.', variant: 'success');
    }

    public function clearFailed(): void
    {
        $this->authorize('manage-settings');

        Artisan::call('queue:flush');
        \Flux::toast('Failed jobs cleared.', variant: 'success');
    }

    /**
     * Re-scan the codebase for new notification types.
     */
    public function syncCatalog(NotificationCatalog $catalog): void
    {
        $this->authorize('manage-settings');

        $created = $catalog->sync();
        \Flux::toast($created.' new notification type(s) discovered.', variant: 'success');
    }

    public function render()
    {
        $settings = NotificationSetting::query()
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($w): void {
                    $w->where('label', 'like', '%'.$this->search.'%')
                        ->orWhere('group', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        $queue = [
            'pending' => $this->tableCount('jobs'),
            'failed' => $this->tableCount('failed_jobs'),
        ];

        $smtp = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'from' => config('mail.from.address'),
            'queue' => config('queue.default'),
        ];

        $logs = EmailLog::query()->latest()->limit(20)->get();

        $recipientList = $this->recipientQuery()
            ->with(['employee:id,user_id,department_id', 'employee.department:id,name'])
            ->get(['id', 'name', 'email']);

        $aiEnabled = Auth::user() ? app(AiAssistant::class)->enabledForUser(Auth::user()) : false;

        return view('livewire.settings.notification-settings', compact('settings', 'queue', 'smtp', 'logs', 'recipientList', 'aiEnabled'))
            ->layout('layouts.app', ['title' => 'Notifications & Email']);
    }

    private function tableCount(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
