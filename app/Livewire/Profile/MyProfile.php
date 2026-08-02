<?php

namespace App\Livewire\Profile;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\ProfileChangeRequest;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileCompletionService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The employee's own profile.
 *
 * Deliberately thin: it decides *nothing* about which fields are editable —
 * ProfileFieldRegistry does — and performs no writes itself, delegating every
 * mutation to ProfileChangeService so the tier rules hold no matter which
 * surface calls them.
 */
class MyProfile extends Component
{
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    /** Field currently open in the edit or request modal. */
    public ?string $editingField = null;

    public mixed $editingValue = null;

    public string $requestReason = '';

    public $photo;

    public const TABS = [
        'overview' => 'Overview',
        'personal' => 'Personal',
        'employment' => 'Employment',
        'requests' => 'Requests',
    ];

    public function mount(): void
    {
        abort_unless($this->employee !== null, 403, 'Your account is not linked to an employee record.');
    }

    /** The signed-in user's employee record. */
    public function getEmployeeProperty(): ?Employee
    {
        return Auth::user()?->employee?->loadMissing([
            'user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'employmentType', 'payrollSettings',
        ]);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->activeTab = $tab;
        }
    }

    // ── Editing ─────────────────────────────────────────────────────────────

    public function editField(string $field): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        if (! Registry::isEditable($field)) {
            \Flux::toast(Registry::label($field).' cannot be edited here.', variant: 'danger');

            return;
        }

        // Media is uploaded through the avatar control, not the text modal —
        // routing it here would present an unusable text box.
        if ((Registry::get($field)['type'] ?? null) === 'image') {
            \Flux::toast('Use the camera button on your photo to upload a new one.');

            return;
        }

        $this->editingField = $field;
        $this->editingValue = Registry::valueFor($this->employee, $field);
        $this->modal('edit-field')->show();
    }

    public function saveField(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        try {
            $service->updateEditable($this->employee, $this->editingField, $this->editingValue, Auth::user());
        } catch (ValidationException $e) {
            // Surface the registry's own message against the modal input.
            $this->addError('editingValue', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->closeFieldModal();
        \Flux::toast(Registry::label($this->editingField ?? '').' updated.', variant: 'success');
        $this->editingField = null;
    }

    // ── Requesting ──────────────────────────────────────────────────────────

    public function requestField(string $field): void
    {
        abort_unless(Auth::user()->hasPermission('request_profile_change'), 403);

        if (! Registry::needsApproval($field)) {
            \Flux::toast(Registry::label($field).' does not use the request flow.', variant: 'danger');

            return;
        }

        $this->editingField = $field;
        $this->editingValue = Registry::valueFor($this->employee, $field);
        $this->requestReason = '';
        $this->modal('request-field')->show();
    }

    public function submitRequest(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('request_profile_change'), 403);

        try {
            $service->requestChange(
                $this->employee,
                $this->editingField,
                $this->editingValue,
                Auth::user(),
                trim($this->requestReason) !== '' ? trim($this->requestReason) : null,
            );
        } catch (ValidationException $e) {
            $this->addError('editingValue', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->modal('request-field')->close();
        \Flux::toast('Sent to HR for review.', variant: 'success');
        $this->editingField = null;
        $this->requestReason = '';
    }

    public function withdrawRequest(int $requestId, ProfileChangeService $service): void
    {
        $request = ProfileChangeRequest::findOrFail($requestId);

        try {
            $service->cancel($request, Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Request withdrawn.');
    }

    public function viewRequest(int $requestId): void
    {
        $this->activeTab = 'requests';
    }

    public function closeFieldModal(): void
    {
        $this->modal('edit-field')->close();
        $this->resetErrorBag();
    }

    // ── Photo ───────────────────────────────────────────────────────────────

    public function updatedPhoto(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        $this->validate(['photo' => Registry::rulesFor('photo')]);

        $path = $this->photo->store('employee-photos', 'public');
        $service->updateEditable($this->employee, 'photo', $path, Auth::user());

        $this->reset('photo');
        \Flux::toast('Photo updated.', variant: 'success');
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /** Pending requests keyed by field, so a field row can show its own state. */
    public function getPendingRequestsProperty()
    {
        return ProfileChangeRequest::where('employee_id', $this->employee->id)
            ->pending()->get()->keyBy('field');
    }

    /**
     * Five headline numbers. Kept to cheap aggregates — the profile shell must
     * not become the slowest page in the app.
     */
    public function getKpisProperty(): array
    {
        $employee = $this->employee;
        $from = now()->startOfMonth();

        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), now()->toDateString()])
            ->selectRaw('COUNT(*) as total, SUM(check_in IS NOT NULL) as present')
            ->first();

        $workingDays = max(1, AttendanceSetting::workingDaysBetween($from, now()));
        $attendancePct = min(100, (int) round(((int) ($attendance->present ?? 0)) / $workingDays * 100));

        $balances = LeaveBalance::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', now()->year)
            ->get();

        $leaveAvailable = round($balances->sum(fn (LeaveBalance $b) => $b->available()), 1);
        $compOff = round(
            $balances->filter(fn (LeaveBalance $b) => $b->leaveType?->category === 'comp_off')
                ->sum(fn (LeaveBalance $b) => (float) ($b->comp_off_credits ?? 0)),
            1
        );

        // Average of the engine's own daily scores this month, so the profile
        // agrees with the attendance module rather than computing its own.
        $score = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), now()->toDateString()])
            ->avg('score');

        $tenure = $employee->joining_date
            ? $employee->joining_date->diff(now())
            : null;

        return [
            'attendance' => $attendancePct,
            'leave' => $leaveAvailable,
            'comp_off' => $compOff,
            'score' => $score !== null ? round((float) $score) : null,
            'tenure' => $tenure ? $tenure->y.'y '.$tenure->m.'m' : 'Not set',
        ];
    }

    public function render()
    {
        return view('livewire.profile.my-profile', [
            'employee' => $this->employee,
            'completion' => app(ProfileCompletionService::class)->for($this->employee),
            'pending' => $this->pendingRequests,
            'kpis' => $this->kpis,
            'requests' => ProfileChangeRequest::with(['reviewer', 'requestedBy'])
                ->where('employee_id', $this->employee->id)
                ->latest()->limit(20)->get(),
        ])->layout('layouts.app', ['title' => 'My Profile']);
    }
}
