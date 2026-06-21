<?php

namespace App\Livewire;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveEscalation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationsPage extends Component
{
    use WithPagination;

    public string $filter = 'all';     // all | unread | read

    public string $typeFilter = '';

    public string $priorityFilter = '';   // '' | high | normal | low

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, string> Selected notification ids for bulk actions. */
    public array $selected = [];

    public string $view = 'inbox';   // inbox | reminders

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['inbox', 'reminders'], true) ? $view : 'inbox';
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function setType(string $type): void
    {
        $this->typeFilter = $this->typeFilter === $type ? '' : $type;
        $this->resetPage();
    }

    public function setPriority(string $priority): void
    {
        $this->priorityFilter = $this->priorityFilter === $priority ? '' : $priority;
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function markSelectedRead(): void
    {
        if (empty($this->selected)) {
            return;
        }

        Auth::user()->notifications()
            ->whereIn('id', $this->selected)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->selected = [];
    }

    public function deleteSelected(): void
    {
        if (empty($this->selected)) {
            return;
        }

        Auth::user()->notifications()
            ->whereIn('id', $this->selected)
            ->delete();

        $this->selected = [];
    }

    public function markRead(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id)->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function delete(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id)->delete();
    }

    public function clearRead(): void
    {
        Auth::user()->notifications()->whereNotNull('read_at')->delete();
    }

    public function redirectTo(string $id): void
    {
        $notif = Auth::user()->notifications()->findOrFail($id);
        $notif->markAsRead();
        $url = $notif->data['url'] ?? null;
        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    /**
     * Whether the current user may see the cross-employee reminder center
     * (managers, directors, HR and super admins).
     */
    protected function canViewReminders(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isHrAdmin()
            || $user->isManager()
            || $user->role === UserRole::Director
            || Employee::where('manager_id', $user->id)->exists();
    }

    /**
     * Aggregate upcoming document expiries, probation reviews and unresolved
     * leave escalations, scoped to what the user is allowed to see.
     *
     * @return array{documents: Collection, probations: Collection, escalations: Collection}
     */
    protected function reminderData(User $user): array
    {
        $canSeeAll = $user->isSuperAdmin() || $user->isHrAdmin();
        $teamEmployeeIds = Employee::where('manager_id', $user->id)->pluck('id');

        $documents = Document::with('employee.user')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->addDays(60))
            ->when(! $canSeeAll, fn ($q) => $q->whereIn('employee_id', $teamEmployeeIds))
            ->orderBy('expires_at')
            ->limit(25)
            ->get();

        $probations = Employee::with('user', 'department')
            ->where('status', EmployeeStatus::Probation->value)
            ->whereNotNull('probation_end_date')
            ->whereDate('probation_end_date', '<=', now()->addDays(30))
            ->when(! $canSeeAll, fn ($q) => $q->whereIn('id', $teamEmployeeIds))
            ->orderBy('probation_end_date')
            ->limit(25)
            ->get();

        $escalations = LeaveEscalation::with(['leaveRequest.employee.user', 'leaveRequest.leaveType'])
            ->where('resolved', false)
            ->when(! $canSeeAll, fn ($q) => $q->where('escalated_to', $user->id))
            ->latest('escalated_at')
            ->limit(25)
            ->get();

        return compact('documents', 'probations', 'escalations');
    }

    public function render()
    {
        $user = Auth::user();

        // High priority surfaces first, then most recent.
        $query = $user->notifications()
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->latest();

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->typeFilter) {
            $query->where('data->type', $this->typeFilter);
        }

        if ($this->priorityFilter) {
            $query->where('priority', $this->priorityFilter);
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        // Distinct notification types for the filter pills
        $allTypes = $user->notifications()
            ->get()
            ->pluck('data.type')
            ->unique()
            ->filter()
            ->values();

        $canViewReminders = $this->canViewReminders($user);

        $reminders = ($this->view === 'reminders' && $canViewReminders)
            ? $this->reminderData($user)
            : ['documents' => collect(), 'probations' => collect(), 'escalations' => collect()];

        return view('livewire.notifications-page', [
            'notifications' => $query->paginate(20),
            'unreadCount' => $user->unreadNotifications()->count(),
            'allTypes' => $allTypes,
            'canViewReminders' => $canViewReminders,
            'reminders' => $reminders,
        ])->layout('layouts.app', ['title' => 'Notifications']);
    }
}
