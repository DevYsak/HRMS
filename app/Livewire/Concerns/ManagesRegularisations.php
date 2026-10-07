<?php

namespace App\Livewire\Concerns;

use App\Models\AttendanceRegularisation;
use App\Services\Attendance\RegularisationManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Edit / Delete for regularisations, shared by the HR list (All Attendance)
 * and the employee's My Attendance. Who may do what is decided by
 * {@see RegularisationManager}; this only drives the modal
 * (resources/views/livewire/attendance/partials/regularisation-manage-modal).
 */
trait ManagesRegularisations
{
    public ?int $manageRegId = null;

    /** edit | delete */
    public string $manageRegMode = '';

    public string $manageRegCheckIn = '';

    public string $manageRegCheckOut = '';

    public string $manageRegHalfDay = '';

    public string $manageRegRequestReason = '';

    /** Why the actor is making this change — audited. */
    public string $manageRegReason = '';

    /** Explicit confirmation for an approved (already applied) request. */
    public bool $manageRegConfirm = false;

    public bool $showManageRegModal = false;

    public function openRegularisationEdit(int $id): void
    {
        $this->openManagedRegularisation($id, 'edit');
    }

    public function openRegularisationDelete(int $id): void
    {
        $this->openManagedRegularisation($id, 'delete');
    }

    public function closeRegularisationManage(): void
    {
        $this->resetManagedRegularisation();
    }

    public function saveRegularisationEdit(): void
    {
        $regularisation = $this->managedRegularisation();
        if (! $regularisation || $this->manageRegMode !== 'edit') {
            return;
        }

        $this->validate($this->manageRegRules(), [
            'manageRegCheckIn.regex' => 'Enter the check-in time as HH:MM.',
            'manageRegCheckOut.regex' => 'Enter the check-out time as HH:MM.',
            'manageRegReason.required' => 'Give a reason for the change.',
            'manageRegConfirm.accepted' => 'Confirm that this approved correction will be re-applied.',
        ]);

        try {
            app(RegularisationManager::class)->update($regularisation, Auth::user(), [
                'check_in' => $this->manageRegCheckIn ?: null,
                'check_out' => $this->manageRegCheckOut ?: null,
                'half_day_period' => $this->manageRegHalfDay ?: null,
                'reason' => $this->manageRegRequestReason,
            ], $this->manageRegReason, $this->manageRegConfirm);
        } catch (AuthorizationException) {
            abort(403);
        } catch (\DomainException $e) {
            $this->addError('manageRegReason', $e->getMessage());

            return;
        }

        $approved = $regularisation->status === 'approved';
        $this->resetManagedRegularisation();
        $this->afterRegularisationManaged();
        \Flux::toast($approved ? 'Correction updated — attendance rebuilt and re-applied.' : 'Regularisation updated.');
    }

    public function confirmRegularisationDelete(): void
    {
        $regularisation = $this->managedRegularisation();
        if (! $regularisation || $this->manageRegMode !== 'delete') {
            return;
        }

        $this->validate([
            'manageRegReason' => 'required|string|min:3|max:500',
            'manageRegConfirm' => $regularisation->status === 'approved' ? 'accepted' : 'nullable',
        ], [
            'manageRegReason.required' => 'Give a reason for deleting it.',
            'manageRegConfirm.accepted' => 'Confirm that the correction will be reverted.',
        ]);

        try {
            app(RegularisationManager::class)->delete($regularisation, Auth::user(), $this->manageRegReason, $this->manageRegConfirm);
        } catch (AuthorizationException) {
            abort(403);
        } catch (\DomainException $e) {
            $this->addError('manageRegReason', $e->getMessage());

            return;
        }

        $approved = $regularisation->status === 'approved';
        $this->resetManagedRegularisation();
        $this->afterRegularisationManaged();
        \Flux::toast($approved ? 'Regularisation deleted — attendance rebuilt from the biometric punches.' : 'Regularisation deleted.');
    }

    /** The request open in the modal (re-read every time — never trusted from the client). */
    public function managedRegularisation(): ?AttendanceRegularisation
    {
        return $this->manageRegId
            ? AttendanceRegularisation::with('employee.user')->find($this->manageRegId)
            : null;
    }

    /** Refresh whatever the host screen shows after an edit or delete. */
    protected function afterRegularisationManaged(): void {}

    private function openManagedRegularisation(int $id, string $mode): void
    {
        $regularisation = AttendanceRegularisation::with('employee.user')->findOrFail($id);
        $manager = app(RegularisationManager::class);
        $allowed = $mode === 'edit' ? $manager->canEdit(Auth::user(), $regularisation) : $manager->canDelete(Auth::user(), $regularisation);

        if (! $allowed) {
            // Visible to the owner, so say why rather than a bare 403.
            if ($manager->isOwner(Auth::user(), $regularisation) || $manager->actsAsHr(Auth::user(), $regularisation)) {
                \Flux::toast($manager->lockReason(Auth::user(), $regularisation) ?? 'You cannot change this regularisation.', variant: 'warning');

                return;
            }

            abort(403);
        }

        $this->resetValidation();
        $this->manageRegId = $regularisation->id;
        $this->manageRegMode = $mode;
        $this->manageRegCheckIn = $regularisation->requested_check_in ? Carbon::parse($regularisation->requested_check_in)->format('H:i') : '';
        $this->manageRegCheckOut = $regularisation->requested_check_out ? Carbon::parse($regularisation->requested_check_out)->format('H:i') : '';
        $this->manageRegHalfDay = (string) $regularisation->half_day_period;
        $this->manageRegRequestReason = (string) $regularisation->reason;
        $this->manageRegReason = '';
        $this->manageRegConfirm = false;
        $this->showManageRegModal = true;
    }

    /** @return array<string, mixed> */
    private function manageRegRules(): array
    {
        $regularisation = $this->managedRegularisation();
        $time = ['nullable', 'regex:/^\d{2}:\d{2}$/'];
        $punch = $regularisation && ! $regularisation->isLeave() && $regularisation->regularisation_type !== 'half_day';

        return [
            'manageRegCheckIn' => $punch ? array_merge(['required'], array_slice($time, 1)) : $time,
            'manageRegCheckOut' => $punch ? array_merge(['required'], array_slice($time, 1)) : $time,
            'manageRegHalfDay' => 'nullable|in:first,second',
            'manageRegRequestReason' => 'required|string|min:5|max:1000',
            'manageRegReason' => 'required|string|min:3|max:500',
            'manageRegConfirm' => $regularisation?->status === 'approved' ? 'accepted' : 'nullable',
        ];
    }

    private function resetManagedRegularisation(): void
    {
        $this->reset(['manageRegId', 'manageRegMode', 'manageRegCheckIn', 'manageRegCheckOut', 'manageRegHalfDay', 'manageRegRequestReason', 'manageRegReason', 'manageRegConfirm', 'showManageRegModal']);
        $this->resetValidation();
    }
}
