<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Notify Others: the extra people chosen to tell about a leave application
 * (the employee's request forms) or a decision (HR's "Also notify" on the
 * request page). Only for a role holding notify_others_on_leave (Roles &
 * Permissions). The view is livewire.time-off.partials.notify-recipients.
 */
trait ChoosesLeaveRecipients
{
    /** The most people one application or decision may add. */
    public const MAX_NOTIFY_RECIPIENTS = 10;

    /**
     * The people chosen so far.
     *
     * @var array<int, int>
     */
    public array $notifyUserIds = [];

    public string $recipientSearch = '';

    public function canNotifyOthers(): bool
    {
        return (bool) Auth::user()?->hasPermission('notify_others_on_leave');
    }

    public function addRecipient(int $userId): void
    {
        abort_unless($this->canNotifyOthers(), 403);

        if (in_array($userId, $this->recipientHiddenIds(), true) || count($this->notifyUserIds) >= self::MAX_NOTIFY_RECIPIENTS) {
            $this->recipientSearch = '';

            return;
        }

        if (User::whereKey($userId)->exists() && ! in_array($userId, array_map('intval', $this->notifyUserIds), true)) {
            $this->notifyUserIds[] = $userId;
        }
        $this->recipientSearch = '';
    }

    public function removeRecipient(int $userId): void
    {
        $this->notifyUserIds = array_values(array_filter($this->notifyUserIds, fn ($id) => (int) $id !== $userId));
    }

    /**
     * The chosen people a submit may use: none without the permission, and
     * never the viewer or the employee the leave is for.
     *
     * @return array<int, int>
     */
    protected function chosenRecipientIds(): array
    {
        if (! $this->canNotifyOthers()) {
            return [];
        }

        $hidden = $this->recipientHiddenIds();

        return collect($this->notifyUserIds)->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id <= 0 || in_array($id, $hidden, true))
            ->unique()->take(self::MAX_NOTIFY_RECIPIENTS)->values()->all();
    }

    /**
     * The chips to show: the people chosen so far, the suggestions and —
     * when searching — anyone with a login by name or email.
     *
     * @return Collection<int, array{id: int, name: string, note: ?string, selected: bool}>
     */
    #[Computed]
    public function recipientOptions(): Collection
    {
        $selected = array_map('intval', $this->notifyUserIds);
        $hidden = $this->recipientHiddenIds();
        $term = trim($this->recipientSearch);
        $found = mb_strlen($term) >= 2
            ? User::where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))->orderBy('name')->limit(8)->get()
            : collect();

        return collect()
            ->merge(User::whereIn('id', $selected)->get()->map(fn (User $u) => [$u, null]))
            ->merge($this->recipientSuggestions())
            ->merge($found->map(fn (User $u) => [$u, null]))
            ->reject(fn ($pair) => in_array((int) $pair[0]->id, $hidden, true))
            ->unique(fn ($pair) => $pair[0]->id)
            ->map(fn ($pair) => ['id' => (int) $pair[0]->id, 'name' => $pair[0]->name, 'note' => $pair[1] ?? $pair[0]->email, 'selected' => in_array((int) $pair[0]->id, $selected, true)])
            ->values();
    }

    /**
     * People offered before any search, each with why.
     *
     * @return array<int, array{0: User, 1: string}>
     */
    protected function recipientSuggestions(): array
    {
        return [];
    }

    /**
     * People never offered or added: the viewer and, where known, the
     * employee the leave is for.
     *
     * @return array<int, int>
     */
    protected function recipientHiddenIds(): array
    {
        return array_values(array_filter([(int) Auth::id(), (int) $this->recipientEmployeeUserId()]));
    }

    /** The login of the employee the leave is for, when known. */
    protected function recipientEmployeeUserId(): ?int
    {
        return null;
    }
}
