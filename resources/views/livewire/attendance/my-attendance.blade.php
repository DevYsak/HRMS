<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header flex items-center justify-between">
        <div>
            <h1 class="pulse-page-title">Attendance Tracking</h1>
            <p class="pulse-page-subtitle">{{ \Illuminate\Support\Carbon::now()->format('l, jS F Y') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if($todayAttendance && !$todayAttendance->check_out)
                @if(!$todayAttendance->break_start)
                    <flux:button wire:click="startBreak" variant="outline" size="sm">Start Break</flux:button>
                @else
                    <flux:button wire:click="endBreak" variant="outline" size="sm">End Break</flux:button>
                @endif
                <flux:button @click="$flux.modal('regularisation-modal').show()" variant="ghost" size="sm">Request Regularisation</flux:button>
            @else
                <flux:button @click="$dispatch('open-clock-widget')" variant="ghost" size="sm">Quick Actions</flux:button>
            @endif
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

                @if(!empty($shiftLabel))
                    <div class="text-sm text-zinc-500 mt-2">
                        <div class="font-semibold">{{ $shiftLabel }}</div>
                    </div>
                @endif

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
                            <p class="text-sm text-green-700 dark:text-green-500 mt-1">
                                Net:
                                @if($todayAttendance->check_out)
                                    @php $diff = $todayAttendance->check_out->diff($todayAttendance->check_in); @endphp
                                    {{ $diff->h }}h {{ $diff->i }}m
                                @elseif($todayAttendance->check_in)
                                    <span wire:poll.30s>
                                        @php $diff = \Illuminate\Support\Carbon::now()->diff($todayAttendance->check_in); @endphp
                                        {{ $diff->h }}h {{ $diff->i }}m
                                    </span>
                                @else
                                    0h 0m
                                @endif
                            </p>
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

            {{-- Top Stats Bar (current month) --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present</div>
                    <div class="text-xl font-bold mt-1 text-green-600">{{ $stats['present'] }}</div>
                </div>
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Late</div>
                    <div class="text-xl font-bold mt-1 text-amber-600">{{ $stats['late'] }}</div>
                </div>
                <div class="pulse-card p-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Hours</div>
                    <div class="text-xl font-bold mt-1 text-sky-600">
                        @if($todayAttendance && !$todayAttendance->check_out)
                            <span wire:poll.30s>
                                @php $diff = \Illuminate\Support\Carbon::now()->diff($todayAttendance->check_in); @endphp
                                {{ $diff->h }}h {{ $diff->i }}m
                            </span>
                        @else
                            {{ round($stats['hours'], 1) }}
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Log Area --}}
        <div class="lg:col-span-8 space-y-6">
            {{-- Monthly Calendar Card --}}
            <div class="pulse-card">
                <div class="flex items-center justify-between mb-4">
                    <div class="text-lg font-bold">{{ $calendarMonth ? $calendarMonth->format('F Y') : \Illuminate\Support\Carbon::now()->format('F Y') }}</div>
                    <div class="flex items-center gap-2">
                        <button wire:click="previousMonth" class="p-2 rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-800"><flux:icon.chevron-left class="size-4" /></button>
                        <button wire:click="nextMonth" class="p-2 rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-800"><flux:icon.chevron-right class="size-4" /></button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-1 text-xs text-zinc-500 mb-2">
                    <div class="text-center">Sun</div>
                    <div class="text-center">Mon</div>
                    <div class="text-center">Tue</div>
                    <div class="text-center">Wed</div>
                    <div class="text-center">Thu</div>
                    <div class="text-center">Fri</div>
                    <div class="text-center">Sat</div>
                </div>

                <div class="grid grid-cols-7 gap-2">
                    @foreach($calendarDays as $day)
                        <div wire:click="showDay('{{ $day['date'] }}')" class="p-2 rounded-lg cursor-pointer border {{ $day['in_month'] ? 'bg-white dark:bg-zinc-900' : 'bg-zinc-50 dark:bg-zinc-800 text-zinc-400' }} {{ $day['carbon']->isToday() ? 'ring-2 ring-brand-500' : '' }}">
                            <div class="flex items-start justify-between">
                                <div class="text-sm font-medium">{{ $day['carbon']->format('j') }}</div>
                                <div>
                                    @if($day['status'] === 'on_time')
                                        <span class="w-2 h-2 bg-green-600 rounded-full inline-block"></span>
                                    @elseif($day['status'] === 'late')
                                        <span class="w-2 h-2 bg-amber-500 rounded-full inline-block"></span>
                                    @elseif($day['status'] === 'absent')
                                        <span class="w-2 h-2 bg-red-600 rounded-full inline-block"></span>
                                    @elseif($day['status'] === 'leave')
                                        <span class="w-2 h-2 bg-violet-600 rounded-full inline-block"></span>
                                    @elseif($day['status'] === 'mdl')
                                        <span class="inline-flex items-center justify-center w-5 h-5 bg-indigo-600 text-white text-[10px] rounded-full">MDL</span>
                                    @elseif($day['status'] === 'holiday')
                                        <span class="inline-flex items-center justify-center w-5 h-5 bg-blue-500 text-white text-[10px] rounded-full">PH</span>
                                    @elseif($day['status'] === 'weekend')
                                        <span class="w-2 h-2 bg-gray-400 rounded-full inline-block"></span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(($monthHolidays ?? collect())->isNotEmpty() || ($monthMdls ?? collect())->isNotEmpty())
                    <div class="mt-4 border-t pt-3">
                        <h4 class="text-sm font-bold mb-2">Holidays This Month</h4>
                        <div class="grid gap-2">
                            @foreach($monthHolidays as $h)
                                <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <span class="inline-flex items-center justify-center w-6 h-6 bg-blue-500 text-white text-[10px] rounded-full">PH</span>
                                    <div>{{ \Illuminate\Support\Carbon::parse($h->date)->format('j M') }} — {{ $h->name }}</div>
                                </div>
                            @endforeach
                            @foreach($monthMdls as $m)
                                <div class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <span class="inline-flex items-center justify-center w-6 h-6 bg-indigo-600 text-white text-[10px] rounded-full">MDL</span>
                                    <div>{{ \Illuminate\Support\Carbon::parse($m->date)->format('j M') }} — {{ $m->description ?? 'MDL' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

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
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
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
                                        @php
                                            $st = strtoupper($item->status);
                                            $badgeClass = match($item->status) {
                                                'on_time' => 'bg-green-100 text-green-700',
                                                'late' => 'bg-amber-100 text-amber-700',
                                                'absent' => 'bg-red-100 text-red-700',
                                                'remote' => 'bg-gray-100 text-gray-700',
                                                default => 'bg-zinc-100 text-zinc-700'
                                            };
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-[11px] font-semibold {{ $badgeClass }}">{{ $st }}</span>
                                    </td>
                                    <td class="py-4 pr-4">
                                        @if($item->missing_checkout || $item->is_late)
                                            <flux:button wire:click="openRegularisation('{{ $item->date->toDateString() }}')" size="sm" variant="ghost">Regularisation Request</flux:button>
                                        @endif
                                    </td>
                                    <td class="py-4 pr-6 text-right font-bold text-zinc-900 dark:text-white">
                                        @if($item->check_out)
                                            @php $diff = $item->check_out->diff($item->check_in); @endphp
                                            {{ $diff->h }}h {{ $diff->i }}m
                                        @elseif($item->check_in && $item->date->isToday())
                                            <span wire:poll.30s>
                                                @php $diff = \Illuminate\Support\Carbon::now()->diff($item->check_in); @endphp
                                                {{ $diff->h }}h {{ $diff->i }}m
                                            </span>
                                        @else
                                            --
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-12 text-center text-zinc-400">No attendance records found for this month.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Regularisation Modal --}}
    {{-- Day Details Modal --}}
    <flux:modal name="day-details" class="max-w-sm">
        <div class="space-y-4">
            <div>
                <flux:heading size="md">Day Details</flux:heading>
                <p class="text-sm text-zinc-500">{{ $selectedDay ?? '—' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1">
                    <div class="text-xs text-zinc-400">Clock In</div>
                    <div class="font-bold">{{ $selectedDayDetails['check_in'] ?? '--:--' }}</div>
                </div>
                <div class="space-y-1">
                    <div class="text-xs text-zinc-400">Clock Out</div>
                    <div class="font-bold">{{ $selectedDayDetails['check_out'] ?? '--:--' }}</div>
                </div>
                <div class="space-y-1">
                    <div class="text-xs text-zinc-400">Break</div>
                    <div class="font-bold">{{ $selectedDayDetails['break_minutes'] ?? 0 }}m</div>
                </div>
                <div class="space-y-1">
                    <div class="text-xs text-zinc-400">Hours</div>
                    <div class="font-bold">{{ $selectedDayDetails['total_hours'] ?? 0 }}</div>
                </div>
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                <div class="text-xs text-zinc-500">Status: <span class="font-bold">{{ $selectedDayDetails['status'] ?? '—' }}</span></div>
                <div class="flex gap-2">
                    <flux:button @click="$flux.modal('day-details').close()">Close</flux:button>
                    <flux:button wire:click="openRegularisation('{{ $selectedDay ?? '' }}')" variant="primary">Request Regularisation</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
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
