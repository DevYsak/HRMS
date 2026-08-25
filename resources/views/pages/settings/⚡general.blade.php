<?php

use App\Models\Company;
use App\Models\Office;
use App\Models\Department;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux\Flux;
use Livewire\WithFileUploads;

new #[Title('Company Settings')] class extends Component {
    use WithFileUploads;

    public Company $company;
    
    // Company details
    public $name;
    public $website;
    public $email;
    public $phone;
    public $address;
    public $city;
    public $country;
    public $timezone;
    public $currency;
    public $currency_symbol;
    public $primary_color;
    public $secondary_color;
    public $logo; // For upload
    public $favicon; // For upload

    // Office form
    public $officeId;
    public $officeName;
    public $officeAddress;
    public $officeCity;
    public $officeCountry;
    public $officeTimezone = 'UTC';
    public $officeRadius = 200;
    public $isHeadquarters = false;

    // Department form
    public $deptId;
    public $deptName;
    public $deptCode;
    public $deptDesc;

    public function mount(): void
    {
        $this->company = Company::first() ?? new Company();
        $this->fillCompanyDetails();
    }

    protected function fillCompanyDetails(): void
    {
        $this->name = $this->company->name;
        $this->website = $this->company->website;
        $this->email = $this->company->email;
        $this->phone = $this->company->phone;
        $this->address = $this->company->address;
        $this->city = $this->company->city;
        $this->country = $this->company->country;
        $this->timezone = $this->company->timezone ?? 'UTC';
        $this->currency = $this->company->currency ?? 'USD';
        $this->currency_symbol = $this->company->currency_symbol ?? '$';
        $this->primary_color = $this->company->primary_color ?? '#4f46e5';
        $this->secondary_color = $this->company->secondary_color ?? '#10b981';
    }

    #[Computed]
    public function offices()
    {
        return Office::where('company_id', $this->company->id)->orderBy('is_headquarters', 'desc')->get();
    }

    #[Computed]
    public function departments()
    {
        return Department::where('company_id', $this->company->id)->orderBy('name')->get();
    }

    public function updateCompany(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'website' => 'nullable|url',
            // Logo: any raster/vector image up to 2 MB.
            'logo' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            // Favicon: square-ish icon; .ico is not an "image" mime, so list it explicitly.
            'favicon' => 'nullable|mimes:png,ico,svg,jpg|max:1024',
        ]);

        $this->company->fill([
            'name' => $this->name,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'currency_symbol' => $this->currency_symbol,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
        ]);

        if ($this->logo) {
            $this->company->logo = $this->logo->store('branding', 'public');
        }

        if ($this->favicon) {
            $this->company->favicon = $this->favicon->store('branding', 'public');
        }

        $this->company->save();

        // The page <head> caches the favicon path; drop it so the new icon shows.
        \Illuminate\Support\Facades\Cache::forget('company.favicon');

        $this->reset(['logo', 'favicon']);

        Flux::toast(variant: 'success', text: __('Company details updated.'));
    }

    // --- Office Actions ---

    public function editOffice($id = null): void
    {
        if ($id) {
            $office = Office::findOrFail($id);
            $this->officeId = $office->id;
            $this->officeName = $office->name;
            $this->officeAddress = $office->address;
            $this->officeCity = $office->city;
            $this->officeCountry = $office->country;
            $this->officeTimezone = $office->timezone;
            $this->officeRadius = $office->radius;
            $this->isHeadquarters = $office->is_headquarters;
        } else {
            $this->reset(['officeId', 'officeName', 'officeAddress', 'officeCity', 'officeCountry', 'isHeadquarters']);
            $this->officeTimezone = 'UTC';
            $this->officeRadius = 200;
        }

        $this->dispatch('flux:modal:open', name: 'office-modal');
    }

    public function saveOffice(): void
    {
        $this->validate([
            'officeName' => 'required|string|max:255',
            'officeCity' => 'required|string',
        ]);

        if ($this->isHeadquarters) {
            Office::where('company_id', $this->company->id)->update(['is_headquarters' => false]);
        }

        Office::updateOrCreate(
            ['id' => $this->officeId],
            [
                'company_id' => $this->company->id,
                'name' => $this->officeName,
                'address' => $this->officeAddress,
                'city' => $this->officeCity,
                'country' => $this->officeCountry,
                'timezone' => $this->officeTimezone,
                'radius' => $this->officeRadius,
                'is_headquarters' => $this->isHeadquarters,
            ]
        );

        $this->dispatch('flux:modal:close', name: 'office-modal');
        Flux::toast(variant: 'success', text: __('Office saved.'));
    }

    public function deleteOffice($id): void
    {
        Office::findOrFail($id)->delete();
        Flux::toast(text: __('Office removed.'));
    }

    // --- Department Actions ---

    public function editDepartment($id = null): void
    {
        if ($id) {
            $dept = Department::findOrFail($id);
            $this->deptId = $dept->id;
            $this->deptName = $dept->name;
            $this->deptCode = $dept->code;
            $this->deptDesc = $dept->description;
        } else {
            $this->reset(['deptId', 'deptName', 'deptCode', 'deptDesc']);
        }

        $this->dispatch('flux:modal:open', name: 'department-modal');
    }

    public function saveDepartment(): void
    {
        $this->validate([
            'deptName' => 'required|string|max:255',
            'deptCode' => 'required|string|max:10',
        ]);

        Department::updateOrCreate(
            ['id' => $this->deptId],
            [
                'company_id' => $this->company->id,
                'name' => $this->deptName,
                'code' => $this->deptCode,
                'description' => $this->deptDesc,
            ]
        );

        $this->dispatch('flux:modal:close', name: 'department-modal');
        Flux::toast(variant: 'success', text: __('Department saved.'));
    }

    public function deleteDepartment($id): void
    {
        Department::findOrFail($id)->delete();
        Flux::toast(text: __('Department removed.'));
    }
}; ?>

