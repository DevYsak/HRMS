<?php

use App\Models\PublicHoliday;
use App\Models\DecemberMandatoryDay;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;

new #[Title('Holiday Calendar')] class extends Component {
    public string $holidayDate = '';
    public string $holidayName = '';
    public int $mandatoryYear;
    public string $mandatoryDate = '';
    public string $mandatoryDesc = 'Mandatory December Leave';

    public function mount(): void
    {
        $this->mandatoryYear = Carbon::now()->year;
    }

    #[Computed]
    public function publicHolidays()
    {
        return PublicHoliday::orderBy('date', 'desc')->get();
    }

    #[Computed]
    public function mandatoryDays()
    {
        return DecemberMandatoryDay::orderBy('date', 'desc')->get();
    }

    public function addPublicHoliday(): void
    {
        $this->validate([
            'holidayDate' => 'required|date|unique:public_holidays,date',
            'holidayName' => 'required|string|max:255',
        ]);

        PublicHoliday::create([
            'date' => $this->holidayDate,
            'name' => $this->holidayName,
        ]);

        $this->reset('holidayDate', 'holidayName');
        
        Flux::toast('Public holiday added.');
    }

    public function removePublicHoliday(int $id): void
    {
        PublicHoliday::findOrFail($id)->delete();
        Flux::toast('Public holiday removed.');
    }

    public function addMandatoryDay(): void
    {
        $this->validate([
            'mandatoryDate' => 'required|date',
            'mandatoryYear' => 'required|integer',
        ]);

        DecemberMandatoryDay::create([
            'year' => $this->mandatoryYear,
            'date' => $this->mandatoryDate,
            'description' => $this->mandatoryDesc,
        ]);

        $this->reset('mandatoryDate');
        Flux::toast('Mandatory day added.');
    }

    public function removeMandatoryDay(int $id): void
    {
        DecemberMandatoryDay::findOrFail($id)->delete();
        Flux::toast('Mandatory day removed.');
    }
}; ?>

<x-pages::settings.layout :heading="__('Holiday Calendar')" :subheading="__('Manage public holidays and mandatory shutdown days.')">
    <div class="space-y-12">
        {{-- Public Holidays Section --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <flux:heading level="3">Public Holidays</flux:heading>
                <flux:modal.trigger name="add-public-holiday">
                    <flux:button size="sm" icon="plus" variant="primary">Add Holiday</flux:button>
                </flux:modal.trigger>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->publicHolidays as $holiday)
                        <flux:table.row :key="$holiday->id">
                            <flux:table.cell>{{ Carbon::parse($holiday->date)->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $holiday->name }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button wire:click="removePublicHoliday({{ $holiday->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>

        <flux:separator variant="subtle" />

        {{-- Mandatory Days Section --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <flux:heading level="3">December Mandatory Days</flux:heading>
                <flux:modal.trigger name="add-mandatory-day">
                    <flux:button size="sm" icon="plus" variant="primary">Add Day</flux:button>
                </flux:modal.trigger>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Year</flux:table.column>
                    <flux:table.column>Date</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>Actions</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->mandatoryDays as $day)
                        <flux:table.row :key="$day->id">
                            <flux:table.cell>{{ $day->year }}</flux:table.cell>
                            <flux:table.cell>{{ Carbon::parse($day->date)->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell>{{ $day->description }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:button wire:click="removeMandatoryDay({{ $day->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    </div>

    {{-- Modals --}}
    <flux:modal name="add-public-holiday" class="md:w-[400px]">
        <form wire:submit="addPublicHoliday" class="space-y-6">
            <div>
                <flux:heading size="lg">Add Public Holiday</flux:heading>
                <flux:subheading>Enter the date and name of the holiday.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="holidayDate" type="date" label="Date" required />
                <flux:input wire:model="holidayName" label="Holiday Name" placeholder="e.g. Christmas" required />
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save Holiday</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="add-mandatory-day" class="md:w-[400px]">
        <form wire:submit="addMandatoryDay" class="space-y-6">
            <div>
                <flux:heading size="lg">Add Mandatory Day</flux:heading>
                <flux:subheading>Add a day to the December mandatory shutdown.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="mandatoryYear" type="number" label="Year" required />
                <flux:input wire:model="mandatoryDate" type="date" label="Date" required />
                <flux:input wire:model="mandatoryDesc" label="Description" required />
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save Day</flux:button>
            </div>
        </form>
    </flux:modal>
</x-pages::settings.layout>
