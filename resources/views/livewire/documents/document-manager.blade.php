<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    {{-- ── Page Header ───────────────────────────────────── --}}
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Documents</h1>
            <p class="mt-0.5 text-sm text-zinc-500">
                {{ $canManage ? 'Manage company policies and employee documents.' : 'Your documents and company policies.' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if(auth()->user()->employee)
                <button type="button" onclick="document.getElementById('modal-personal').showModal()"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 shadow-sm transition-colors">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    Upload My Document
                </button>
            @endif
            @if($canManage)
                <button type="button" onclick="document.getElementById('modal-upload').showModal()"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 shadow-sm transition-colors">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    Upload Document
                </button>
            @endif
        </div>
    </div>

    {{-- ── Success Flash ──────────────────────────────────── --}}
    @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
             class="flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-3.5 text-sm text-green-800">
            <svg class="size-5 shrink-0 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="font-medium">{{ session('success') }}</span>
            <button @click="show = false" class="ml-auto text-green-400 hover:text-green-600">✕</button>
        </div>
    @endif

    {{-- ── View Tabs ───────────────────────────────────────── --}}
    @php
        $docTabs = ['library' => 'Library'];
        if ($canManage) {
            $docTabs['expiry'] = 'Expiry Tracking';
            $docTabs['acknowledgements'] = 'Acknowledgements';
        }
    @endphp
    <div class="flex w-fit items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @foreach($docTabs as $val => $label)
            <button wire:click="setView('{{ $val }}')"
                class="rounded-lg px-4 py-1.5 text-sm font-bold transition-all
                    {{ $view === $val ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if($view === 'library')
    {{-- ── Filters ─────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="filterSearch" type="text" placeholder="Search documents…"
                class="pl-9 pr-4 h-9 rounded-lg border border-zinc-200 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 w-64 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
        </div>
        <select wire:model.live="filterCategory"
            class="h-9 rounded-lg border border-zinc-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
            <option value="">All Categories</option>
            <option value="policy">Policy</option>
            <option value="contract">Contract</option>
            <option value="payslip">Payslip</option>
            <option value="personal">My Documents</option>
            <option value="warning_letter">Warning Letters</option>
            <option value="pip">Improvement Plans</option>
            <option value="promotion">Promotions</option>
            <option value="performance_review">Performance Reviews</option>
            <option value="other">Other</option>
        </select>
        @if($canManage)
            <select wire:model.live="filterEmployee"
                class="h-9 rounded-lg border border-zinc-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                <option value="">All Employees</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->user?->name }}</option>
                @endforeach
            </select>
            <div class="flex items-center gap-1.5">
                <span class="text-xs font-medium text-zinc-400">Expiry</span>
                <input type="date" wire:model.live="filterExpiringFrom" aria-label="Expiring from"
                    class="h-9 rounded-lg border border-zinc-200 bg-white px-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
                <span class="text-xs text-zinc-400">&rarr;</span>
                <input type="date" wire:model.live="filterExpiringTo" aria-label="Expiring to"
                    class="h-9 rounded-lg border border-zinc-200 bg-white px-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>
        @endif
    </div>

    {{-- ── Documents Grid ──────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse($documents as $doc)
            <div class="flex flex-col justify-between rounded-xl border border-zinc-200 bg-white p-5 shadow-sm hover:shadow-md transition-shadow dark:border-zinc-800 dark:bg-zinc-900">
                <div>
                    {{-- Title + badges --}}
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <h3 class="font-semibold text-zinc-900 dark:text-white text-sm leading-snug line-clamp-2">{{ $doc->title }}</h3>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">v{{ $doc->version }}</span>
                            <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase
                                @if($doc->category === 'policy') bg-blue-100 text-blue-700
                                @elseif($doc->category === 'contract') bg-purple-100 text-purple-700
                                @elseif($doc->category === 'personal') bg-orange-100 text-orange-700
                                @elseif($doc->category === 'payslip') bg-indigo-100 text-indigo-700
                                @elseif($doc->category === 'warning_letter') bg-red-100 text-red-700
                                @elseif($doc->category === 'pip') bg-amber-100 text-amber-700
                                @elseif($doc->category === 'promotion') bg-emerald-100 text-emerald-700
                                @elseif($doc->category === 'performance_review') bg-sky-100 text-sky-700
                                @else bg-zinc-100 text-zinc-500 @endif">
                                @if($doc->category === 'warning_letter') Warning Letter
                                @elseif($doc->category === 'pip') Improvement Plan
                                @elseif($doc->category === 'promotion') Promotion
                                @elseif($doc->category === 'performance_review') Review
                                @else {{ ucfirst($doc->category) }}
                                @endif
                            </span>
                        </div>
                    </div>

                    @if($doc->description)
                        <p class="text-xs text-zinc-500 mb-3 line-clamp-2">{{ $doc->description }}</p>
                    @endif

                    {{-- Meta --}}
                    <div class="space-y-1 text-xs text-zinc-400 mb-4">
                        <div>Uploaded {{ $doc->created_at->format('d M Y') }} by {{ $doc->uploader?->name ?? 'System' }}</div>
                        @if($doc->expires_at)
                            <div class="font-medium {{ $doc->expires_at->isPast() ? 'text-red-500' : ($doc->expires_at->diffInDays(now()) <= 30 ? 'text-amber-500' : '') }}">
                                Expires {{ $doc->expires_at->format('d M Y') }}
                            </div>
                        @endif
                        @if($canManage && $doc->employee)
                            <div class="text-zinc-500">For: {{ $doc->employee->user->name }}</div>
                        @endif
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    {{-- View + Download --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ URL::temporarySignedRoute('documents.view', now()->addMinutes(5), ['document' => $doc->id]) }}"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 text-sm text-zinc-600 hover:text-orange-600 font-medium transition-colors">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            View
                        </a>
                        <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $doc->id]) }}"
                            class="inline-flex items-center gap-1.5 text-sm text-zinc-600 hover:text-orange-600 font-medium transition-colors">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Download
                        </a>
                        <button type="button" wire:click="preview({{ $doc->id }})"
                            class="inline-flex items-center gap-1.5 text-sm text-zinc-600 hover:text-orange-600 font-medium transition-colors">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Preview
                        </button>
                        @if($doc->versions_count > 0)
                            <button type="button" wire:click="showVersions({{ $doc->id }})"
                                class="inline-flex items-center gap-1.5 text-sm text-zinc-600 hover:text-orange-600 font-medium transition-colors">
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Versions ({{ $doc->versions_count }})
                            </button>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if($doc->requires_acknowledgement)
                            @if(in_array($doc->id, $acknowledgedIds))
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Acknowledged
                                </span>
                            @else
                                <button wire:click="acknowledge({{ $doc->id }})"
                                    class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 transition-colors">
                                    Acknowledge
                                </button>
                            @endif
                        @endif

                        @if($canManage)
                            <div x-data="{ open: false }" class="relative">
                                <button @click="open = !open" type="button"
                                    class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors dark:hover:bg-zinc-800">
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                                <div x-show="open" @click.outside="open = false" x-transition
                                    class="absolute right-0 bottom-8 z-50 w-44 rounded-xl border border-zinc-200 bg-white shadow-lg py-1 dark:border-zinc-700 dark:bg-zinc-900">
                                    <button type="button"
                                        @click="open = false; document.getElementById('new-version-id').value = '{{ $doc->id }}'; document.getElementById('modal-upload').showModal()"
                                        class="w-full flex items-center gap-2 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        New Version
                                    </button>
                                    <button wire:click="delete({{ $doc->id }})"
                                        wire:confirm="Delete this document permanently?"
                                        @click="open = false"
                                        class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-800">
                <svg class="mx-auto mb-3 size-12 text-zinc-200 dark:text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p class="text-sm font-medium text-zinc-400">No documents found.</p>
                <p class="mt-1 text-xs text-zinc-400">Use the upload buttons above to add documents.</p>
            </div>
        @endforelse
    </div>

    <div>{{ $documents->links() }}</div>
    @endif {{-- /library view --}}

    {{-- ── Expiry Tracking ─────────────────────────────────── --}}
    @if($view === 'expiry' && $expiry !== null)
        @php
            $buckets = [
                'expired' => 'Expired',
                'd30' => 'Within 30 days',
                'd60' => '31–60 days',
                'd90' => '61–90 days',
            ];
        @endphp
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-4">
            @foreach($buckets as $key => $label)
                <div class="pulse-card">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-400">{{ $label }}</h3>
                        <span class="rounded-full px-2 py-0.5 text-xs font-black
                            {{ $key === 'expired' ? 'bg-rose-100 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}">
                            {{ $expiry[$key]->count() }}
                        </span>
                    </div>
                    <div class="space-y-3">
                        @forelse($expiry[$key] as $doc)
                            <div class="border-b border-zinc-50 pb-3 last:border-0 last:pb-0 dark:border-zinc-800/60">
                                <p class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $doc->title }}</p>
                                <p class="text-xs text-zinc-400">
                                    {{ $doc->employee?->user?->name ?? 'Company-wide' }} · Expires {{ $doc->expires_at->format('d M Y') }}
                                </p>
                            </div>
                        @empty
                            <p class="py-6 text-center text-xs text-zinc-400">Nothing here.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Acknowledgement Tracker ─────────────────────────── --}}
    @if($view === 'acknowledgements' && $ackTracker !== null)
        <div class="space-y-4">
            @forelse($ackTracker as $row)
                <div class="pulse-card">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $row['document']->title }}</h3>
                            <p class="text-xs text-zinc-400">{{ ucfirst($row['document']->category) }} · {{ ucfirst($row['document']->visibility) }} audience</p>
                        </div>
                        <div class="flex items-center gap-2 text-xs font-bold">
                            <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">{{ $row['acknowledged'] }} confirmed</span>
                            <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">{{ $row['pending'] }} pending</span>
                            <span class="text-zinc-400">of {{ $row['expected'] }}</span>
                        </div>
                    </div>
                    @php $pct = $row['expected'] > 0 ? round($row['acknowledged'] / $row['expected'] * 100) : 0; @endphp
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                    </div>
                    @if($row['pending'] > 0)
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach($row['pendingNames'] as $name)
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $name }}</span>
                            @endforeach
                            @if($row['pending'] > $row['pendingNames']->count())
                                <span class="px-2 py-0.5 text-[11px] font-medium text-zinc-400">+{{ $row['pending'] - $row['pendingNames']->count() }} more</span>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div class="pulse-card py-12 text-center text-sm text-zinc-400">No documents require acknowledgement.</div>
            @endforelse
        </div>
    @endif

    {{-- ── Version History ─────────────────────────────────── --}}
    @if($versionList !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeVersions()">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$wire.closeVersions()"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl ring-1 ring-black/5 dark:bg-zinc-900 dark:ring-zinc-700">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Version History</h3>
                    <button type="button" @click="$wire.closeVersions()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="max-h-[70vh] divide-y divide-zinc-50 overflow-y-auto dark:divide-zinc-800/60">
                    @foreach($versionList as $v)
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">v{{ $v->version }}</span>
                                    @if($loop->first)
                                        <span class="rounded-md bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">Latest</span>
                                    @endif
                                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $v->title }}</p>
                                </div>
                                <p class="mt-0.5 text-xs text-zinc-400">{{ $v->created_at->format('d M Y, H:i') }} · {{ $v->uploader?->name ?? 'System' }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <a href="{{ URL::temporarySignedRoute('documents.view', now()->addMinutes(5), ['document' => $v->id]) }}" target="_blank" class="text-xs font-semibold text-zinc-500 hover:text-orange-600">View</a>
                                <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $v->id]) }}" class="text-xs font-semibold text-zinc-500 hover:text-orange-600">Download</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- ── Document Preview ────────────────────────────────── --}}
    @if($preview !== null)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closePreview()">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="$wire.closePreview()"></div>
            <div class="relative flex w-full max-w-4xl flex-col rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 dark:bg-zinc-900 dark:ring-zinc-700">
                <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <h3 class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $preview['document']->title }}</h3>
                    <div class="flex items-center gap-3">
                        <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $preview['document']->id]) }}" class="text-xs font-semibold text-zinc-500 hover:text-orange-600">Download</a>
                        <button type="button" @click="$wire.closePreview()" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
                <div class="bg-zinc-100 p-2 dark:bg-zinc-950">
                    @if($preview['kind'] === 'pdf')
                        <iframe src="{{ $preview['url'] }}" class="h-[75vh] w-full rounded-lg bg-white" title="Document preview"></iframe>
                    @elseif($preview['kind'] === 'image')
                        <div class="flex max-h-[75vh] items-center justify-center overflow-auto">
                            <img src="{{ $preview['url'] }}" alt="{{ $preview['document']->title }}" class="max-h-[75vh] w-auto rounded-lg" />
                        </div>
                    @else
                        <div class="flex h-48 flex-col items-center justify-center gap-3 text-center">
                            <p class="text-sm text-zinc-500">Preview isn't available for this file type.</p>
                            <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $preview['document']->id]) }}" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Download to view</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ── Modals (inside flux:main = single root element) ── --}}
    {{-- ════════════════════════════════════════════════════════════
         MODAL 1 — HR Upload (native <dialog>, standard form POST)
         ════════════════════════════════════════════════════════════ --}}
