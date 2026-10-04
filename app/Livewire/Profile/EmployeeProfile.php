<?php

namespace App\Livewire\Profile;

use App\Exceptions\InvitationNotAllowed;
use App\Livewire\Profile\Concerns\ShowsProfileSummary;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Services\EmployeeInvitationService;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HR's view of an employee's profile.
 *
 * Deliberately the same components as the employee's own page, passed
 * `as-hr` so locked fields become editable. The difference between the two
 * surfaces is authority, not markup — duplicating the layout would guarantee
 * they drift apart.
 */
class EmployeeProfile extends Component
{
    use ShowsProfileSummary;

    public Employee $employee;

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    public ?string $editingField = null;

    public mixed $editingValue = null;

    /** Request currently being reviewed in the decision modal (set by openReview only). */
    #[Locked]
    public ?int $reviewingId = null;

    public string $reviewComment = '';

    public const TABS = [
        'overview' => 'Overview',
        'personal' => 'Personal',
        'employment' => 'Employment',
        'financial' => 'Financial',
        'requests' => 'Requests',
    ];

    public function mount(Employee $employee): void
    {
        $this->authorize('manage_employees');
        // Inside the viewer's reach (a department-scoped user sees their department).
        $this->authorize('view', $employee);

        $this->employee = $employee->load([
            'user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'employmentType', 'payrollSettings',
            'latestInvitation',
        ]);
    }

