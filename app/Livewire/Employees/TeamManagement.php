<?php

namespace App\Livewire\Employees;

use App\Models\Department;
use App\Models\DepartmentTeam;
use App\Models\Employee;
use App\Services\Security\ScopeResolver;
use App\Services\Teams\TeamService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * HR team management (v4 Part 3.1): create/edit department teams, assign a
 * primary + secondary lead, and move employees between teams. All membership
 * changes go through TeamService so the one-active-team rule and history hold.
 */
class TeamManagement extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?int $departmentId = null;

    public ?int $teamLeadId = null;

    public ?int $secondaryLeadId = null;

    public string $status = 'active';

    public bool $showForm = false;

    /** Employee ids to (re)assign to the team being edited. @var array<int> */
    public array $memberIds = [];

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->canManageEmployees(), 403);
    }

    /**
     * Departments this user manages employees in (null = every department):
     * the manage_employees scope, so a department-scoped Director runs only
     * their own department's teams.
     *
     * @return array<int, int>|null
     */
    private function departmentReach(): ?array
    {
        return app(ScopeResolver::class)->departmentIds(auth()->user(), 'manage_employees');
    }

    /** @return array<int, int>|null employee ids in reach (null = everyone) */
    private function employeeReach(): ?array
    {
        return auth()->user()->accessibleEmployeeIds('manage_employees');
    }

    private function assertTeamInReach(DepartmentTeam $team): void
    {
        $departments = $this->departmentReach();
        abort_unless($departments === null || in_array((int) $team->department_id, $departments, true), 403);
    }

    public function newTeam(): void
    {
        $this->reset(['editingId', 'name', 'departmentId', 'teamLeadId', 'secondaryLeadId', 'memberIds']);
        $this->status = 'active';
        $this->showForm = true;
    }

    public function editTeam(int $id): void
    {
        $team = DepartmentTeam::with('activeMemberships')->findOrFail($id);
        $this->assertTeamInReach($team);
        $this->editingId = $team->id;
        $this->name = $team->name;
        $this->departmentId = $team->department_id;
        $this->teamLeadId = $team->team_lead_id;
        $this->secondaryLeadId = $team->secondary_lead_id;
        $this->status = $team->status;
        $this->memberIds = $team->activeMemberships->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
        $this->showForm = true;
    }

    public function save(TeamService $teams): void
    {
        $this->validate([
            'name' => 'required|string|max:120',
            'departmentId' => ['required', Rule::exists('departments', 'id')],
            'teamLeadId' => ['nullable', Rule::exists('employees', 'id')],
            'secondaryLeadId' => ['nullable', 'different:teamLeadId', Rule::exists('employees', 'id')],
            'status' => 'required|in:active,inactive',
            'memberIds' => 'array',
        ], [
            'secondaryLeadId.different' => 'The secondary lead must differ from the primary lead.',
        ]);

        // Only teams, leads and members inside the user's reach.
        $departments = $this->departmentReach();
        $people = $this->employeeReach();
        abort_unless($departments === null || in_array((int) $this->departmentId, $departments, true), 403);
        if ($this->editingId) {
            $this->assertTeamInReach(DepartmentTeam::findOrFail($this->editingId));
        }
        $chosen = array_filter([$this->teamLeadId, $this->secondaryLeadId, ...$this->memberIds]);
        abort_unless($people === null || array_diff(array_map('intval', $chosen), $people) === [], 403);

        $team = DepartmentTeam::updateOrCreate(
            ['id' => $this->editingId],
            [
                'department_id' => $this->departmentId,
                'name' => $this->name,
                'team_lead_id' => $this->teamLeadId,
                'secondary_lead_id' => $this->secondaryLeadId,
                'status' => $this->status,
            ],
        );

        // Reconcile membership: assign newly added, remove those unticked.
        $current = $team->activeMemberships()->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
        foreach (array_diff($this->memberIds, $current) as $addId) {
            $teams->assign(Employee::findOrFail($addId), $team);
        }
        foreach (array_diff($current, $this->memberIds) as $removeId) {
            $teams->remove(Employee::findOrFail($removeId));
        }

        \Flux::toast($this->editingId ? 'Team updated.' : 'Team created.');
        $this->showForm = false;
        $this->reset(['editingId', 'name', 'departmentId', 'teamLeadId', 'secondaryLeadId', 'memberIds']);
    }

    public function render()
    {
        $departments = $this->departmentReach();
        $people = $this->employeeReach();

        $teams = DepartmentTeam::with(['department', 'teamLead.user', 'secondaryLead.user'])
            ->withCount('activeMemberships')
            ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments))
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('department_id')->orderBy('name')->get();

        return view('livewire.employees.team-management', [
            'teams' => $teams,
            'departments' => Department::query()->when($departments !== null, fn ($q) => $q->whereIn('id', $departments))->orderBy('name')->get(),
            'employees' => Employee::with('user')->whereHas('user')
                ->when($people !== null, fn ($q) => $q->whereIn('id', $people))
                ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
                ->orderBy('id')->get(),
        ])->layout('layouts.app', ['title' => 'Teams']);
    }
}
