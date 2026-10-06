<?php

namespace App\Livewire\Holidays;

use App\Enums\HolidayType;
use App\Models\DecemberMandatoryDay;
use App\Models\Department;
use App\Models\HolidayWorkRequest;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\SpreadsheetService;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /** Holiday calendar (country) filter: '' = every calendar. */
    public string $filterCountry = '';

    /** New MDL (Mandatory December Leave) shutdown date being added. */
    public string $mdlDate = '';

    public string $mdlDescription = 'Company shutdown';

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
            // The company's holiday calendar — the one everyone follows unless
            // an office or employee is opted into another.
            'country' => $this->companyCalendar(),
            'substitute_for_id' => null,
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
            'substitute_for_id' => $h->substitute_for_id,
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
            'form.holiday_type' => 'required|in:'.collect(HolidayType::cases())->map->value->implode(','),
            'form.substitute_for_id' => 'nullable|required_if:form.holiday_type,substitute|exists:public_holidays,id',
            'form.category' => 'nullable|string|max:60',
            'form.color' => 'nullable|string|max:20',
            'form.description' => 'nullable|string|max:1000',
            'form.country' => 'required|string|max:5|in:'.implode(',', $this->calendars()),
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
            'substitute_for_id' => $data['holiday_type'] === HolidayType::Substitute->value ? ($this->form['substitute_for_id'] ?: null) : null,
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
            \Flux::toast('Holiday updated.', variant: 'success');
        } else {
            $holiday = PublicHoliday::create($payload + ['is_active' => true, 'created_by' => Auth::id()]);
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
        \Flux::toast('Holiday duplicated to '.$copy->date->format('d M Y').'.', variant: 'success');
    }

    public function toggleArchive(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);
        $h->update(['is_active' => ! $h->is_active]);
        \Flux::toast($h->is_active ? 'Holiday restored.' : 'Holiday archived.', variant: $h->is_active ? 'success' : 'warning');
    }

    /**
     * Only a future holiday is deleted. A past one already shaped attendance,
     * leave and pay, so it is archived instead, and one with holiday-work
     * requests against it is never deleted.
     */
    public function delete(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $h = PublicHoliday::findOrFail($id);

        if (! $h->date->isFuture()) {
            \Flux::toast('Past holidays are kept for history — archive it instead.', variant: 'warning');

            return;
        }

        if (HolidayWorkRequest::where('holiday_id', $h->id)->exists()) {
            \Flux::toast('Holiday-work requests refer to this holiday — archive it instead.', variant: 'warning');

            return;
        }

        $h->delete();
        \Flux::toast('Holiday deleted.', variant: 'danger');
    }

    /** Add a Mandatory December Leave (company shutdown) date. */
    public function addMdl(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate([
            'mdlDate' => ['required', 'date', 'after_or_equal:today', function (string $attribute, mixed $value, \Closure $fail) {
                if (Carbon::parse($value)->month !== 12) {
                    $fail('MDL dates fall in December.');
                } elseif (DecemberMandatoryDay::whereDate('date', $value)->exists()) {
                    $fail('That date is already an MDL date.');
                }
            }],
            'mdlDescription' => ['nullable', 'string', 'max:120'],
        ], [], ['mdlDate' => 'MDL date']);

        DecemberMandatoryDay::create([
            'year' => Carbon::parse($this->mdlDate)->year,
            'date' => $this->mdlDate,
            'description' => $this->mdlDescription ?: 'Company shutdown',
        ]);

        $this->reset('mdlDate');
        \Flux::toast('MDL date added.', variant: 'success');
    }

    /** Remove a future MDL date (a past one already decided leave and pay). */
    public function deleteMdl(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $day = DecemberMandatoryDay::findOrFail($id);

        if (! Carbon::parse($day->date)->isFuture()) {
            \Flux::toast('Past MDL dates are kept for history.', variant: 'warning');

            return;
        }

        $day->delete();
        \Flux::toast('MDL date removed.', variant: 'warning');
    }

    /** @return array<int, string> the holiday calendars in use (UK, IN, …) */
    protected function calendars(): array
    {
        return collect(['UK', 'IN', $this->companyCalendar()])
            ->merge(PublicHoliday::query()->distinct()->pluck('country'))
            ->filter()->map(fn ($c) => strtoupper((string) $c))->unique()->values()->all();
    }

    protected function companyCalendar(): string
    {
        return DB::table('companies')->value('holiday_calendar') ?: HolidayResolver::FALLBACK_CALENDAR;
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

    /** Export through the spreadsheet writer, which quotes and escapes every cell. */
    public function exportCsv()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $rows = $this->baseQuery()->orderBy('date')->get()->map(fn (PublicHoliday $h) => [
            $h->name, $h->date->toDateString(), $h->typeLabel(), $h->category, $h->country,
            $h->is_paid ? 'Yes' : 'No', $h->is_optional ? 'Yes' : 'No', $h->is_recurring ? 'Yes' : 'No',
            $h->office?->name ?? ($h->department?->name ?? 'Company-wide'), $h->is_active ? 'Active' : 'Archived',
        ])->all();

        return app(SpreadsheetService::class)->download(
            ['Name', 'Date', 'Type', 'Category', 'Country', 'Paid', 'Optional', 'Recurring', 'Scope', 'Status'],
            $rows, 'holidays-'.$this->year.'.csv',
        );
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
            ->when($this->filterCountry !== '', fn ($q) => $q->where('country', $this->filterCountry))
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
                'isWeekend' => app(WorkingDayResolver::class)->isWeeklyOff($d),
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
            'calendars' => $this->calendars(),
            'substituteOptions' => PublicHoliday::whereYear('date', $this->year)
                ->where('holiday_type', '!=', HolidayType::Substitute->value)
                ->orderBy('date')->get(['id', 'name', 'date', 'country']),
            'mdlDays' => DecemberMandatoryDay::whereYear('date', $this->year)->orderBy('date')->get(),
            'stats' => [
                'total' => $holidays->count(),
                'paid' => $holidays->where('is_paid', true)->count(),
                'optional' => $holidays->where('is_optional', true)->count(),
                'upcoming' => $holidays->filter(fn ($h) => $h->date->gte(now()->startOfDay()))->count(),
            ],
        ])->layout('layouts.app', ['title' => 'Holiday Management']);
    }
}
