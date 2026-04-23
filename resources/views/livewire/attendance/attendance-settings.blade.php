<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Attendance Settings</h1>
            <p class="pulse-page-subtitle">Configure global rules and geo-fencing locations</p>
        </div>
        <flux:button wire:click="save" variant="primary" icon="check">Save Changes</flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Global Rules --}}
        <div class="space-y-6">
            <div class="pulse-card space-y-6">
                <h3 class="font-bold text-zinc-900 dark:text-white">General Rules</h3>
                
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="settings.shift_start" type="time" label="Shift Start" />
                        <flux:input wire:model="settings.shift_end" type="time" label="Shift End" />
                    </div>
                    
                    <flux:input wire:model="settings.late_grace_period" type="number" suffix="mins" label="Late Grace Period" />
                    
                    <div class="pt-4 space-y-4">
                        <flux:switch wire:model="settings.requires_location" label="Require Geolocation" description="Block clock-in if location is not shared." />
                        <flux:switch wire:model="settings.requires_qr" label="Require QR Scan" description="Require scanning a physical QR code at the office." />
                    </div>
                </div>
            </div>
        </div>

        {{-- Geo-fencing Locations --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="pulse-card">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-zinc-900 dark:text-white">Office Locations (Geo-fencing)</h3>
                </div>

                <div class="space-y-6">
                    @foreach($offices as $office)
                        <div class="p-4 rounded-xl border border-zinc-100 bg-zinc-50/50 dark:bg-zinc-900 dark:border-zinc-800"
                             x-data="{ 
                                id: {{ $office->id }},
                                lat: '{{ $office->latitude }}',
                                lng: '{{ $office->longitude }}',
                                radius: '{{ $office->radius }}'
                             }">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $office->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $office->city }}, {{ $office->country }}</div>
                                </div>
                                <flux:button x-on:click="$wire.updateOffice(id, lat, lng, radius)" size="sm" variant="ghost" icon="arrow-path">Update Location</flux:button>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <flux:input x-model="lat" label="Latitude" placeholder="0.000000" />
                                <flux:input x-model="lng" label="Longitude" placeholder="0.000000" />
                                <flux:input x-model="radius" label="Radius (Meters)" placeholder="200" />
                            </div>
                            
                            <div class="mt-4 flex items-center gap-2 text-[10px] text-zinc-400">
                                <flux:icon.information-circle class="size-3" />
                                <span>Employees assigned to this office must be within this radius to clock in.</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</flux:main>
