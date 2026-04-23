<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Attendance Tracking</h1>
            <p class="pulse-page-subtitle">{{ \Illuminate\Support\Carbon::now()->format('l, jS F Y') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Clock Area --}}
        <div class="lg:col-span-4 space-y-6">
            <div class="pulse-card flex flex-col items-center text-center p-8 space-y-6" 
                 x-data="{ 
                    time: '--:--:--', 
                    timer: null,
                    location: null,
                    loading: false,
                    error: null,
                    updateTime() {
                        const now = new Date();
                        this.time = now.toLocaleTimeString();
                    },
                    getLocation() {
                        return new Promise((resolve, reject) => {
                            if (!navigator.geolocation) {
                                reject('Geolocation not supported');
                            }
                            navigator.geolocation.getCurrentPosition(resolve, reject);
                        });
                    },
                    async clockIn() {
                        this.loading = true;
                        this.error = null;
                        try {
                            const pos = await this.getLocation();
                            $wire.checkIn(pos.coords.latitude, pos.coords.longitude);
                        } catch (err) {
                            this.error = 'Location access required to clock in';
                            console.error(err);
                        } finally {
                            this.loading = false;
                        }
                    },
                    async clockOut() {
                        this.loading = true;
                        this.error = null;
                        try {
                            const pos = await this.getLocation();
                            $wire.checkOut(pos.coords.latitude, pos.coords.longitude);
                        } catch (err) {
                            $wire.checkOut(0, 0); // Allow checkout without loc if fail? or block?
                        } finally {
                            this.loading = false;
                        }
                    }
                 }" 
                 x-init="updateTime(); timer = setInterval(() => updateTime(), 1000)">
                
                <div class="bg-brand-50 text-brand-700 px-4 py-1.5 rounded-full text-sm font-bold dark:bg-brand-900/40 dark:text-brand-400">
                    {{ $todayAttendance ? 'ACTIVE SHIFT' : 'NOT CLOCKED IN' }}
                </div>

                <div class="text-5xl font-black text-zinc-900 tracking-tighter dark:text-white" x-text="time"></div>

                <div class="w-full space-y-4">
                    @if(!$todayAttendance)
                        <button 
                            @click="clockIn()" 
                            :disabled="loading"
                            class="w-full py-4 bg-brand-600 hover:bg-brand-700 text-white rounded-2xl font-bold shadow-lg shadow-brand-500/20 transition-all active:scale-95 flex items-center justify-center gap-2 group"
                        >
                            <template x-if="loading">
                                <flux:icon.arrow-path class="size-5 animate-spin" />
                            </template>
                            <template x-if="!loading">
                                <flux:icon.finger-print class="size-5 group-hover:scale-110 transition-transform" />
                            </template>
                            Clock In
                        </button>
                    @elseif(!$todayAttendance->check_out)
                        <div class="bg-zinc-50 border border-zinc-100 p-4 rounded-xl dark:bg-zinc-900 dark:border-zinc-800">
                            <div class="flex justify-between items-center mb-1">
                                <div class="text-xs text-zinc-400">Checked-in at</div>
                                <div class="text-[10px] font-bold text-brand-600 dark:text-brand-400 tracking-wider">LATE: {{ $todayAttendance->late_minutes ?? 0 }}m</div>
                            </div>
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $todayAttendance->check_in->format('H:i A') }}</div>
                        </div>

                        {{-- Break Tracking Buttons --}}
                        <div class="grid grid-cols-1 gap-2">
                            @if(!$todayAttendance->break_start)
                                <button wire:click="startBreak" class="w-full py-3 bg-zinc-100 hover:bg-zinc-200 text-zinc-900 rounded-xl font-bold flex items-center justify-center gap-2 transition-all active:scale-95 dark:bg-zinc-800 dark:text-zinc-100">
                                    <flux:icon.clock class="size-4" />
                                    Starting Break
                                </button>
                            @else
                                <button wire:click="endBreak" class="w-full py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-bold flex items-center justify-center gap-2 transition-all active:scale-95 animate-pulse">
                                    <flux:icon.play class="size-4" />
                                    Ending Break
                                </button>
                                <div class="text-[10px] font-bold text-orange-600 dark:text-orange-400 animate-bounce">ON BREAK SINCE {{ $todayAttendance->break_start->format('H:i') }}</div>
                            @endif
                        </div>

                        <button 
                            @click="clockOut()" 
                            :disabled="loading"
                            class="w-full py-4 bg-zinc-900 hover:bg-zinc-800 text-white rounded-2xl font-bold shadow-lg transition-all active:scale-95 flex items-center justify-center gap-2"
                        >
                            <template x-if="loading">
                                <flux:icon.arrow-path class="size-5 animate-spin" />
                            </template>
                            <template x-if="!loading">
                                <flux:icon.arrow-right-start-on-rectangle class="size-5" />
                            </template>
                            Clock Out
                        </button>
                    @else
                        <div class="p-6 bg-green-50 rounded-2xl border border-green-100 dark:bg-green-950/20 dark:border-green-900/40">
                            <flux:icon.check-badge class="size-10 text-green-600 mx-auto mb-2" />
                            <h4 class="font-bold text-green-900 dark:text-green-400">Shift Completed</h4>
                            <p class="text-sm text-green-700 dark:text-green-500 mt-1">Net: {{ (float)$todayAttendance->total_hours }} Hours</p>
                            <div class="text-[10px] text-zinc-400 mt-1 uppercase tracking-widest">Breaks: {{ $todayAttendance->break_minutes }}m</div>
                        </div>
                    @endif
                </div>

                <div class="w-full pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:button variant="ghost" icon="document-text" size="sm" @click="$flux.modal('regularisation-modal').show()">
                        Regularisation Request
                    </flux:button>
                </div>

                <p x-show="error" x-transition class="text-xs text-red-500 font-medium" x-text="error"></p>
                <div class="flex items-center gap-2 text-xs text-zinc-400">
                    <flux:icon.map-pin class="size-3" />
                    IP & Geo Logging active
                </div>
            </div>

            {{-- Summary Stats --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present</div>
                    <div class="text-xl font-bold mt-1 text-zinc-900 dark:text-white">{{ $stats['present'] }}</div>
                </div>
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Late</div>
                    <div class="text-xl font-bold mt-1 text-red-500">{{ $stats['late'] }}</div>
                </div>
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Hours</div>
                    <div class="text-xl font-bold mt-1 text-zinc-900 dark:text-white">{{ round($stats['hours'], 1) }}</div>
                </div>
            </div>
        </div>

        {{-- Log Area --}}
        <div class="lg:col-span-8 space-y-6">
            <div class="pulse-card">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Attendance Log (This Month)</h3>
                
                <div class="overflow-x-auto -mx-6">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">In/Out</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Break</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                                <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Net Hours</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @forelse($history as $item)
                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                                    <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                        {{ $item->date->format('jS M, l') }}
                                        @if($item->regularisation?->status === 'pending')
                                            <span class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400 animate-pulse">PENDING REG</span>
                                        @endif
                                    </td>
                                    <td class="py-4 pr-4">
                                        <div class="text-xs font-bold">{{ $item->check_in->format('H:i') }} - {{ $item->check_out ? $item->check_out->format('H:i') : '--:--' }}</div>
                                        <div class="text-[10px] text-zinc-400">{{ $item->check_in_ip }}</div>
                                    </td>
                                    <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $item->break_minutes }}m
                                    </td>
                                    <td class="py-4 pr-4">
                                        <span class="badge-{{ $item->status === 'on_time' ? $item->status : ($item->status === 'late' ? 'rejected' : 'manager') }}">
                                            {{ strtoupper($item->status) }}
                                        </span>
                                    </td>
                                    <td class="py-4 pr-6 text-right font-bold text-zinc-900 dark:text-white">
                                        {{ (float)$item->total_hours }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-12 text-center text-zinc-400">No attendance records found for this month.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Regularisation Modal --}}
    <flux:modal name="regularisation-modal" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Regularisation Request</flux:heading>
                <flux:subheading>Request a correction for past attendance</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="regDate" label="Date of Work" type="date" min="{{ \Illuminate\Support\Carbon::today()->subDays(7)->format('Y-m-d') }}" max="{{ \Illuminate\Support\Carbon::today()->format('Y-m-d') }}" />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="regCheckIn" label="Actual Check-in" type="time" />
                    <flux:input wire:model="regCheckOut" label="Actual Check-out" type="time" />
                </div>

                <flux:textarea wire:model="regReason" label="Reason for correction" placeholder="e.g. System glitch, forgot to clock in, out for meeting etc." rows="3" />
            </div>

            <div class="flex gap-2 justify-end mt-6">
                <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitRegularisation" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
