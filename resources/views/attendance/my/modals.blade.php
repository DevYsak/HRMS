{{-- My Attendance modals: punch detail (a day's punches and audit), the manage-regularisation modal, Request Regularization, and the clock in/out capture. Moved unchanged from the previous page. --}}
@use('App\Enums\AttendanceMode')
{{-- ═══════════ ATTENDANCE DECISION — "Why?" audit popup (Rule 11) ═══════════ --}}
<flux:modal name="score-decision" class="max-w-lg">
    @if($decision)
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">Attendance Decision</flux:heading>
                    <flux:subheading>{{ $decision['date'] }}</flux:subheading>
                </div>
                @if($decision['score'] !== null)
                    @php $sc = (int) round($decision['score']); @endphp
                    <div class="text-center">
                        <div class="text-2xl font-black tabular-nums {{ $sc >= 85 ? 'text-emerald-600' : ($sc >= 60 ? 'text-amber-500' : 'text-rose-500') }}">{{ $sc }}<span class="text-xs text-zinc-400">/100</span></div>
                        <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Attendance Score</div>
                    </div>
                @endif
            </div>

            {{-- How the engine read the day --}}
            <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/40 p-3 text-xs">
                <div class="mb-2 flex items-center gap-1.5 font-black text-zinc-700 dark:text-zinc-200"><flux:icon.cog-6-tooth class="size-3.5 text-orange-500" /> Engine inputs</div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 tabular-nums">
                    @if($decision['shift'])
                        <dt class="text-zinc-400">Shift</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['shift']['window'] }}</dd>
                        <dt class="text-zinc-400">Grace</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['shift']['grace'] }}</dd>
                    @endif
                    <dt class="text-zinc-400">First IN</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['first_in'] ?? '—' }}</dd>
                    <dt class="text-zinc-400">Last OUT</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['last_out'] ?? '—' }}{{ $decision['auto_punch_out'] ? ' (auto)' : '' }}</dd>
                    <dt class="text-zinc-400">Worked</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['worked'] }}</dd>
                    <dt class="text-zinc-400">Break</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['break'] }}</dd>
                    @if($decision['late'])<dt class="text-zinc-400">Late</dt><dd class="text-right font-bold text-amber-600">{{ $decision['late'] }}</dd>@endif
                    @if($decision['sessions'])<dt class="text-zinc-400">Sessions</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['sessions'] }}</dd>@endif
                    @if($decision['duplicates'] > 0)<dt class="text-zinc-400">Duplicates merged</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['duplicates'] }}</dd>@endif
                </dl>
                @foreach($decision['ignored'] as $ig)
                    <div class="mt-1.5 flex items-center gap-1.5 text-[11px] text-zinc-400"><flux:icon.no-symbol class="size-3" /> {{ $ig }}</div>
                @endforeach
            </div>

            {{-- Deductions & bonuses — the persisted audit trail --}}
            <div>
                <div class="mb-1.5 flex items-center gap-1.5 text-xs font-black text-zinc-700 dark:text-zinc-200"><flux:icon.scale class="size-3.5 text-blue-500" /> Score breakdown</div>
                @forelse($decision['breakdown'] as $line)
                    <div class="flex items-start justify-between gap-3 border-b border-zinc-50 dark:border-zinc-800/60 py-1.5 text-xs">
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $line['label'] }}</div>
                            <div class="text-[10px] text-zinc-400">{{ $line['detail'] }}</div>
                        </div>
                        <span class="shrink-0 font-black tabular-nums {{ $line['points'] < 0 ? 'text-rose-500' : 'text-emerald-600' }}">{{ $line['points'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($line['points'], 1), '0'), '.') }}</span>
                    </div>
                @empty
                    <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/25 px-3 py-2 text-xs font-bold text-emerald-700 dark:text-emerald-300">Perfect day — no deductions applied.</div>
                @endforelse
                @if($decision['score'] === null)
                    <div class="mt-2 text-[10px] text-zinc-400">This day hasn't been scored yet — the engine scores each day just after midnight.</div>
                @endif
            </div>

            <div class="flex items-center justify-between rounded-xl bg-zinc-50 dark:bg-zinc-800/40 px-3 py-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Final status</span>
                <span class="text-xs font-black {{ $decision['status'] === 'late' ? 'text-amber-600' : ($decision['status'] === 'absent' ? 'text-rose-500' : 'text-emerald-600') }}">
                    {{ ucwords(str_replace('_', ' ', $decision['status'])) }}{{ $decision['regularized'] ? ' · Regularized' : '' }}
                </span>
            </div>
        </div>
    @endif
