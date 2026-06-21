<flux:main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 p-0"
    x-data="{
        scale: 1,
        pan: { x: 0, y: 0 },
        dragging: false,
        startX: 0, startY: 0,
        startPanX: 0, startPanY: 0,
        startDrag(e) {
            this.dragging = true;
            this.startX = e.clientX; this.startY = e.clientY;
            this.startPanX = this.pan.x; this.startPanY = this.pan.y;
        },
        doDrag(e) {
            if (!this.dragging) return;
            this.pan.x = this.startPanX + (e.clientX - this.startX);
            this.pan.y = this.startPanY + (e.clientY - this.startY);
        },
        endDrag() { this.dragging = false; },
        zoom(e) {
            this.scale = Math.min(2, Math.max(0.3, +(this.scale + (e.deltaY > 0 ? -0.08 : 0.08)).toFixed(2)));
        },
        reset() { this.scale = 1; this.pan = { x: 0, y: 0 }; }
    }">

    {{-- ─── HEADER ─── --}}
    <div class="sticky top-0 z-20 border-b border-zinc-200/80 bg-white/90 backdrop-blur-xl px-6 py-4 shadow-sm dark:border-zinc-800/80 dark:bg-zinc-900/90">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="flex size-10 items-center justify-center rounded-xl bg-brand-500 shadow-lg shadow-brand-200/40 dark:shadow-brand-900/40">
                    <svg class="size-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5m.75-9 3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605" />
                    </svg>
                </div>
                <div>
                    <h1 class="pulse-page-title text-xl">Organizational Chart</h1>
                    <p class="pulse-page-subtitle">Reporting structure &amp; team hierarchy</p>
                </div>
            </div>

            {{-- Stats + Zoom Controls --}}
            <div class="flex items-center gap-4">
                @php
                    $allEmps    = $topLevel->merge($groupedByManager->flatten());
                    $totalCount = $topLevel->count() + $groupedByManager->flatten()->unique('id')->count();
                    $deptCount  = $allEmps->pluck('department.name')->filter()->unique()->count();
                @endphp
                <div class="hidden sm:flex items-center gap-3">
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="size-2 rounded-full bg-brand-500"></div>
                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $totalCount }} <span class="font-normal text-zinc-400">employees</span></span>
                    </div>
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="size-2 rounded-full bg-violet-500"></div>
                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $deptCount }} <span class="font-normal text-zinc-400">departments</span></span>
                    </div>
                </div>

                {{-- Zoom controls --}}
                <div class="flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">
                    <button @click="scale = Math.max(0.3, +(scale - 0.1).toFixed(1))"
                        class="flex size-7 items-center justify-center rounded-md text-zinc-500 transition hover:bg-white hover:text-zinc-800 hover:shadow-sm dark:hover:bg-zinc-700 dark:hover:text-zinc-100">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                    </button>
                    <span class="w-12 text-center text-xs font-bold tabular-nums text-zinc-600 dark:text-zinc-300" x-text="Math.round(scale * 100) + '%'"></span>
                    <button @click="scale = Math.min(2, +(scale + 0.1).toFixed(1))"
                        class="flex size-7 items-center justify-center rounded-md text-zinc-500 transition hover:bg-white hover:text-zinc-800 hover:shadow-sm dark:hover:bg-zinc-700 dark:hover:text-zinc-100">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    </button>
                    <div class="mx-0.5 h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>
                    <button @click="reset()"
                        class="rounded-md px-2 py-1 text-[10px] font-semibold text-zinc-500 transition hover:bg-white hover:text-zinc-700 hover:shadow-sm dark:hover:bg-zinc-700 dark:hover:text-zinc-200">
                        Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── CANVAS ─── --}}
    <div class="relative w-full overflow-hidden"
         style="min-height: calc(100vh - 73px); cursor: grab;"
         :style="dragging ? 'cursor: grabbing' : 'cursor: grab'"
         @mousedown="startDrag($event)"
         @mousemove="doDrag($event)"
         @mouseup="endDrag()"
         @mouseleave="endDrag()"
         @wheel.prevent="zoom($event)">

        {{-- Subtle grid background --}}
        <svg class="pointer-events-none absolute inset-0 h-full w-full opacity-[0.35]" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <pattern id="org-grid" width="32" height="32" patternUnits="userSpaceOnUse">
                    <circle cx="0.5" cy="0.5" r="0.5" fill="#cbd5e1" />
                </pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#org-grid)" />
        </svg>

        <div class="flex min-w-max flex-col items-center px-24 py-16 transition-transform duration-150 origin-top select-none"
             :style="`transform: translate(${pan.x}px, ${pan.y}px) scale(${scale})`">

            @if($topLevel->isEmpty())
                <div class="flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white p-16 text-center shadow-sm">
                    <div class="mb-4 flex size-16 items-center justify-center rounded-2xl bg-slate-100">
                        <svg class="size-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-700">No hierarchy found</h3>
                    <p class="mt-1 text-sm text-slate-400">No reporting lines found for your account.</p>
                </div>
            @else
                <div class="flex gap-20 justify-center">
                    @foreach($topLevel as $manager)
                        @php
                            $reportCount = isset($groupedByManager[$manager->user_id])
                                ? $groupedByManager[$manager->user_id]->count() : 0;
                        @endphp

                        <div class="flex flex-col items-center">

                            {{-- ── ROOT NODE (CEO / Director level) ── --}}
                            <div class="group relative w-72">
                                <div class="relative overflow-hidden rounded-2xl border-2 border-indigo-200 bg-white p-5 shadow-xl shadow-indigo-100/60 ring-4 ring-indigo-50 transition-all duration-300 group-hover:-translate-y-1 group-hover:shadow-2xl group-hover:shadow-indigo-200/70">
                                    {{-- Top gradient bar --}}
                                    <div class="absolute inset-x-0 top-0 h-1 rounded-t-2xl bg-gradient-to-r from-indigo-500 via-violet-500 to-indigo-500"></div>

                                    <div class="flex items-center gap-4 pt-1">
                                        {{-- Avatar with ring --}}
                                        <div class="relative shrink-0">
                                            <div class="absolute -inset-1 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 opacity-80 blur-[2px]"></div>
                                            <img src="{{ $manager->user->avatarUrl() }}"
                                                 class="relative size-14 rounded-full border-2 border-white object-cover shadow-md" />
                                            <div class="absolute -bottom-0.5 -right-0.5 size-3.5 rounded-full border-2 border-white bg-emerald-400"></div>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <h3 class="truncate text-base font-bold text-slate-900">{{ $manager->user->name }}</h3>
                                            <p class="truncate text-[11px] font-semibold uppercase tracking-wide text-indigo-600">
                                                {{ $manager->jobTitle?->name ?? 'Director' }}
                                            </p>
                                            @if($manager->department)
                                                <span class="mt-1 inline-block rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-600 ring-1 ring-indigo-100">
                                                    {{ $manager->department->name }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    @if($reportCount > 0)
                                        <div class="mt-3 flex items-center gap-1.5 border-t border-slate-100 pt-3">
                                            <svg class="size-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0Zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0Z" />
                                            </svg>
                                            <span class="text-[11px] font-medium text-slate-500">{{ $reportCount }} direct {{ Str::plural('report', $reportCount) }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Recurse into children --}}
                            <x-org-node :node="$manager" :grouped="$groupedByManager" :visited="[$manager->user_id]" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</flux:main>
