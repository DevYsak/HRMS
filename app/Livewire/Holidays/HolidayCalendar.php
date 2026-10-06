<?php

namespace App\Livewire\Holidays;

use App\Models\DecemberMandatoryDay;
use App\Models\PublicHoliday;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The employee's Holiday Calendar: their own calendar only (the UK or India
 * calendar their employee, office or company follows, within any branch /
 * department scope), month or year view, with MDL shutdown dates shown
 * distinctly. Read-only; HR manages holidays in Settings → Holidays.
 */
class HolidayCalendar extends Component
{
    public string $view = 'month'; // month | year

    public string $month = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function previous(): void
    {
        $this->month = $this->view === 'year'
            ? $this->cursor()->subYearNoOverflow()->format('Y-m')
            : $this->cursor()->subMonthNoOverflow()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = $this->view === 'year'
            ? $this->cursor()->addYearNoOverflow()->format('Y-m')
            : $this->cursor()->addMonthNoOverflow()->format('Y-m');
    }

    public function setYear(int $year): void
    {
        $this->month = $this->cursor()->setDate(max(2000, min(2100, $year)), $this->cursor()->month, 1)->format('Y-m');
    }

    private function cursor(): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m', $this->month ?: now()->format('Y-m'))->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    public function render(): View
    {
        $employee = Auth::user()->employee;
        $resolver = app(HolidayResolver::class);
        $days = app(WorkingDayResolver::class);
        $cursor = $this->cursor();
        $yearStart = $cursor->copy()->startOfYear();
        $yearEnd = $cursor->copy()->endOfYear()->startOfDay();

        $holidays = $resolver->holidaysInRange($yearStart, $yearEnd)
            ->filter(fn (PublicHoliday $h) => $resolver->appliesTo($h, $employee))
            ->sortBy('date')->values();
        $mdl = DecemberMandatoryDay::whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->orderBy('date')->get()->keyBy(fn ($d) => Carbon::parse($d->date)->toDateString());
        $byDate = $holidays->groupBy(fn ($h) => $h->date->toDateString());

        // Month grid, Sunday–Saturday like the other calendars.
        $gridStart = $cursor->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY)->startOfDay();
        $grid = [];
        for ($d = $gridStart->copy(); $d->lte($gridEnd); $d = $d->copy()->addDay()) {
            $key = $d->toDateString();
            $grid[] = [
                'date' => $key,
                'day' => $d->day,
                'in_month' => $d->month === $cursor->month,
                'today' => $d->isToday(),
                'weekly_off' => $days->isWeeklyOff($d),
                'holidays' => $byDate->get($key, collect())->values(),
                'mdl' => $mdl->get($key),
            ];
        }

        return view('livewire.holidays.holiday-calendar', [
            'cursor' => $cursor,
            'grid' => $grid,
            'holidays' => $holidays,
            'mdl' => $mdl->values(),
            'byMonth' => $holidays->groupBy(fn ($h) => $h->date->format('Y-m')),
            'mdlByMonth' => $mdl->values()->groupBy(fn ($d) => Carbon::parse($d->date)->format('Y-m')),
            'calendar' => $resolver->resolveCountry($employee),
            'upcoming' => $resolver->upcomingHolidays($employee, 3),
        ])->layout('layouts.app', ['title' => 'Holiday Calendar']);
    }
}