@if($canManage)
<dialog id="modal-upload" class="rounded-2xl shadow-2xl border-0 p-0 w-full max-w-lg backdrop:bg-black/40 backdrop:backdrop-blur-sm"
    x-data="{ vis: 'all' }">
    <form method="POST" action="{{ route('documents.upload') }}" enctype="multipart/form-data"
        class="space-y-5 p-6">
        @csrf
        <input type="hidden" id="new-version-id" name="parent_id" value="">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-zinc-900">Upload Document</h2>
                <p class="text-sm text-zinc-500 mt-0.5">PDF and images only · Max 10 MB</p>
            </div>
            <button type="button" onclick="document.getElementById('modal-upload').close()"
                class="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Validation errors --}}
        @if($errors->any())
            <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">Title <span class="text-red-500">*</span></label>
            <input type="text" name="title" value="{{ old('title') }}" required placeholder="e.g. Employee Handbook 2026"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">Description</label>
            <textarea name="description" rows="2" placeholder="Brief description…"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">{{ old('description') }}</textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">File <span class="text-red-500">*</span></label>
            <input type="file" name="file" required accept=".pdf,.png,.jpg,.jpeg"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1 file:text-sm file:font-medium file:text-orange-700 hover:file:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-orange-400">
            <p class="mt-1 text-xs text-zinc-400">PDF, JPG, PNG — max 10 MB</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 mb-1">Category <span class="text-red-500">*</span></label>
                <select name="category" required
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
                    <option value="policy">Policy / Handbook</option>
                    <option value="contract">Contract</option>
                    <option value="personal">Personal Document</option>
                    <option value="payslip">Payslip</option>
                    <option value="form">Form</option>
                    <option value="notice">Notice</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 mb-1">Visibility</label>
                <select name="visibility" x-model="vis"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
                    <option value="all">Company-wide</option>
                    <option value="restricted">Restricted (employee only)</option>
                </select>
            </div>
        </div>

        {{-- Employee assignment (visible when restricted to a specific employee) --}}
        <div x-show="vis === 'restricted'" x-transition style="display:none">
            <label class="block text-sm font-medium text-zinc-700 mb-1">Assign to Employee <span class="text-zinc-400 font-normal text-xs">(optional)</span></label>
            <select name="employee_id"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
                <option value="">Not specific (company-wide)</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->user->name }} — {{ $emp->employee_id }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-zinc-400">If selected, only this employee and HR can see it.</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-zinc-700 mb-1">Expiry Date <span class="text-zinc-400 font-normal text-xs">(optional)</span></label>
                <input type="date" name="expires_at" value="{{ old('expires_at') }}"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
            </div>
            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input type="checkbox" name="requires_acknowledgement" value="1" {{ old('requires_acknowledgement') ? 'checked' : '' }}
                        class="size-4 rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="text-sm text-zinc-700">Requires acknowledgement</span>
                </label>
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4">
            <button type="button" onclick="document.getElementById('modal-upload').close()"
                class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 transition-colors">
                Cancel
            </button>
            <button type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 transition-colors shadow-sm">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                Upload Document
            </button>
        </div>
    </form>
</dialog>
@endif

{{-- ════════════════════════════════════════════════════════════
     MODAL 2 — Employee Personal Upload (native <dialog>)
     ════════════════════════════════════════════════════════════ --}}
@if(auth()->user()->employee)
<dialog id="modal-personal" class="rounded-2xl shadow-2xl border-0 p-0 w-full max-w-md backdrop:bg-black/40 backdrop:backdrop-blur-sm">
    <form method="POST" action="{{ route('documents.upload-personal') }}" enctype="multipart/form-data"
        class="space-y-5 p-6">
        @csrf

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-zinc-900">Upload My Document</h2>
                <p class="text-sm text-zinc-500 mt-0.5">Personal document — only you and HR can see it.</p>
            </div>
            <button type="button" onclick="document.getElementById('modal-personal').close()"
                class="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        @if($errors->any())
            <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">Document Title <span class="text-red-500">*</span></label>
            <input type="text" name="personal_title" value="{{ old('personal_title') }}" required
                placeholder="e.g. Aadhar Card, Bank Statement"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">Notes <span class="text-zinc-400 font-normal text-xs">(optional)</span></label>
            <textarea name="personal_description" rows="2" placeholder="Brief description of this document…"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-400">{{ old('personal_description') }}</textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-zinc-700 mb-1">File <span class="text-red-500">*</span></label>
            <input type="file" name="personal_file" required accept=".pdf,.png,.jpg,.jpeg"
                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1 file:text-sm file:font-medium file:text-orange-700 hover:file:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-orange-400">
            <p class="mt-1 text-xs text-zinc-400">PDF, JPG, PNG — max 10 MB</p>
        </div>

        <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4">
            <button type="button" onclick="document.getElementById('modal-personal').close()"
                class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-600 hover:bg-zinc-100 transition-colors">
                Cancel
            </button>
            <button type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700 transition-colors shadow-sm">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                Upload
            </button>
        </div>
    </form>
</dialog>
@endif

</flux:main>
