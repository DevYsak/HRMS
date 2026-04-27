<flux:main class="bg-[#f7f7f5] p-4 md:p-8 font-['DM_Sans'] text-[#1a1a1a]"
     x-data="{ 
        currentTime: '', 
        updateClock() {
            const now = new Date();
            this.currentTime = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
     }" 
     x-init="updateClock(); setInterval(() => updateClock(), 1000)">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;700&family=DM+Mono&display=swap');
        .font-mono { font-family: 'DM Mono', monospace; }
        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #1a5c3a;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 rgba(26, 92, 58, 0.4);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(26, 92, 58, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(26, 92, 58, 0); }
            100% { box-shadow: 0 0 0 0 rgba(26, 92, 58, 0); }
        }
    </style>

    {{-- Header --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Attendance Tracking</h1>
            <p class="text-zinc-500 text-sm">{{ now()->format('l, jS F Y') }}</p>
        </div>
        <div class="flex items-center gap-3">
            @if($todayAttendance && !$todayAttendance->check_out)
                @if(!$todayAttendance->break_start)
                    <button wire:click="startBreak" class="flex items-center gap-2 px-4 py-2 bg-white border border-[#e8e6df] rounded-lg text-sm font-medium hover:bg-zinc-50 transition-colors">
                        <flux:icon.pause class="size-4" /> Start Break
                    </button>
                @else
                    <button wire:click="endBreak" class="flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-lg text-sm font-medium hover:bg-amber-100 transition-colors">
                        <flux:icon.play class="size-4" /> End Break
                    </button>
                @endif
            @endif
            <button @click="$flux.modal('regularisation-modal').show()" class="flex items-center gap-2 px-4 py-2 bg-white border border-[#e8e6df] rounded-lg text-sm font-medium hover:bg-zinc-50 transition-colors">
                <flux:icon.pencil-square class="size-4" /> Request Regularisation
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Shift Card --}}
        <div class="lg:col-span-2 bg-[#1a1a1a] rounded-2xl p-8 text-white relative overflow-hidden">
            <div class="relative z-10">
                <div class="inline-flex items-center gap-2 px-3 py-1 bg-[#262626] border border-[#333] rounded-full text-[10px] font-bold tracking-wider mb-6">
                    <span class="pulse-dot"></span> ACTIVE SHIFT
                </div>
                
                <h2 class="text-5xl font-light tracking-tight mb-2">
                    {{ $shift ? Carbon\Carbon::parse($shift->start_time)->format('g:i A') : '10:30 AM' }} – 
                    {{ $shift ? Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '07:30 PM' }}
                </h2>
                <p class="text-zinc-500 text-sm mb-12">
                    {{ $shiftLabel }} · {{ now()->format('l, jS F Y') }}
                </p>

                <div class="grid grid-cols-3 gap-8">
                    <div>
                        <div class="text-3xl font-bold mb-1">{{ $attendanceSettings->late_grace_period ?? 5 }} min</div>
                        <div class="text-[10px] text-zinc-500 uppercase tracking-widest font-medium">Grace period</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold mb-1">
                            @if($shift)
                                {{ Carbon\Carbon::parse($shift->start_time)->diffInHours(Carbon\Carbon::parse($shift->end_time)) }} hrs
                            @else
                                9 hrs
                            @endif
                        </div>
                        <div class="text-[10px] text-zinc-500 uppercase tracking-widest font-medium">Standard hours</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold mb-1">{{ $shift->break_duration ?? 60 }} min</div>
                        <div class="text-[10px] text-zinc-500 uppercase tracking-widest font-medium">Max break</div>
                    </div>
                </div>
            </div>
            {{-- Subtle decoration --}}
            <div class="absolute top-[-20%] right-[-10%] w-64 h-64 bg-zinc-800/20 rounded-full blur-3xl"></div>
        </div>

        {{-- Clock Card --}}
        <div class="bg-white rounded-2xl p-8 border-[0.5px] border-[#e8e6df] shadow-sm flex flex-col justify-between">
            <div>
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Current Time</div>
                <div class="text-5xl font-light tracking-tighter mb-8 font-mono" x-text="currentTime"></div>

                @if($todayAttendance)
                    <div class="space-y-6">
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Checked in at</div>
                                @if($todayAttendance->is_late)
                                    <div class="text-[10px] font-bold text-[#d44c4c] uppercase">Late: {{ $todayAttendance->late_minutes }}m</div>
                                @else
                                    <div class="text-[10px] font-bold text-[#1a5c3a] uppercase">On time</div>
                                @endif
                            </div>
                            <div class="text-2xl font-bold font-mono">{{ $todayAttendance->check_in->format('h:i A') }}</div>
                        </div>

                        <div class="inline-flex items-center gap-2 px-3 py-1 bg-green-50 text-[#1a5c3a] rounded-full text-xs font-bold" wire:poll.10s>
                            <span class="w-1.5 h-1.5 bg-[#1a5c3a] rounded-full"></span>
                            @php
                                $diff = now()->diff($todayAttendance->check_in);
                                $hours = $diff->h + ($diff->d * 24);
                                $mins = $diff->i;
                            @endphp
                            {{ $hours }}h {{ $mins }}m running
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-2 text-zinc-400 py-4 italic text-sm">
                        <flux:icon.exclamation-circle class="size-4" /> Not clocked in today
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 mt-8">
                @if($todayAttendance && !$todayAttendance->check_out)
                    @if(!$todayAttendance->break_start)
                        <button wire:click="startBreak" class="py-3 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl font-bold text-sm hover:bg-amber-100 transition-colors">
                            Start Break
                        </button>
                    @else
                        <button wire:click="endBreak" class="py-3 bg-amber-600 text-white rounded-xl font-bold text-sm hover:bg-amber-700 transition-colors">
                            End Break
                        </button>
                    @endif
                    <button wire:click="checkOut" class="py-3 bg-[#1a1a1a] text-white rounded-xl font-bold text-sm hover:bg-black transition-colors">
                        Clock Out
                    </button>
                @elseif(!$todayAttendance)
                    <button wire:click="checkIn" class="col-span-2 py-4 bg-[#1a5c3a] text-white rounded-xl font-bold text-sm hover:bg-[#154a2e] transition-colors shadow-lg shadow-green-900/10">
                        Clock In
                    </button>
                @else
                    <div class="col-span-2 py-3 bg-green-50 text-[#1a5c3a] text-center rounded-xl font-bold text-sm border border-green-100">
                        Shift Completed
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Stats Row --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white p-6 rounded-2xl border-[0.5px] border-[#e8e6df] shadow-sm">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Present this month</div>
            <div class="text-4xl font-light text-[#1a5c3a]">{{ $stats['present'] }}</div>
        </div>
        <div class="bg-white p-6 rounded-2xl border-[0.5px] border-[#e8e6df] shadow-sm">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Late Arrivals</div>
            <div class="text-4xl font-light text-[#c4621a]">{{ $stats['late'] }}</div>
        </div>
        <div class="bg-white p-6 rounded-2xl border-[0.5px] border-[#e8e6df] shadow-sm" @if($todayAttendance && ! $todayAttendance->check_out) wire:poll.30s @endif>
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Hours Logged</div>
            <div class="text-4xl font-light text-[#1a4a7a] font-mono">{{ $stats['hours'] }}</div>
        </div>
        <div class="bg-white p-6 rounded-2xl border-[0.5px] border-[#e8e6df] shadow-sm">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Leaves Taken</div>
            <div class="text-4xl font-light text-zinc-900">{{ $stats['leaves'] }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Calendar --}}
        <div class="lg:col-span-8 bg-white rounded-2xl p-8 border-[0.5px] border-[#e8e6df] shadow-sm">
            <div class="flex items-center justify-between mb-8">
                <h3 class="text-lg font-bold">{{ $calendarMonth->format('F Y') }}</h3>
                <div class="flex gap-2">
                    <button wire:click="previousMonth" class="p-2 border border-[#e8e6df] rounded-lg hover:bg-zinc-50 transition-colors"><flux:icon.chevron-left class="size-4" /></button>
                    <button wire:click="nextMonth" class="p-2 border border-[#e8e6df] rounded-lg hover:bg-zinc-50 transition-colors"><flux:icon.chevron-right class="size-4" /></button>
                </div>
            </div>

            <div class="grid grid-cols-7 gap-y-4 text-center">
                @foreach(['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'] as $dayName)
                    <div class="text-[10px] font-bold text-zinc-400 tracking-widest">{{ $dayName }}</div>
                @endforeach

                @foreach($calendarDays as $day)
                    <div class="relative py-2 group">
                        <div class="text-sm font-medium mx-auto flex items-center justify-center w-10 h-10 rounded-xl transition-colors
                            {{ !$day['in_month'] ? 'text-zinc-300' : 'text-zinc-900' }}
                            {{ $day['is_today'] ? 'bg-[#1a1a1a] text-white shadow-lg' : '' }}
                        ">
                            {{ $day['day'] }}
                        </div>
                        
                        {{-- Status Dot --}}
                        <div class="flex justify-center mt-1">
                            @if($day['status'] === 'present')
                                <div class="w-1 h-1 rounded-full bg-[#1a5c3a]"></div>
                            @elseif($day['status'] === 'late')
                                <div class="w-1 h-1 rounded-full bg-[#d44c4c]"></div>
                            @elseif($day['status'] === 'leave')
                                <div class="w-1 h-1 rounded-full bg-[#c4621a]"></div>
                            @elseif($day['status'] === 'holiday')
                                <div class="w-1 h-1 rounded-full bg-[#1a4a7a]"></div>
                            @elseif($day['status'] === 'absent' && $day['in_month'] && Carbon\Carbon::parse($day['date'])->isPast())
                                <div class="w-1 h-1 rounded-full bg-zinc-300"></div>
                            @endif
                        </div>

                        @if($day['is_holiday'])
                            <div class="absolute top-2 right-2 flex items-center justify-center bg-blue-50 text-[#1a4a7a] text-[8px] font-bold px-1 rounded">PH</div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Legend --}}
            <div class="mt-8 pt-8 border-t border-[#e8e6df] flex flex-wrap gap-6">
                <div class="flex items-center gap-2 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#1a5c3a]"></div> Present
                </div>
                <div class="flex items-center gap-2 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#d44c4c]"></div> Late
                </div>
                <div class="flex items-center gap-2 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#c4621a]"></div> On leave
                </div>
                <div class="flex items-center gap-2 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="w-1.5 h-1.5 rounded-full bg-[#1a4a7a]"></div> Public Holiday
                </div>
                <div class="flex items-center gap-2 text-[10px] font-bold text-zinc-500 uppercase tracking-widest">
                    <div class="w-1.5 h-1.5 rounded-full bg-zinc-300"></div> Absent
                </div>
            </div>
        </div>

        {{-- Holidays List --}}
        <div class="lg:col-span-4 space-y-6">
            <div class="bg-white rounded-2xl p-8 border-[0.5px] border-[#e8e6df] shadow-sm">
                <h3 class="text-lg font-bold mb-1">Holidays this month</h3>
                <p class="text-xs text-zinc-400 mb-6 uppercase tracking-wider">UK public holidays — {{ $calendarMonth->format('F Y') }}</p>

                <div class="space-y-4">
                    @forelse($monthHolidays as $holiday)
                        <div class="flex items-center gap-4 p-4 border border-[#e8e6df] rounded-xl hover:border-zinc-300 transition-colors">
                            <div class="text-center min-w-[40px]">
                                <div class="text-xl font-bold leading-none">{{ Carbon\Carbon::parse($holiday->date)->format('j') }}</div>
                                <div class="text-[9px] font-bold text-zinc-400 uppercase tracking-widest">{{ Carbon\Carbon::parse($holiday->date)->format('M') }}</div>
                            </div>
                            <div class="w-[1px] h-8 bg-[#e8e6df]"></div>
                            <div class="flex-1">
                                <div class="text-sm font-bold text-zinc-900">{{ $holiday->name }}</div>
                                <div class="inline-flex items-center px-1.5 py-0.5 bg-blue-50 text-[#1a4a7a] text-[9px] font-bold rounded uppercase mt-1">UK Public Holiday</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-8 text-zinc-400 text-sm italic">No holidays this month</div>
                    @endforelse
                </div>

                <div class="mt-8 pt-8 border-t border-[#e8e6df] flex items-center gap-2 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">
                    <span class="w-1.5 h-1.5 rounded-full bg-zinc-300"></span> IP & Geo Logging active
                </div>
            </div>
        </div>
    </div>

    {{-- Attendance Log --}}
    <div class="mt-6 bg-white rounded-2xl p-8 border-[0.5px] border-[#e8e6df] shadow-sm">
        <div class="flex items-center justify-between mb-8">
            <h3 class="text-lg font-bold">Attendance log — {{ $calendarMonth->format('F Y') }}</h3>
            <button class="px-3 py-1.5 border border-[#e8e6df] rounded-lg text-xs font-bold text-zinc-500 hover:bg-zinc-50 transition-colors flex items-center gap-2">
                Filter <flux:icon.chevron-down class="size-3" />
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest border-b border-[#e8e6df]">
                        <th class="pb-4">Date</th>
                        <th class="pb-4">In / Out</th>
                        <th class="pb-4">Break</th>
                        <th class="pb-4">Status</th>
                        <th class="pb-4">Action</th>
                        <th class="pb-4 text-right">Net Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#f7f7f5]">
                    @foreach($history as $item)
                        <tr class="group hover:bg-[#f7f7f5] transition-colors">
                            <td class="py-5">
                                <span class="font-bold text-sm">{{ $item->date->format('d M') }}</span>
                                <span class="text-zinc-400 text-xs ml-1">{{ $item->date->format('D') }}</span>
                            </td>
                            <td class="py-5 font-mono text-xs">
                                {{ $item->check_in->format('H:i') }} — 
                                @if($item->check_out)
                                    {{ $item->check_out->format('H:i') }}
                                @elseif($item->date->isToday())
                                    <span class="text-[#1a5c3a] animate-pulse">running</span>
                                @else
                                    --:--
                                @endif
                            </td>
                            <td class="py-5 text-xs text-zinc-500">
                                {{ $item->break_minutes ?? 0 }}m
                            </td>
                            <td class="py-5">
                                @php
                                    $statusColor = match($item->status) {
                                        'on_time' => 'bg-green-50 text-[#1a5c3a]',
                                        'late' => 'bg-red-50 text-[#d44c4c]',
                                        'remote' => 'bg-blue-50 text-[#1a4a7a]',
                                        'wfh' => 'bg-purple-50 text-purple-700',
                                        default => 'bg-zinc-50 text-zinc-500'
                                    };
                                    $statusLabel = match($item->status) {
                                        'on_time' => 'ON TIME',
                                        'late' => 'LATE',
                                        'remote' => 'REMOTE',
                                        'wfh' => 'WFH',
                                        default => strtoupper($item->status)
                                    };
                                @endphp
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold tracking-wider {{ $statusColor }}">{{ $statusLabel }}</span>
                            </td>
                            <td class="py-5">
                                @if(!$item->check_out && !$item->date->isToday())
                                    <button wire:click="openRegularisation('{{ $item->date->toDateString() }}')" class="text-zinc-400 text-[10px] font-bold uppercase hover:text-[#1a1a1a] transition-colors">Regularisation &rarr;</button>
                                @elseif($item->check_in_ip)
                                    <span class="text-zinc-300 text-[10px] font-mono">{{ $item->check_in_ip }}</span>
                                @endif
                            </td>
                            <td class="py-5 text-right font-bold text-sm font-mono">
                                @if($item->check_out)
                                    @php
                                        $diff = $item->check_in->diff($item->check_out);
                                        $h = $diff->h + ($diff->d * 24);
                                        $m = $diff->i;
                                    @endphp
                                    {{ $h }}h {{ $m }}m
                                @elseif($item->date->isToday())
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 bg-green-50 text-[#1a5c3a] rounded text-[10px] font-bold" wire:poll.10s>
                                        <span class="w-1 h-1 bg-[#1a5c3a] rounded-full"></span>
                                        @php
                                            $diff = now()->diff($item->check_in);
                                            $h = $diff->h + ($diff->d * 24);
                                            $m = $diff->i;
                                        @endphp
                                        {{ $h }}h {{ $m }}m
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
                <flux:input wire:model="regDate" label="Date of Work" type="date" />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="regCheckIn" label="Actual Check-in" type="time" />
                    <flux:input wire:model="regCheckOut" label="Actual Check-out" type="time" />
                </div>

                <flux:textarea wire:model="regReason" label="Reason for correction" placeholder="e.g. System glitch, forgot to clock in..." rows="3" />
            </div>

            <div class="flex gap-2 justify-end mt-6">
                <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitRegularisation" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
