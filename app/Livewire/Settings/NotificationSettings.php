<?php

namespace App\Livewire\Settings;

use App\Models\EmailLog;
use App\Models\NotificationSetting;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Support\Facades\Artisan;
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

    public function mount(): void
    {
        $this->authorize('manage-settings');
        $this->testEmail = (string) (auth()->user()?->email ?? '');
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

        return view('livewire.settings.notification-settings', compact('settings', 'queue', 'smtp', 'logs'))
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