<x-pages::settings.layout :heading="__('Company Settings')" :subheading="__('Manage your organization details, offices, and departments.')">
    <div class="space-y-12">
        {{-- Company Details --}}
        <section>
            <form wire:submit="updateCompany" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <flux:input wire:model="name" :label="__('Company Name')" required />
                    <flux:input wire:model="website" :label="__('Website')" type="url" placeholder="https://..." />
                    <flux:input wire:model="email" :label="__('Company Email')" type="email" required />
                    <flux:input wire:model="phone" :label="__('Phone Number')" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <flux:input wire:model="address" :label="__('Address')" class="md:col-span-2" />
                    <flux:input wire:model="city" :label="__('City')" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <flux:input wire:model="country" :label="__('Country')" />
                    <flux:select wire:model="timezone" :label="__('Timezone')">
                        <option value="UTC">UTC</option>
                        <option value="Asia/Kolkata">Asia/Kolkata</option>
                        <option value="America/New_York">America/New_York</option>
                        <option value="Europe/London">Europe/London</option>
                    </flux:select>
                    <div class="flex gap-2">
                        <flux:input wire:model="currency" :label="__('Currency')" placeholder="USD" />
                        <flux:input wire:model="currency_symbol" :label="__('Symbol')" placeholder="$" class="w-20" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <flux:field>
                        <flux:label>{{ __('Primary Color') }}</flux:label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="primary_color" class="size-10 rounded border border-zinc-200" />
                            <flux:input wire:model="primary_color" class="flex-1" />
                        </div>
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Secondary Color') }}</flux:label>
                        <div class="flex items-center gap-3">
                            <input type="color" wire:model="secondary_color" class="size-10 rounded border border-zinc-200" />
                            <flux:input wire:model="secondary_color" class="flex-1" />
                        </div>
                    </flux:field>
                </div>

                {{-- Branding: logo & favicon --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Logo --}}
                    <flux:field>
                        <flux:label>{{ __('Company Logo') }}</flux:label>
                        <div class="flex items-center gap-4">
                            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                @if ($logo && $logo->isPreviewable())
                                    <img src="{{ $logo->temporaryUrl() }}" class="size-full object-contain" alt="Logo preview">
                                @elseif ($logo)
                                    <flux:icon.document-check class="size-6 text-emerald-500" />
                                @elseif ($company->logo)
                                    <img src="{{ asset('storage/'.$company->logo) }}" class="size-full object-contain" alt="Current logo">
                                @else
                                    <flux:icon.photo class="size-6 text-zinc-400" />
                                @endif
                            </div>
                            <div class="flex-1">
                                <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                    class="block w-full text-sm text-zinc-500 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-200" />
                                <flux:text size="sm" class="mt-1 text-zinc-400">{{ __('PNG, JPG, WEBP or SVG · max 2 MB. Shown in the sidebar & payslips.') }}</flux:text>
                                <div wire:loading wire:target="logo"><flux:text size="sm" class="text-zinc-400">{{ __('Uploading…') }}</flux:text></div>
                            </div>
                        </div>
                        <flux:error name="logo" />
                    </flux:field>

                    {{-- Favicon --}}
                    <flux:field>
                        <flux:label>{{ __('Favicon') }}</flux:label>
                        <div class="flex items-center gap-4">
                            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                @if ($favicon && $favicon->isPreviewable())
                                    <img src="{{ $favicon->temporaryUrl() }}" class="size-8 object-contain" alt="Favicon preview">
                                @elseif ($favicon)
                                    <flux:icon.document-check class="size-6 text-emerald-500" />
                                @elseif ($company->favicon)
                                    <img src="{{ asset('storage/'.$company->favicon) }}" class="size-8 object-contain" alt="Current favicon">
                                @else
                                    <flux:icon.globe-alt class="size-6 text-zinc-400" />
                                @endif
                            </div>
                            <div class="flex-1">
                                <input type="file" wire:model="favicon" accept="image/png,image/x-icon,image/svg+xml,.ico"
                                    class="block w-full text-sm text-zinc-500 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-200" />
                                <flux:text size="sm" class="mt-1 text-zinc-400">{{ __('ICO, PNG or SVG · max 1 MB · square (e.g. 32×32). Browser tab icon.') }}</flux:text>
                                <div wire:loading wire:target="favicon"><flux:text size="sm" class="text-zinc-400">{{ __('Uploading…') }}</flux:text></div>
                            </div>
                        </div>
                        <flux:error name="favicon" />
                    </flux:field>
                </div>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit">
                        {{ __('Save Changes') }}
                    </flux:button>
                </div>
            </form>
        </section>

        <flux:separator variant="subtle" />

        {{-- Offices --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <flux:heading level="3">{{ __('Offices') }}</flux:heading>
                    <flux:subheading>{{ __('Physical locations and branches.') }}</flux:subheading>
                </div>
                <flux:button wire:click="editOffice()" size="sm" icon="plus" variant="primary">{{ __('Add Office') }}</flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('Timezone') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->offices as $office)
                        <flux:table.row :key="$office->id">
                            <flux:table.cell class="font-medium">
                                {{ $office->name }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $office->city }}, {{ $office->country }}</flux:table.cell>
                            <flux:table.cell>{{ $office->timezone }}</flux:table.cell>
                            <flux:table.cell>
                                @if($office->is_headquarters)
                                    <flux:badge color="emerald" size="sm">{{ __('HQ') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="flex justify-end gap-2">
                                <flux:button wire:click="editOffice({{ $office->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                <flux:button wire:click="deleteOffice({{ $office->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>

        <flux:separator variant="subtle" />

        {{-- Departments --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <flux:heading level="3">{{ __('Departments') }}</flux:heading>
                    <flux:subheading>{{ __('Organizational divisions.') }}</flux:subheading>
                </div>
                <flux:button wire:click="editDepartment()" size="sm" icon="plus" variant="primary">{{ __('Add Department') }}</flux:button>
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->departments as $dept)
                        <flux:table.row :key="$dept->id">
                            <flux:table.cell>
                                <flux:badge color="zinc" variant="outline">{{ $dept->code }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $dept->name }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500 truncate max-w-xs">{{ $dept->description }}</flux:table.cell>
                            <flux:table.cell class="flex justify-end gap-2">
                                <flux:button wire:click="editDepartment({{ $dept->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                <flux:button wire:click="deleteDepartment({{ $dept->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    </div>

    {{-- Modals --}}
    <flux:modal name="office-modal" class="md:w-[500px]">
        <form wire:submit="saveOffice" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $officeId ? __('Edit Office') : __('Add Office') }}</flux:heading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="officeName" :label="__('Office Name')" required />
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="officeCity" :label="__('City')" required />
                    <flux:input wire:model="officeCountry" :label="__('Country')" />
                </div>
                <flux:input wire:model="officeAddress" :label="__('Full Address')" />
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="officeTimezone" :label="__('Timezone')">
                        <option value="UTC">UTC</option>
                        <option value="Asia/Kolkata">Asia/Kolkata</option>
                        <option value="America/New_York">America/New_York</option>
                    </flux:select>
                    <flux:input wire:model="officeRadius" type="number" :label="__('Geofence Radius (m)')" />
                </div>
                <flux:checkbox wire:model="isHeadquarters" :label="__('Mark as Headquarters')" />
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save Office') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="department-modal" class="md:w-[450px]">
        <form wire:submit="saveDepartment" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $deptId ? __('Edit Department') : __('Add Department') }}</flux:heading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="deptCode" :label="__('Department Code')" placeholder="e.g. ENG" required />
                <flux:input wire:model="deptName" :label="__('Department Name')" required />
                <flux:textarea wire:model="deptDesc" :label="__('Description')" />
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save Department') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-pages::settings.layout>
