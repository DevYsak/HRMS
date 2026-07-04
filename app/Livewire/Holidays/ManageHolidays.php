<?php

namespace App\Livewire\Holidays;

use App\Enums\HolidayType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Office;
use App\Models\PublicHoliday;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Livewire\Component;

/**
 * Holiday Management — Admin/HR CRUD + calendar for the extended
 * public_holidays model. Replaces the thin date+name settings page at the
 * same route (settings.holidays), so no new route/page is introduced.
 * Every write is audit-logged and reuses the existing PublicHoliday model,
 * keeping isHoliday() and all attendance/leave/report consumers intact.
 */
class ManageHolidays extends Component
{
    // View + filters
    public string $view = 'calendar'; // calendar | list | year

    public int $year;

    public string $filterType = '';

    public string $filterStatus = 'active'; // active | archived | all

    public ?int $filterOffice = null;

    public string $calendarMonth; // Y-m-01

    // Form / modal state
    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    // Details popup
    public ?array $detail = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $this->year = (int) now()->year;
        $this->calendarMonth = now()->startOfMonth()->toDateString();
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'date' => now()->toDateString(),
            'holiday_type' => 'national',
            'category' => '',
            'color' => '',
            'description' => '',
            'country' => 'IN',
            'is_paid' => true,
            'is_optional' => false,
            'is_recurring' => false,
            'office_id' => null,
            'department_id' => null,
        ];
    }

    public function openCreate(?string $date = null): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $this->resetForm();
        if ($date) {
            $this->form['date'] = $date;
        }
        $this->detail = null;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);
        $this->editingId = $h->id;
        $this->form = [
            'name' => $h->name,
            'date' => $h->date->toDateString(),
            'holiday_type' => $h->holiday_type instanceof HolidayType ? $h->holiday_type->value : (string) $h->holiday_type,
            'category' => (string) $h->category,
            'color' => (string) $h->color,
            'description' => (string) $h->description,
            'country' => $h->country,
            'is_paid' => (bool) $h->is_paid,
            'is_optional' => (bool) $h->is_optional,
            'is_recurring' => (bool) $h->is_recurring,
            'office_id' => $h->office_id,
            'department_id' => $h->department_id,
        ];
        $this->detail = null;
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.date' => 'required|date',
            'form.holiday_type' => 'required|in:national,state,festival,company,optional,branch',
            'form.category' => 'nullable|string|max:60',
            'form.color' => 'nullable|string|max:20',
            'form.description' => 'nullable|string|max:1000',
            'form.country' => 'required|string|max:5',
            'form.office_id' => 'nullable|exists:offices,id',
            'form.department_id' => 'nullable|exists:departments,id',
        ])['form'];

        $payload = [
            'name' => $data['name'],
            'date' => $data['date'],
            'holiday_type' => $data['holiday_type'],
            'category' => $data['category'] ?: null,
            'color' => $data['color'] ?: null,
            'description' => $data['description'] ?: null,
            'country' => strtoupper($data['country']),
            'is_paid' => (bool) $this->form['is_paid'],
            'is_optional' => (bool) $this->form['is_optional'] || $data['holiday_type'] === 'optional',
            'is_recurring' => (bool) $this->form['is_recurring'],
            'office_id' => $this->form['office_id'] ?: null,
            'department_id' => $this->form['department_id'] ?: null,
        ];

        if ($this->editingId) {
            $holiday = PublicHoliday::findOrFail($this->editingId);
            $before = $holiday->toArray();
            $holiday->update($payload);
            AuditLog::record($holiday, 'updated', $before, $holiday->fresh()->toArray());
            \Flux::toast('Holiday updated.', variant: 'success');
        } else {
            $holiday = PublicHoliday::create($payload + ['is_active' => true, 'created_by' => Auth::id()]);
            AuditLog::record($holiday, 'created', null, $holiday->toArray());
            \Flux::toast('Holiday created.', variant: 'success');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function duplicate(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);
        $copy = $h->replicate(['created_by', 'year']); // 'year' is a generated column
        $copy->name = $h->name.' (Copy)';
        $copy->date = $h->date->copy()->addYear();  // next year by default
        $copy->created_by = Auth::id();
        $copy->save();
        AuditLog::record($copy, 'created', $copy->toArray(), null);
        \Flux::toast('Holiday duplicated to '.$copy->date->format('d M Y').'.', variant: 'success');
    }

    public function toggleArchive(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);
        $h->update(['is_active' => ! $h->is_active]);
        AuditLog::record($h, $h->is_active ? 'restored' : 'archived', $h->toArray(), null);
        \Flux::toast($h->is_active ? 'Holiday restored.' : 'Holiday archived.', variant: $h->is_active ? 'success' : 'warning');
    }

    public function delete(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);
        AuditLog::record($h, 'deleted', null, $h->toArray());
        $h->delete();
        \Flux::toast('Holiday deleted.', variant: 'danger');
    }

    public function showDetail(int $id): void
    {
        $h = PublicHoliday::with('office', 'department', 'creator')->findOrFail($id);
        $this->detail = [
            'id' => $h->id,
            'name' => $h->name,
            'date' => $h->date->format('l, d M Y'),
            'type' => $h->typeLabel(),
            'color' => $h->displayColor(),
            'category' => $h->category,
            'description' => $h->description,
            'country' => $h->country,
            'is_paid' => (bool) $h->is_paid,
            'is_optional' => (bool) $h->is_optional,
            'is_recurring' => (bool) $h->is_recurring,
            'is_active' => (bool) $h->is_active,
            'scope' => $h->office?->name ?? ($h->department?->name ?? 'Company-wide'),
            'creator' => $h->creator?->name,
        ];
    }

    public function exportCsv()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $rows = $this->baseQuery()->orderBy('date')->get();
        $csv = "Name,Date,Type,Category,Country,Paid,Optional,Recurring,Scope,Status\n";
        foreach ($rows as $h) {
            $scope = $h->office?->name ?? ($h->department?->name ?? 'Company-wide');
            $csv .= '"'.$h->name.'","'.$h->date->toDateString().'","'.$h->typeLabel().'","'.($h->category ?? '')
                .'","'.$h->country.'","'.($h->is_paid ? 'Yes' : 'No').'","'.($h->is_optional ? 'Yes' : 'No')
                .'","'.($h->is_recurring ? 'Yes' : 'No').'","'.$scope.'","'.($h->is_active ? 'Active' : 'Archived')."\"\n";
        }

        return Response::streamDownload(fn () => print ($csv), 'holidays-'.$this->year.'.csv', ['Content-Type' => 'text/csv']);
    }

    // ── Navigation ────────────────────────────────────────────────────────────
    public function previousMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->subMonth()->toDateString();
        $this->year = (int) Carbon::parse($this->calendarMonth)->year;
    }

    public function nextMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->addMonth()->toDateString();
        $this->year = (int) Carbon::parse($this->calendarMonth)->year;
    }

    public function setYear(int $year): void
    {
        $this->year = $year;
        $this->calendarMonth = Carbon::create($year, Carbon::parse($this->calendarMonth)->month, 1)->toDateString();
    }

    protected function baseQuery()
    {
        return PublicHoliday::query()
            ->with('office', 'department')
            ->when($this->filterStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->filterStatus === 'archived', fn ($q) => $q->where('is_active', false))
            ->when($this->filterType !== '', fn ($q) => $q->where('holiday_type', $this->filterType))
            ->when($this->filterOffice, fn ($q) => $q->where('office_id', $this->filterOffice))
            ->whereYear('date', $this->year);
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $holidays = $this->baseQuery()->orderBy('date')->get();
        $byDate = $holidays->groupBy(fn ($h) => $h->date->toDateString());

        // Month calendar grid (Sun–Sat)
        $monthStart = Carbon::parse($this->calendarMonth)->startOfMonth();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $monthStart->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);
        $calendarDays = [];
        foreach (CarbonPeriod::create($gridStart, $gridEnd) as $d) {
            $key = $d->toDateString();
            $calendarDays[] = [
                'date' => $key,
                'day' => $d->format('j'),
                'inMonth' => $d->month === $monthStart->month,
                'isToday' => $d->isToday(),
                'isWeekend' => $d->isSunday() || $d->isSaturday(),
                'holidays' => ($byDate[$key] ?? collect())->map(fn ($h) => [
                    'id' => $h->id, 'name' => $h->name, 'color' => $h->displayColor(), 'type' => $h->typeLabel(),
                ])->all(),
            ];
        }

        return view('livewire.holidays.manage-holidays', [
            'holidays' => $holidays,
            'calendarDays' => $calendarDays,
            'monthLabel' => $monthStart->format('F Y'),
            'types' => HolidayType::options(),
            'offices' => Office::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'stats' => [
                'total' => $holidays->count(),
                'paid' => $holidays->where('is_paid', true)->count(),
                'optional' => $holidays->where('is_optional', true)->count(),
                'upcoming' => $holidays->filter(fn ($h) => $h->date->gte(now()->startOfDay()))->count(),
            ],
        ])->layout('layouts.app', ['title' => 'Holiday Management']);
    }
}
