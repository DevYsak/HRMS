<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950" x-data="{ tab: 'requests' }">
    {{-- Header Section --}}
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">My Time Off</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage your leave balances, applications, and encashments.</p>
        </div>
        <div class="flex items-center gap-2">
            @if($encashableTypes->isNotEmpty())
                <flux:button @click="$flux.modal('encashment-modal').show()" variant="ghost" icon="banknotes">
                    Encash Leave
                </flux:button>
            @endif
            <flux:button wire:click="openRequestModal" variant="primary" icon="plus">
                Apply for Leave
            </flux:button>
        </div>
    </div>

    {{-- Pending Requests Banner (Top Section) --}}
    <div class="mt-4">
        @if(!empty($pendingCount) && $pendingCount > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="rounded-full bg-amber-100 p-2">
                        <flux:icon.clock class="size-5 text-amber-700" />
                    </div>
                    <div>
                        <div class="text-sm font-bold text-amber-800">You have {{ $pendingCount }} pending leave {{ Str::plural('request', $pendingCount) }}</div>
                        <div class="text-xs text-amber-700/80">Pending applications need approver action. Check the list below or click to apply.</div>
                    </div>
                </div>
                <div>
                    <flux:button wire:click="openRequestModal" variant="primary">Request Leave</flux:button>
                </div>
            </div>
        @else
            <div class="rounded-lg border border-zinc-100 bg-white p-3 text-sm text-zinc-500">No pending leave requests</div>
        @endif
    </div>

    {{-- Stats Section (Weekly pattern, CSL donut, Monthly stats) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Weekly Pattern --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h3 class="text-sm font-bold text-zinc-600 uppercase tracking-wide mb-4">Weekly Pattern</h3>
            <div class="flex items-center gap-3">
                @php
                    $days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                @endphp
                @foreach($days as $i => $d)
                    <div class="flex flex-col items-center text-xs">
                        <div class="w-10 h-8 rounded-md mb-2 transition-all" style="background-color: {{ $weeklyPattern[$i] ? '#7c3aed' : '#eef2ff' }}; box-shadow: {{ $weeklyPattern[$i] ? '0 4px 8px rgba(124,58,237,0.12)' : 'none' }}"></div>
                        <span class="text-xs text-zinc-500">{{ $d }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-[12px] text-zinc-500">Shows which weekdays you have taken leave this year.</p>
        </div>

        {{-- CSL Donut --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h3 class="text-sm font-bold text-zinc-600 uppercase tracking-wide mb-4">Consumed Leave Types</h3>
            <div class="flex items-center gap-6">
                <div class="w-40 h-40">
                    <canvas id="cslDonut"></canvas>
                </div>
                <div>
                    <div class="text-xs font-bold text-zinc-600">{{ $cslData['label'] }}</div>
                    <div class="mt-2 text-sm font-black text-zinc-900">{{ $cslData['used'] }} days used</div>
                    <div class="text-sm text-zinc-400">{{ $cslData['remaining'] }} days remaining</div>
                </div>
            </div>
        </div>

        {{-- Monthly Stats Bar --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h3 class="text-sm font-bold text-zinc-600 uppercase tracking-wide mb-4">Monthly Stats</h3>
            <div class="w-full h-36">
                <canvas id="monthlyBar"></canvas>
            </div>
        </div>
    </div>

    {{-- Leave Balances Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        @forelse($balances as $balance)
            <div class="group relative rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                <div class="absolute top-0 right-0 w-1.5 h-full rounded-r-2xl" style="background-color: {{ $balance->leaveType->color }}"></div>
                
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-zinc-50 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                        @php
                            $icon = match(strtolower($balance->leaveType->name)) {
                                'casual leave', 'cl' => 'sun',
                                'sick leave', 'sl' => 'heart',
                                'earned leave', 'el' => 'briefcase',
                                'compensatory off', 'comp off' => 'gift',
                                default => 'calendar-days'
                            };
                        @endphp
                        <flux:icon :name="$icon" class="size-5" />
                    </div>
                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">{{ $balance->leaveType->name }}</span>
                </div>

                <div class="flex items-baseline gap-1">
                    <span class="text-3xl font-black text-zinc-900 dark:text-white">{{ (float)($balance->allocated_days - $balance->used_days - $balance->encashed_days) }}</span>
                    <span class="text-xs font-bold text-zinc-400 uppercase">Days Left</span>
                </div>

                <div class="mt-5 space-y-2">
                    <div class="flex justify-between text-[10px] font-bold text-zinc-400 uppercase tracking-widest">
                        <span>Used: {{ (float)$balance->used_days }}</span>
                        <span>Total: {{ (float)$balance->allocated_days }}</span>
                    </div>
                    <div class="h-1.5 w-full bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                        @php 
                            $percentage = ($balance->allocated_days > 0) ? (($balance->used_days + $balance->encashed_days) / $balance->allocated_days) * 100 : 0;
                        @endphp
                        <div class="h-full rounded-full transition-all duration-500" style="width: {{ min(100, $percentage) }}%; background-color: {{ $balance->leaveType->color }}"></div>
                    </div>
                </div>

                @if($balance->encashed_days > 0)
                    <div class="mt-3 flex items-center gap-1.5 text-[10px] font-bold text-amber-600 dark:text-amber-500 bg-amber-50 dark:bg-amber-900/20 px-2 py-1 rounded-lg">
                        <flux:icon.banknotes class="size-3" />
                        <span>{{ (float)$balance->encashed_days }} Days Encashed/Reserved</span>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-zinc-200 p-8 text-center sm:col-span-2 lg:col-span-4 dark:border-zinc-800">
                <flux:icon.calendar-days class="size-8 mx-auto mb-3 text-zinc-300 dark:text-zinc-700" />
                <p class="text-sm text-zinc-500">No leave balances found for your profile. Please contact HR.</p>
            </div>
        @endforelse
    </div>

    {{-- History & Tabs Section --}}
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="flex items-center gap-1 p-1 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-100 dark:border-zinc-800">
            <button 
                @click="tab = 'requests'" 
                :class="tab === 'requests' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex-1 md:flex-none px-6 py-2 text-sm font-bold rounded-xl transition-all"
            >
                Leave Applications
            </button>
            <button 
                @click="tab = 'encashments'" 
                :class="tab === 'encashments' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex-1 md:flex-none px-6 py-2 text-sm font-bold rounded-xl transition-all"
            >
                Encashment History
            </button>
        </div>

        {{-- Leave Requests Tab --}}
        <div x-show="tab === 'requests'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-zinc-50/50 dark:bg-zinc-950/50">
                            <th class="py-4 pl-6 pr-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Type</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Duration</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest text-center">Days</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Status</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Reviewer</th>
                            <th class="py-4 pr-6 text-[10px] font-bold text-zinc-400 uppercase tracking-widest text-right">Applied On</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($requests as $req)
                            <tr class="group hover:bg-zinc-50/30 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2 rounded-full shadow-sm" style="background-color: {{ $req->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-700 dark:text-zinc-300">{{ $req->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                        <span class="font-medium">{{ $req->start_date->format('d M') }}</span>
                                        <flux:icon.arrow-long-right class="inline size-3 mx-1 opacity-40" />
                                        <span class="font-medium">{{ $req->end_date->format('d M, Y') }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <span class="text-sm font-black text-zinc-900 dark:text-white">{{ (float)$req->days }}</span>
                                </td>
                                <td class="py-4 px-4">
                                    @php
                                        $statusClasses = match($req->status) {
                                            'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                            'rejected' => 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400',
                                            'pending' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                            default => 'bg-zinc-50 text-zinc-700'
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider {{ $statusClasses }}">
                                        {{ $req->status }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    @if($req->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="size-6 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-[8px] font-black uppercase">
                                                {{ collect(explode(' ', $req->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs text-zinc-500">{{ $req->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-[10px] font-bold text-zinc-400 italic uppercase">Waiting ...</span>
                                    @endif
                                </td>
                                <td class="py-4 pr-6 text-right">
                                    <span class="text-xs text-zinc-400">{{ $req->created_at->format('d M Y') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <flux:icon.document-magnifying-glass class="size-10 mx-auto mb-3 text-zinc-200 dark:text-zinc-800" />
                                    <p class="text-sm text-zinc-400 italic font-medium">You haven't requested any time off yet.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($requests->hasPages())
                <div class="p-4 border-t border-zinc-50 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>

        {{-- Encashments Tab --}}
        <div x-show="tab === 'encashments'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-zinc-50/50 dark:bg-zinc-950/50">
                            <th class="py-4 pl-6 pr-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Leave Type</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest text-center">Requested Days</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Status</th>
                            <th class="py-4 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Payout Cycle</th>
                            <th class="py-4 pr-6 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($encashments as $enc)
                            <tr class="group hover:bg-zinc-50/30 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2 rounded-full shadow-sm" style="background-color: {{ $enc->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-700 dark:text-zinc-300">{{ $enc->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <span class="text-sm font-black text-zinc-900 dark:text-white">{{ (float)$enc->requested_days }}</span>
                                </td>
                                <td class="py-4 px-4">
                                    @php
                                        $encStatusClasses = match($enc->status) {
                                            'approved', 'processed' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                            'rejected' => 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400',
                                            'pending' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                            default => 'bg-zinc-50 text-zinc-700'
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider {{ $encStatusClasses }}">
                                        {{ $enc->status }}
                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400 font-bold">
                                        <flux:icon.calendar class="size-3" />
                                        {{ $enc->payout_month ? Carbon::parse($enc->payout_month)->format('M Y') : '--' }}
                                    </div>
                                </td>
                                <td class="py-4 pr-6">
                                    @if($enc->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="size-6 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-[8px] font-black uppercase">
                                                {{ collect(explode(' ', $enc->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs text-zinc-500">{{ $enc->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-[10px] font-bold text-zinc-400 italic uppercase">Waiting ...</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-16 text-center">
                                    <flux:icon.banknotes class="size-10 mx-auto mb-3 text-zinc-200 dark:text-zinc-800" />
                                    <p class="text-sm text-zinc-400 italic font-medium">No encashment requests found.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Request Leave Modal --}}
    <flux:modal wire:model="showRequestModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Request Time Off</flux:heading>
                <flux:subheading>Submit a new leave application for review.</flux:subheading>
            </div>

            <form wire:submit="submitRequest" class="space-y-5">
                <flux:select wire:model="leave_type_id" label="Leave Type" placeholder="Select type..." required>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'Paid' : 'Unpaid' }})</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>

                <flux:textarea wire:model="reason" label="Reason" placeholder="Please describe why you need time off..." rows="3" required />

                <div class="flex justify-end gap-3 pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Submit Request</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Encashment Modal --}}
    <flux:modal name="encashment-modal" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Encash Leave Balance</flux:heading>
                <flux:subheading>Convert your unused paid leave days into salary payout.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="encash_leave_type_id" label="Select Leave Type" required>
                    @foreach($encashableTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="encash_days" label="Days to Encash" type="number" step="0.5" suffix="Days" required />
                
                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-100 dark:border-zinc-800">
                    <p class="text-[10px] text-zinc-500 leading-relaxed uppercase font-bold tracking-wider">
                        <flux:icon.information-circle class="inline size-3 mr-1" />
                        Only CSL leaves are eligible for encashment as per company policy. Requests are subject to HR & Finance approval.
                    </p>
                </div>
            </div>

            <div class="flex gap-2 justify-end mt-6">
                <flux:button @click="$flux.modal('encashment-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitEncashment" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('livewire:load', function () {
            // CSL Donut
            try {
                const donutEl = document.getElementById('cslDonut');
                if (donutEl) {
                    new Chart(donutEl.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Used','Remaining'],
                            datasets: [{
                                data: [parseFloat(@json($cslData['used'])), parseFloat(@json($cslData['remaining']))],
                                backgroundColor: [@json($cslData['color']), '#e5e7eb'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            cutout: '70%',
                            plugins: { legend: { display: false } }
                        }
                    });
                }

                // Monthly bar
                const barEl = document.getElementById('monthlyBar');
                if (barEl) {
                    new Chart(barEl.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
                            datasets: [{
                                label: 'Leave days',
                                data: @json($monthlyStats),
                                backgroundColor: '#7c3aed'
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            scales: {
                                x: { grid: { display: false } },
                                y: { beginAtZero: true, ticks: { stepSize: 1 } }
                            },
                            plugins: { legend: { display: false } }
                        }
                    });
                }
            } catch (e) {
                console.error('Chart init error', e);
            }
        });
    </script>
</flux:main>
