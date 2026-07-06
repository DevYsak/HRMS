<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-6">

    {{-- ── Header ──────────────────────────────────────────────────── --}}
    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-black text-zinc-900 dark:text-white">Biometric Sync</h1>
            <p class="text-sm text-zinc-500">eSSL / ZKTeco device attendance management</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button wire:click="pingDevices" variant="ghost" icon="signal">Ping Devices</flux:button>
            <flux:button wire:click="syncAll" variant="primary" icon="arrow-path">Sync All</flux:button>
        </div>
    </div>

    {{-- ── Flash ───────────────────────────────────────────────────── --}}
    @if($flashMessage)
        @php
            $alertClass = match($flashType) {
                'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300',
                'error'   => 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/40 dark:bg-rose-950/20 dark:text-rose-300',
                default   => 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/40 dark:bg-blue-950/20 dark:text-blue-300',
            };
        @endphp
        <div class="rounded-xl border px-4 py-3 text-sm font-medium {{ $alertClass }}">{{ $flashMessage }}</div>
    @endif

    {{-- ── Tab Bar ──────────────────────────────────────────────────── --}}
    <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
        <button type="button" wire:click="$set('activeTab','sync')"
            class="border-b-2 px-4 pb-3 pt-1 text-sm font-bold transition-colors
                {{ $activeTab === 'sync'
                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                    : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            Sync &amp; Logs
        </button>
        <button type="button" wire:click="$set('activeTab','users')"
            class="flex items-center gap-1.5 border-b-2 px-4 pb-3 pt-1 text-sm font-bold transition-colors
                {{ $activeTab === 'users'
                    ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                    : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            Device Users
            @php $mapped = collect($deviceUsers)->whereNotNull('employee_id')->count(); @endphp
            @if($usersFetched)
                <span class="rounded-full bg-zinc-100 px-1.5 py-0.5 text-[10px] font-black text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                    {{ count($deviceUsers) }}
                </span>
            @endif
        </button>
    </div>

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- ── TAB: SYNC & LOGS ────────────────────────────────────────── --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}
    @if($activeTab === 'sync')

        {{-- Device cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($this->devices as $device)
                @php
                    $statusColor = match($device->last_ping_status) {
                        'online'  => 'bg-emerald-400',
                        'offline' => 'bg-amber-400',
                        'error'   => 'bg-rose-400',
                        default   => 'bg-zinc-300',
                    };
                @endphp
                <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mb-3 flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="inline-block size-2 rounded-full {{ $statusColor }}"></span>
                                <span class="font-black text-zinc-900 dark:text-white">{{ $device->name }}</span>
                            </div>
                            <p class="mt-0.5 font-mono text-xs text-zinc-400">{{ $device->ip_address }}:{{ $device->port }}</p>
                        </div>
                        <flux:badge color="{{ $device->is_active ? 'green' : 'zinc' }}">
                            {{ $device->is_active ? 'Active' : 'Disabled' }}
                        </flux:badge>
                    </div>
                    <div class="mb-4 space-y-1.5 text-xs text-zinc-500">
                        <div class="flex justify-between">
                            <span>Last synced</span>
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $device->last_synced_at?->diffForHumans() ?? 'Never' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Last sync count</span>
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $device->last_sync_count }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Last ping</span>
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $device->last_ping_at?->diffForHumans() ?? 'Never' }}</span>
                        </div>
                        @if($device->last_ping_error)
                            <div class="rounded-lg bg-rose-50 px-2 py-1.5 text-rose-600 dark:bg-rose-950/20 dark:text-rose-400">
                                {{ $device->last_ping_error }}
                            </div>
                        @endif
                    </div>
                    <flux:button
                        wire:click="$set('selectedDeviceId', {{ $device->id }})"
                        class="w-full"
                        variant="{{ $selectedDeviceId == $device->id ? 'primary' : 'ghost' }}"
                        icon="check-circle">
                        {{ $selectedDeviceId == $device->id ? 'Selected' : 'Select' }}
                    </flux:button>
                </div>
            @empty
                <div class="col-span-3 rounded-2xl border border-dashed border-zinc-200 p-10 text-center text-zinc-400 dark:border-zinc-700">
                    No devices configured.
                </div>
            @endforelse
        </div>

        {{-- Sync selected --}}
        @if($selectedDeviceId)
            <div class="rounded-2xl border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="mb-4 text-sm font-black uppercase tracking-widest text-zinc-400">Sync Selected Device</h2>
                <div class="flex gap-3">
                    <flux:button wire:click="syncDevice" variant="primary" icon="arrow-path"
                        wire:loading.attr="disabled" wire:target="syncDevice">
                        <span wire:loading.remove wire:target="syncDevice">Pull Attendance Logs</span>
                        <span wire:loading wire:target="syncDevice">Syncing…</span>
                    </flux:button>
                    <flux:button wire:click="$set('selectedDeviceId', null)" variant="ghost" icon="x-mark">Deselect</flux:button>
                </div>
            </div>
        @endif

        {{-- Stats --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Total Logs</p>
                <p class="mt-1 text-2xl font-black text-zinc-900 dark:text-white">{{ number_format(\App\Models\BiometricLog::count()) }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Unprocessed</p>
                <p class="mt-1 text-2xl font-black {{ $this->unprocessedCount > 0 ? 'text-amber-600' : 'text-zinc-900 dark:text-white' }}">{{ $this->unprocessedCount }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Unmatched IDs</p>
                <p class="mt-1 text-2xl font-black {{ $this->unmatchedCount > 0 ? 'text-rose-600' : 'text-zinc-900 dark:text-white' }}">{{ $this->unmatchedCount }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Devices Online</p>
                <p class="mt-1 text-2xl font-black text-zinc-900 dark:text-white">
                    {{ $this->devices->where('last_ping_status', 'online')->count() }} / {{ $this->devices->count() }}
                </p>
            </div>
        </div>

        {{-- Recent Logs table --}}
        <div class="pulse-card p-0 overflow-hidden">
            <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                <h2 class="font-black text-zinc-900 dark:text-white">Recent Punch Logs</h2>
                <span class="text-xs text-zinc-400">Last 50 records</span>
            </div>
            <div class="pulse-table-wrap">
                <table class="pulse-table">
                    <thead>
                        <tr>
                            <th class="pulse-th pl-5">Employee</th>
                            <th class="pulse-th">Device ID</th>
                            <th class="pulse-th">Punched At</th>
                            <th class="pulse-th">Type</th>
                            <th class="pulse-th">Verify</th>
                            <th class="pulse-th">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->recentLogs as $log)
                            @php
                                $typeColor = match($log->punch_type) {
                                    'check_in'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                    'check_out' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400',
                                    'ot_in'     => 'bg-purple-100 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400',
                                    'ot_out'    => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400',
                                    default     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                };
                            @endphp
                            <tr>
                                <td class="pulse-td pl-5">
                                    @if($log->employee)
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $log->employee->user?->name }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ $log->employee->employee_id }}</div>
                                    @else
                                        <span class="font-mono text-xs text-rose-500">Unmatched</span>
                                    @endif
                                </td>
                                <td class="pulse-td font-mono text-xs">{{ $log->device_user_id }}</td>
                                <td class="pulse-td">{{ $log->punched_at->format('d M Y, H:i:s') }}</td>
                                <td class="pulse-td">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase {{ $typeColor }}">
                                        {{ str_replace('_', ' ', $log->punch_type) }}
                                    </span>
                                </td>
                                <td class="pulse-td text-xs">
                                    {{ config('biometric.verify_types')[$log->verify_type] ?? "#{$log->verify_type}" }}
                                </td>
                                <td class="pulse-td">
                                    @if($log->is_processed)
                                        <flux:badge color="green">Applied</flux:badge>
                                    @elseif($log->process_error)
                                        <flux:badge color="red" :title="$log->process_error">Error</flux:badge>
                                    @else
                                        <flux:badge color="yellow">Pending</flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="pulse-table__empty">
                                    No biometric logs yet. Run a sync to pull data from the device.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- ── TAB: DEVICE USERS ───────────────────────────────────────── --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}
    @elseif($activeTab === 'users')

        {{-- Fetch controls --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="mb-4 text-sm font-black uppercase tracking-widest text-zinc-400">Fetch Enrolled Users from Device</h2>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <x-clean-select model="usersDeviceId" label="Select Device" placeholder="— Choose device —" :live="false"
                        :options="collect($this->devices)->map(fn ($device) => ['value' => $device->id, 'label' => $device->name.' ('.$device->ip_address.')'])->all()" />
                </div>
                <flux:button wire:click="fetchDeviceUsers" variant="primary" icon="users"
                    wire:loading.attr="disabled" wire:target="fetchDeviceUsers">
                    <span wire:loading.remove wire:target="fetchDeviceUsers">Fetch Users</span>
                    <span wire:loading wire:target="fetchDeviceUsers">Fetching…</span>
                </flux:button>
            </div>
        </div>

        {{-- Users table --}}
        @if($usersFetched)
            @php
                $totalUsers  = count($deviceUsers);
                $mappedCount = collect($deviceUsers)->whereNotNull('employee_id')->count();
            @endphp

            {{-- Mini stats --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">On Device</p>
                    <p class="mt-1 text-2xl font-black text-zinc-900 dark:text-white">{{ $totalUsers }}</p>
                </div>
                <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Mapped to HRMS</p>
                    <p class="mt-1 text-2xl font-black text-emerald-600">{{ $mappedCount }}</p>
                </div>
                <div class="rounded-2xl border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-bold uppercase tracking-widest text-zinc-400">Unmapped</p>
                    <p class="mt-1 text-2xl font-black {{ ($totalUsers - $mappedCount) > 0 ? 'text-rose-600' : 'text-zinc-900 dark:text-white' }}">
                        {{ $totalUsers - $mappedCount }}
                    </p>
                </div>
            </div>

            <div class="pulse-card p-0 overflow-hidden">
                <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <h2 class="font-black text-zinc-900 dark:text-white">Enrolled Users</h2>
                    <span class="text-xs text-zinc-400">{{ $mappedCount }} / {{ $totalUsers }} mapped</span>
                </div>
                <div class="pulse-table-wrap">
                    <table class="pulse-table">
                        <thead>
                            <tr>
                                <th class="pulse-th pl-5">Device ID</th>
                                <th class="pulse-th">Name on Device</th>
                                <th class="pulse-th">Privilege</th>
                                <th class="pulse-th">Mapped HRMS Employee</th>
                                <th class="pulse-th">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deviceUsers as $user)
                                @php
                                    $emp = $user['employee_id']
                                        ? $this->employees->firstWhere('id', $user['employee_id'])
                                        : null;
                                    $privilegeLabel = match((int)$user['privilege']) {
                                        0 => 'User',
                                        14 => 'Admin',
                                        default => 'Level '.$user['privilege'],
                                    };
                                @endphp
                                <tr>
                                    {{-- Device ID --}}
                                    <td class="pulse-td pl-5">
                                        <span class="inline-flex items-center rounded-lg bg-zinc-100 px-2.5 py-1 font-mono text-xs font-black text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                            #{{ $user['device_user_id'] }}
                                        </span>
                                    </td>
                                    {{-- Name on device --}}
                                    <td class="pulse-td font-medium text-zinc-900 dark:text-white">
                                        {{ $user['name'] ?: '—' }}
                                    </td>
                                    {{-- Privilege --}}
                                    <td class="pulse-td">
                                        <flux:badge color="{{ $user['privilege'] > 0 ? 'amber' : 'zinc' }}">
                                            {{ $privilegeLabel }}
                                        </flux:badge>
                                    </td>
                                    {{-- Mapped employee --}}
                                    <td class="pulse-td">
                                        @if($emp)
                                            <div class="flex items-center gap-2">
                                                <div class="flex size-7 items-center justify-center rounded-full bg-emerald-100 text-xs font-black text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                    {{ strtoupper(substr($emp->user?->name ?? '?', 0, 1)) }}
                                                </div>
                                                <div>
                                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $emp->user?->name }}</div>
                                                    <div class="text-[11px] text-zinc-400">{{ $emp->employee_id }}</div>
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-xs text-zinc-400">Not mapped</span>
                                        @endif
                                    </td>
                                    {{-- Actions --}}
                                    <td class="pulse-td">
                                        <div class="flex items-center gap-2">
                                            <flux:button
                                                wire:click="openMapping('{{ $user['device_user_id'] }}', '{{ addslashes($user['name']) }}')"
                                                variant="ghost" size="sm" icon="link">
                                                {{ $emp ? 'Re-map' : 'Map to Employee' }}
                                            </flux:button>
                                            @if($emp)
                                                <flux:button
                                                    wire:click="removeMapping('{{ $user['device_user_id'] }}')"
                                                    wire:confirm="Remove mapping for device user #{{ $user['device_user_id'] }}?"
                                                    variant="ghost" size="sm" icon="x-mark"
                                                    class="text-rose-500 hover:text-rose-700">
                                                    Remove
                                                </flux:button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center text-sm text-zinc-400">
                                        No users enrolled on this device.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                <div class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon.users class="size-7 text-zinc-400" />
                </div>
                <p class="font-bold text-zinc-700 dark:text-zinc-200">No users loaded yet</p>
                <p class="mt-1 text-sm text-zinc-400">Select a device above and click <strong>Fetch Users</strong></p>
            </div>
        @endif

    @endif

    {{-- ════════════════════════════════════════════════════════════════ --}}
    {{-- ── Mapping Modal ───────────────────────────────────────────── --}}
    {{-- ════════════════════════════════════════════════════════════════ --}}
    @if($mappingDeviceUserId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" wire:click.self="closeMapping">
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <div>
                        <h3 class="font-black text-zinc-900 dark:text-white">Map Device User</h3>
                        <p class="mt-0.5 text-sm text-zinc-400">
                            Device #{{ $mappingDeviceUserId }}
                            @if($mappingDeviceUserName) · <em>{{ $mappingDeviceUserName }}</em> @endif
                        </p>
                    </div>
                    <button wire:click="closeMapping" class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200">
                        <flux:icon.x-mark class="size-5" />
                    </button>
                </div>

                <div class="space-y-4 p-6">
                    {{-- Search --}}
                    <div>
                        <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-zinc-500">
                            Search Employee
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="mappingEmployeeSearch"
                            placeholder="Name or Employee ID…"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                    </div>

                    {{-- Search results --}}
                    @if($this->employeeSearchResults->isNotEmpty())
                        <div class="max-h-56 overflow-y-auto rounded-xl border border-zinc-100 dark:border-zinc-800">
                            @foreach($this->employeeSearchResults as $emp)
                                <button type="button"
                                    wire:click="$set('mappingEmployeeId', {{ $emp->id }})"
                                    class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800
                                        {{ $mappingEmployeeId == $emp->id ? 'bg-brand-50 dark:bg-brand-900/20' : '' }}">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-sm font-black text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                        {{ strtoupper(substr($emp->user?->name ?? '?', 0, 1)) }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-zinc-900 dark:text-white">{{ $emp->user?->name }}</div>
                                        <div class="text-[11px] text-zinc-400">
                                            {{ $emp->employee_id }}
                                            @if($emp->biometric_id)
                                                · <span class="text-amber-500">already mapped to device #{{ $emp->biometric_id }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if($mappingEmployeeId == $emp->id)
                                        <flux:icon.check-circle class="ml-auto size-5 shrink-0 text-brand-500" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @elseif(strlen($mappingEmployeeSearch) > 0)
                        <p class="text-center text-sm text-zinc-400">No employees found.</p>
                    @endif

                    {{-- Selected employee confirmation --}}
                    @if($mappingEmployeeId)
                        @php $selected = $this->employees->firstWhere('id', $mappingEmployeeId); @endphp
                        @if($selected)
                            <div class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                                <flux:icon.check-circle class="size-5 text-emerald-600" />
                                <div>
                                    <div class="font-bold text-emerald-800 dark:text-emerald-300">{{ $selected->user?->name }}</div>
                                    <div class="text-xs text-emerald-600 dark:text-emerald-400">{{ $selected->employee_id }}</div>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">
                    <flux:button wire:click="closeMapping" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveMapping" variant="primary" icon="link"
                        :disabled="!$mappingEmployeeId">
                        Save Mapping
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

</flux:main>