    /**
     * Issue this employee a login, or replace an invitation already out there.
     *
     * The same action as the employee list, offered here because this is the
     * screen HR is on when they finish checking an imported record.
     */
    public function inviteEmployee(EmployeeInvitationService $invitations): void
    {
        $this->authorize('invite', $this->employee);

        try {
            $invitation = $invitations->invite($this->employee, auth()->user());
        } catch (InvitationNotAllowed $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->employee->load('user', 'latestInvitation');

        \Flux::toast(
            'Invitation sent to '.$invitation->sent_to.'. It expires '.$invitation->expires_at->diffForHumans().'.'
        );
    }

    /** not_invited | invited | accepted | expired | active */
    public function getInvitationStateProperty(): string
    {
        return app(EmployeeInvitationService::class)->statusFor($this->employee);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS) && ($tab !== 'financial' || $this->canSeeFinancial())) {
            $this->activeTab = $tab;
        }
    }

    /**
     * Bank account, PAN, Aadhaar and CTC are compensation data (spec §4:
     * Finance / HR) — not for every holder of employee management.
     */
    public function canSeeFinancial(): bool
    {
        return Auth::user()->canViewFinanceProfile();
    }

    /**
     * HR does not edit their own record on the HR surface: bank, PAN and other
     * approval-tier data need a second person (My Profile -> change request).
     * The Super Admin is exempt — no one sits above them.
     */
    public function canEditHere(): bool
    {
        return (int) $this->employee->user_id !== (int) Auth::id() || Auth::user()->isSuperAdmin();
    }

    private function refuseOwnRecord(): void
    {
        abort_unless($this->canEditHere(), 403, 'Change your own details from My Profile — another HR user approves them.');
    }

    public function isFinancialField(string $field): bool
    {
        return $this->registryGroup($field) === 'financial';
    }

    /** A change-request value as this viewer may see it (financial values masked without view_finance_profile). */
    public function requestValue(ProfileChangeRequest $request, ?string $value): string
    {
        if ($value === null || $value === '') {
            return 'empty';
        }

        if ($this->isFinancialField($request->field) && ! $this->canSeeFinancial()) {
            return '•••• '.mb_substr($value, -2);
        }

        return $value;
    }

    /**
     * Empty fields this viewer may fill in here.
     *
     * @param  array<int, array{field: string, label: string}>  $missing
     * @return array<int, array{field: string, label: string}>
     */
    public function editableGaps(array $missing): array
    {
        if (! $this->canEditHere()) {
            return [];
        }

        return array_values(array_filter($missing, fn (array $gap) => $this->canSeeFinancial() || ! $this->isFinancialField($gap['field'])));
    }

    private function authorizeField(string $field): void
    {
        abort_if(($this->registryGroup($field) === 'financial') && ! $this->canSeeFinancial(), 403);
    }

    private function registryGroup(string $field): ?string
    {
        return Registry::get($field)['group'] ?? null;
    }

    // ── Direct edit (HR may write any registered field) ──────────────────────

    public function editField(string $field): void
    {
        $this->authorize('manage_employees');
        $this->refuseOwnRecord();

        if (! Registry::has($field)) {
            return;
        }

        $this->authorizeField($field);

        // Media is uploaded through the avatar control, not a text modal.
        if ((Registry::get($field)['type'] ?? null) === 'image') {
            \Flux::toast('Photos are uploaded from the employee record photo.');

            return;
        }

        // A few fields belong to a workflow that applies side effects of its
        // own; the service refuses them too, this just avoids a dead modal.
        if (! Registry::isHrEditable($field)) {
            \Flux::toast(Registry::lockReason($field), variant: 'warning');

            return;
        }

        $this->editingField = $field;
        $this->editingValue = Registry::valueFor($this->employee, $field);
        $this->modal('hr-edit-field')->show();
    }

    public function saveField(ProfileChangeService $service): void
    {
        $this->authorize('manage_employees');
        // Writing is editing the record: inside reach, and never a Super Admin's
        // record unless you are one (its login email is here).
        $this->authorize('update', $this->employee);
        $this->refuseOwnRecord();
        $this->authorizeField((string) $this->editingField);

        try {
            $service->updateAsHr($this->employee, $this->editingField, $this->editingValue, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('editingValue', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $label = Registry::label($this->editingField);
        $this->employee->refresh()->load(['user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'employmentType', 'payrollSettings']);

        $this->modal('hr-edit-field')->close();
        $this->editingField = null;
        \Flux::toast($label.' updated.', variant: 'success');
    }

    public function closeFieldModal(): void
    {
        $this->modal('hr-edit-field')->close();
        $this->editingField = null;
        $this->resetErrorBag();
    }

    // ── Reviewing change requests ───────────────────────────────────────────

    public function openReview(int $requestId): void
    {
        $this->authorize('approve_profile_changes');

        // Only a request belonging to the employee on this page.
        $this->reviewingId = ProfileChangeRequest::where('employee_id', $this->employee->id)->findOrFail($requestId)->id;
        $this->reviewComment = '';
        $this->modal('review-request')->show();
    }

    public function approveRequest(ProfileChangeService $service): void
    {
        $this->authorize('approve_profile_changes');

        $request = ProfileChangeRequest::where('employee_id', $this->employee->id)->findOrFail($this->reviewingId);

        try {
            $service->approve($request, Auth::user(), $this->comment());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->afterReview('Change approved and applied.');
    }

    public function rejectRequest(ProfileChangeService $service): void
    {
        $this->authorize('approve_profile_changes');

        $request = ProfileChangeRequest::where('employee_id', $this->employee->id)->findOrFail($this->reviewingId);

        try {
            $service->reject($request, Auth::user(), $this->comment());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->afterReview('Change rejected.');
    }

    private function comment(): ?string
    {
        return trim($this->reviewComment) !== '' ? trim($this->reviewComment) : null;
    }

    private function afterReview(string $message): void
    {
        $this->modal('review-request')->close();
        $this->reviewingId = null;
        $this->reviewComment = '';
        $this->employee->refresh()->load(['user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'employmentType', 'payrollSettings']);
        \Flux::toast($message, variant: 'success');
    }

    // ── Data ────────────────────────────────────────────────────────────────

    public function getPendingRequestsProperty()
    {
        return ProfileChangeRequest::where('employee_id', $this->employee->id)
            ->pending()->get()->keyBy('field');
    }

    public function render()
    {
        return view('livewire.profile.employee-profile', [
            'completion' => $this->summaryCompletion($this->employee),
            'kpis' => $this->summaryKpis($this->employee),
            'pending' => $this->pendingRequests,
            'requests' => ProfileChangeRequest::with(['requestedBy', 'reviewer'])
                ->where('employee_id', $this->employee->id)
                ->latest()->limit(20)->get(),
            'reviewing' => $this->reviewingId
                ? ProfileChangeRequest::with('requestedBy')->find($this->reviewingId)
                : null,
        ])->layout('layouts.app', ['title' => $this->employee->user?->name ?? 'Employee profile']);
    }
}