</flux:modal>

<flux:modal name="punch-detail" class="max-w-lg">
    @if($detail)
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Punch Details</flux:heading>
                    <flux:subheading>{{ $detail['date'] }}</flux:subheading>
                </div>
                @php $dMode = AttendanceMode::tryFromValue($detail['mode']); @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $dMode->chipClass() }}">
                    <flux:icon :icon="$dMode->icon()" class="size-3.5" /> {{ $dMode->label() }}
                </span>
            </div>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['total_hours'] ?? '—' }}<span class="text-[10px] text-zinc-400">h</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Worked</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['break_minutes'] }}<span class="text-[10px] text-zinc-400">m</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Break</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black {{ $detail['is_late'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $detail['is_late'] ? 'Late' : 'On Time' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Status</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['is_late'] ? $detail['late_minutes'].'m' : '—' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Late by</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach(['in' => 'Check In', 'out' => 'Check Out'] as $key => $title)
                    @php $p = $detail[$key]; @endphp
                    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 p-4 dark:border-zinc-800">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-widest {{ $key === 'in' ? 'text-emerald-600' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $title }}</span>
                            <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $p['time'] ?? '—' }}</span>
                        </div>
                        @if($p['photo'])
                            <a href="{{ Storage::url($p['photo']) }}" target="_blank">
                                <img src="{{ Storage::url($p['photo']) }}" alt="Punch selfie" class="mb-2 h-24 w-full rounded-xl object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                            </a>
                        @endif
                        <div class="space-y-1.5 text-[11px]">
                            @if($p['method'])
                                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$p['method_icon']" class="size-3.5 text-zinc-400" /> {{ $p['method'] }}@if(! empty($p['guidance'])) · {{ $p['guidance'] }}@endif</div>
                            @endif
                            <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon.computer-desktop class="size-3.5 text-zinc-400" /> {{ $p['device'] }}</div>
                            @if($p['ip'])
                                <div class="flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"><flux:icon.globe-alt class="size-3.5 text-zinc-400" /> {{ $p['ip'] }}</div>
                            @endif
                            @if($p['lat'] && $p['lng'])
                                <a href="https://www.google.com/maps?q={{ $p['lat'] }},{{ $p['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-semibold text-orange-500 hover:underline"><flux:icon.map-pin class="size-3.5" /> View location</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Attendance Replay — every raw punch of the day --}}
            @if(! empty($detail['punches']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Punch Timeline · {{ count($detail['punches']) }} punches</div>
                    <div class="max-h-48 space-y-1.5 overflow-y-auto pr-1">
                        @foreach($detail['punches'] as $i => $pp)
                            <div class="flex items-center gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-3 py-1.5 text-xs dark:bg-zinc-800/40">
                                <span class="w-16 shrink-0 font-black tabular-nums text-zinc-900 dark:text-white">{{ $pp['time'] }}</span>
                                @if($pp['method'])<span class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$pp['method_icon']" class="size-3.5 text-zinc-400" /> {{ $pp['method'] }}@if(! empty($pp['guidance'])) · {{ $pp['guidance'] }}@endif</span>@endif
                                <span class="ml-auto flex items-center gap-2 text-[10px] text-zinc-400">
                                    @if($pp['location'])<span>{{ $pp['location'] }}</span>@endif
                                    @if($pp['device'])<span>{{ $pp['device'] }}</span>@endif
                                    <span class="uppercase">{{ $pp['source'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Audit History — regularisations touching this day --}}
            @if(! empty($detail['audits']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Audit History</div>
                    <div class="space-y-2">
                        @foreach($detail['audits'] as $audit)
                            @php
                                $ac = match($audit['status']) { 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-600', default => 'bg-amber-100 text-amber-700' };
                            @endphp
                            <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 p-3 text-xs dark:border-zinc-800">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100">Corrected to {{ $audit['requested_in'] }} → {{ $audit['requested_out'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $ac }}">{{ $audit['status'] }}</span>
                                </div>
                                <div class="mt-1 text-zinc-500 dark:text-zinc-400">“{{ $audit['reason'] }}”</div>
                                <div class="mt-1 text-[10px] text-zinc-400">
                                    Submitted {{ $audit['submitted_at'] }}
                                    @if($audit['reviewer']) · {{ ucfirst($audit['status']) }} by {{ $audit['reviewer'] }} on {{ $audit['reviewed_at'] }}@endif
                                </div>

                                {{-- Multi-stage manager approval trail (L1 → L2 → …) --}}
                                @if(! empty($audit['trail']))
                                    <ol class="mt-2.5 space-y-1.5 border-t border-zinc-100 pt-2.5 dark:border-zinc-800">
                                        @foreach($audit['trail'] as $step)
                                            @php
                                                $stepDot = match(strtolower($step['action'])) {
                                                    'approved' => 'bg-emerald-500',
                                                    'rejected' => 'bg-rose-500',
                                                    default => 'bg-amber-400',
                                                };
                                            @endphp
                                            <li class="flex items-start gap-2">
                                                <span class="mt-1 size-1.5 shrink-0 rounded-full {{ $stepDot }}"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold capitalize text-zinc-700 dark:text-zinc-200">{{ $step['stage'] ?: 'Review' }} · {{ ucfirst($step['action']) }}</span>
                                                        @if($step['at'])<span class="shrink-0 text-[9px] text-zinc-400">{{ $step['at'] }}</span>@endif
                                                    </div>
                                                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                                        {{ $step['by'] ?? 'System' }}@if($step['comment']) — “{{ $step['comment'] }}”@endif
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-1">
                <flux:button @click="$flux.modal('punch-detail').close()">Close</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

@include('livewire.attendance.partials.regularisation-manage-modal')

<flux:modal name="regularisation-modal" class="max-w-lg">
    <div class="space-y-5">
        <div class="flex items-start gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600"><flux:icon.pencil-square class="size-5" /></span>
            <div>
                <flux:heading size="lg">Request Regularization</flux:heading>
                <flux:subheading>Fix a missing or wrong punch. Raw device logs stay untouched — the correction applies only after final approval.</flux:subheading>
            </div>
        </div>

        {{-- 1 · When --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">1</span> Which day?</div>
            <flux:input wire:model="regDate" type="date" />
        </div>

        {{-- 2 · Type of fix --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">2</span> What do you need to fix?</div>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regType === 'punch' ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="radio" wire:model.live="regType" value="punch" class="border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regType === 'punch' ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.clock class="size-4" /> Fix a punch</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regType === 'half_day' ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="radio" wire:model.live="regType" value="half_day" class="border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regType === 'half_day' ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.sun class="size-4" /> Mark half day</span>
                </label>
            </div>
        </div>

        {{-- Half-day period (only for half-day requests) --}}
        @if($regType === 'half_day')
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Which half?</div>
            <flux:select wire:model="regHalfDayPeriod">
                <flux:select.option value="first">First half</flux:select.option>
                <flux:select.option value="second">Second half</flux:select.option>
            </flux:select>
        </div>
        @endif

        {{-- Punch fix — which punch (only when fixing a punch) --}}
        @if($regType === 'punch')
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Which punch is missing or wrong?</div>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regFixIn ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="checkbox" wire:model.live="regFixIn" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regFixIn ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.arrow-right-end-on-rectangle class="size-4" /> IN punch</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regFixOut ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="checkbox" wire:model.live="regFixOut" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regFixOut ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.arrow-left-start-on-rectangle class="size-4" /> OUT punch</span>
                </label>
            </div>
        </div>

        {{-- 3 · Expected time + method --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Expected time</div>
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2 {{ $regFixIn ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckIn" label="IN at" type="time" :disabled="! $regFixIn" />
                    <flux:select wire:model="regCheckInMethod" :disabled="! $regFixIn">
                        <flux:select.option value="id_card">via ID Card</flux:select.option>
                        <flux:select.option value="face">via Face</flux:select.option>
                    </flux:select>
                </div>
                <div class="space-y-2 {{ $regFixOut ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckOut" label="OUT at" type="time" :disabled="! $regFixOut" />
                    <flux:select wire:model="regCheckOutMethod" :disabled="! $regFixOut">
                        <flux:select.option value="id_card">via ID Card</flux:select.option>
                        <flux:select.option value="face">via Face</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <p class="mt-1.5 text-[11px] text-zinc-400">Unticked punches keep their recorded time.</p>
        </div>
        @endif

        {{-- 4 · Why --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">4</span> Reason &amp; proof</div>
            <flux:textarea wire:model="regReason" placeholder="e.g. Forgot to clock out — left through the loading gate…" rows="2" />
            @error('regReason')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            <div class="mt-2">
                <input type="file" wire:model="regAttachment" accept=".jpg,.jpeg,.png,.webp,.pdf"
                       class="block w-full cursor-pointer rounded-xl border border-zinc-200 dark:border-zinc-800 text-sm text-zinc-500 file:mr-3 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-orange-50 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-orange-600 hover:file:bg-orange-100" />
                <p class="mt-1 text-[11px] text-zinc-400">Optional — gate pass, screenshot or medical slip (jpg/png/pdf, max 5 MB).</p>
                <div wire:loading wire:target="regAttachment" class="mt-1 text-xs text-orange-500">Uploading…</div>
                @if($regAttachment)<div class="mt-1 flex items-center gap-1.5 text-xs font-semibold text-emerald-600"><flux:icon.check-circle class="size-3.5" /> {{ $regAttachment->getClientOriginalName() }}</div>@endif
                @error('regAttachment')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Approval flow --}}
        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2.5">
            <div class="mb-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-400">What happens next</div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-zinc-500 dark:text-zinc-400">
                Manager <flux:icon.chevron-right class="size-3 text-zinc-300" /> HR <flux:icon.chevron-right class="size-3 text-zinc-300" /> Admin <flux:icon.chevron-right class="size-3 text-zinc-300" /> <span class="text-emerald-600">Approved — hours recalculated</span>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
            <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
            <flux:button wire:click="submitRegularisation" variant="primary" wire:loading.attr="disabled" wire:target="regAttachment,submitRegularisation">
                <span wire:loading.remove wire:target="submitRegularisation">Submit Request</span>
                <span wire:loading wire:target="submitRegularisation">Submitting…</span>
            </flux:button>
        </div>
    </div>
</flux:modal>


<flux:modal name="punch-capture" class="max-w-md"
    x-data="{
        action: 'in', lat: null, lng: null, photo: null,
        stream: null, status: 'idle', geoStatus: 'pending', busy: false,
        async openCapture(action) {
            this.action = action; this.photo = null; this.lat = null; this.lng = null;
            this.geoStatus = 'pending'; this.busy = false;
            this.getLocation();
            await this.startCamera();
        },
        getLocation() {
            if (! ('geolocation' in navigator)) { this.geoStatus = 'unavailable'; return; }
            navigator.geolocation.getCurrentPosition(
                p => { this.lat = +p.coords.latitude.toFixed(6); this.lng = +p.coords.longitude.toFixed(6); this.geoStatus = 'ok'; },
                () => { this.geoStatus = 'denied'; },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        },
        async startCamera() {
            if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) { this.status = 'nocamera'; return; }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.status = 'camera';
                this.$nextTick(() => { if (this.$refs.video) this.$refs.video.srcObject = this.stream; });
            } catch (e) { this.status = 'nocamera'; }
        },
        capture() {
            const v = this.$refs.video, c = this.$refs.canvas;
            if (! v) return;
            const w = 360, h = Math.round(w * (v.videoHeight || 480) / (v.videoWidth || 640));
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(v, 0, 0, w, h);
            this.photo = c.toDataURL('image/jpeg', 0.7);
            this.stopCamera(); this.status = 'preview';
        },
        retake() { this.photo = null; this.startCamera(); },
        stopCamera() { if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; } },
        cleanup() { this.stopCamera(); this.status = 'idle'; this.busy = false; },
        async submit() {
            if (this.busy) return;
            this.busy = true;
            try {
                if (this.action === 'in') { await this.$wire.checkIn(this.lat, this.lng, this.photo); }
                else { await this.$wire.checkOut(this.lat, this.lng, this.photo); }
            } finally {
                this.cleanup();
                this.$flux.modal('punch-capture').close();
            }
        }
    }"
    x-on:open-punch.window="openCapture($event.detail.action)"
    x-on:close="cleanup()">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" x-text="action === 'in' ? 'Clock In' : 'End Work Day'">Clock In</flux:heading>
            <flux:subheading>Confirm with a quick selfie &amp; your location.</flux:subheading>
        </div>

        <div class="relative aspect-[4/3] w-full overflow-hidden rounded-2xl bg-zinc-900">
            <video x-ref="video" autoplay playsinline muted x-show="status === 'camera'" class="h-full w-full object-cover"></video>
            <img :src="photo" x-show="status === 'preview' && photo" class="h-full w-full object-cover" alt="Selfie preview">
            <div x-show="status === 'idle'" class="absolute inset-0 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.camera class="size-9 animate-pulse" />
            </div>
            <div x-show="status === 'nocamera'" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center text-zinc-400">
                <flux:icon.video-camera-slash class="mb-2 size-9" />
                <p class="text-xs">Camera unavailable — you can still clock in without a photo.</p>
            </div>
            <canvas x-ref="canvas" class="hidden"></canvas>
        </div>

        <div class="flex items-center gap-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-xs dark:bg-zinc-800/50">
            <flux:icon.map-pin class="size-4 shrink-0"
                ::class="geoStatus === 'ok' ? 'text-emerald-500' : (geoStatus === 'pending' ? 'text-zinc-400 animate-pulse' : 'text-amber-500')" />
            <span x-show="geoStatus === 'pending'" class="text-zinc-400">Getting your location…</span>
            <span x-show="geoStatus === 'ok'" class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="'Location captured · ' + lat + ', ' + lng"></span>
            <span x-show="geoStatus === 'denied'" class="text-amber-600 dark:text-amber-400">Location off — clocking in without it.</span>
            <span x-show="geoStatus === 'unavailable'" class="text-amber-600 dark:text-amber-400">Location unavailable on this device.</span>
        </div>

        <div class="flex items-center justify-between gap-2 pt-1">
            <button type="button" @click="cleanup(); $flux.modal('punch-capture').close()"
                class="rounded-xl px-4 py-2 text-sm font-bold text-zinc-500 dark:text-zinc-400 transition hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-800">Cancel</button>
            <div class="flex items-center gap-2">
                <button type="button" x-show="status === 'preview'" @click="retake()"
                    class="rounded-xl bg-zinc-100 dark:bg-zinc-800 px-4 py-2 text-sm font-bold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200">Retake</button>
                <button type="button" x-show="status === 'camera'" @click="capture()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-zinc-800 px-4 py-2 text-sm font-bold text-white transition hover:bg-zinc-900 dark:bg-zinc-700">
                    <flux:icon.camera class="size-4" /> Capture
                </button>
                <button type="button" @click="submit()" x-bind:disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-orange-300/40 transition hover:bg-orange-600 disabled:opacity-50"
                    x-text="busy ? 'Saving…' : (action === 'in' ? 'Clock In' : 'Clock Out')">Clock In</button>
            </div>
        </div>
        <p class="text-center text-[10px] text-zinc-400">Photo &amp; location are optional — you can clock in without them.</p>
    </div>
</flux:modal>
