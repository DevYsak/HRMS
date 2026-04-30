<flux:main class="min-h-screen bg-zinc-950 p-0"
    x-data="{ scale: 0.85 }">

    {{-- ─── HERO HEADER ─── --}}
    <div class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 px-8 py-7">
        <div class="pointer-events-none absolute inset-0 opacity-[0.06]"
             style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 24px 24px;"></div>
        <div class="pointer-events-none absolute -top-20 right-0 size-64 rounded-full bg-brand-500/10 blur-3xl"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-5">
            <div>
                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1">
                    <div class="size-1.5 rounded-full bg-brand-400"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-zinc-400">Organization</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">Org Chart</h1>
                <p class="mt-1 text-sm text-zinc-500">Reporting lines & team structure across the organization.</p>
            </div>

            {{-- Live Stats --}}
            @php
                $allEmps    = $topLevel->merge($groupedByManager->flatten());
                $totalCount = $topLevel->count() + $groupedByManager->flatten()->count();
                $deptCount  = $allEmps->pluck('department.name')->filter()->unique()->count();
            @endphp
            <div class="flex items-center gap-3">
                <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-center backdrop-blur-sm">
                    <div class="text-2xl font-black text-white">{{ $totalCount }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Employees</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-center backdrop-blur-sm">
                    <div class="text-2xl font-black text-white">{{ $deptCount }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Departments</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-5 py-3 text-center backdrop-blur-sm">
                    <div class="text-2xl font-black text-white">{{ $topLevel->count() }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Leaders</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─── TOOLBAR ─── --}}
    <div class="flex items-center justify-between border-b border-zinc-800 bg-zinc-900/90 px-6 py-2.5 backdrop-blur-sm">
        <div class="flex items-center gap-1.5">
            {{-- Zoom out --}}
            <button @click="scale = Math.max(0.3, +(scale - 0.1).toFixed(1))"
                class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-800 hover:text-white">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                </svg>
            </button>
            <span class="w-10 text-center text-xs font-bold tabular-nums text-zinc-400" x-text="Math.round(scale * 100) + '%'"></span>
            {{-- Zoom in --}}
            <button @click="scale = Math.min(2, +(scale + 0.1).toFixed(1))"
                class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-800 hover:text-white">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </button>
            <div class="mx-1 h-4 w-px bg-zinc-700"></div>
            {{-- Reset --}}
            <button @click="scale = 0.85"
                class="rounded-lg border border-zinc-700 px-2.5 py-0.5 text-[10px] font-bold text-zinc-500 transition-colors hover:border-zinc-600 hover:text-zinc-300">
                Reset
            </button>
            {{-- Fit --}}
            <button @click="scale = 0.5"
                class="rounded-lg border border-zinc-700 px-2.5 py-0.5 text-[10px] font-bold text-zinc-500 transition-colors hover:border-zinc-600 hover:text-zinc-300">
                Fit
            </button>
        </div>
        <div class="flex items-center gap-3">
            {{-- Legend --}}
            <div class="hidden items-center gap-3 sm:flex">
                @foreach([['#6366f1','Engineering'],['#10b981','Operations'],['#f59e0b','Finance'],['#0ea5e9','Sales']] as [$c,$l])
                    <div class="flex items-center gap-1.5">
                        <div class="size-2 rounded-full" style="background:{{ $c }}"></div>
                        <span class="text-[10px] text-zinc-600">{{ $l }}</span>
                    </div>
                @endforeach
            </div>
            <div class="text-[10px] text-zinc-700">Ctrl + Scroll to zoom</div>
        </div>
    </div>

    {{-- ─── CHART CANVAS ─── --}}
    <div class="relative w-full overflow-auto bg-zinc-950"
         style="min-height: calc(100vh - 200px);"
         @wheel.ctrl.prevent="scale = Math.min(2, Math.max(0.3, +(scale + ($event.deltaY > 0 ? -0.05 : 0.05)).toFixed(2)))">

        {{-- Dot grid background --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.04]"
             style="background-image: radial-gradient(circle, #ffffff 1px, transparent 1px); background-size: 32px 32px;"></div>

        <div class="flex min-w-max flex-col items-center px-20 py-14 transition-transform duration-200 origin-top"
             :style="`transform: scale(${scale})`">

            @if($topLevel->isEmpty())
                <div class="flex flex-col items-center justify-center rounded-2xl border border-zinc-800 bg-zinc-900 p-16 text-center">
                    <div class="mb-4 flex size-16 items-center justify-center rounded-2xl bg-zinc-800">
                        <svg class="size-8 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-zinc-300">No hierarchy found</h3>
                    <p class="mt-1 text-sm text-zinc-600">No reporting lines found for your account.</p>
                </div>
            @else
                <div class="flex gap-24 justify-center">
                    @foreach($topLevel as $manager)
                        @php
                            $reportCount = isset($groupedByManager[$manager->user_id])
                                ? $groupedByManager[$manager->user_id]->count() : 0;
                        @endphp

                        <div class="flex flex-col items-center">

                            {{-- ── ROOT NODE ── --}}
                            <div class="group relative w-72">
                                {{-- Glow --}}
                                <div class="absolute -inset-1 rounded-2xl bg-gradient-to-br from-brand-500/30 to-violet-600/20 opacity-0 blur-xl transition-opacity duration-500 group-hover:opacity-100"></div>

                                <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-zinc-800 to-zinc-900 p-5 shadow-2xl">
                                    {{-- Gradient top bar --}}
                                    <div class="absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r from-brand-500 via-violet-400 to-brand-500"></div>

                                    <div class="flex items-center gap-4">
                                        {{-- Avatar with gradient ring --}}
                                        <div class="relative shrink-0">
                                            <div class="absolute -inset-[2px] rounded-full bg-gradient-to-br from-brand-400 to-violet-500"></div>
                                            <img src="{{ $manager->user->avatarUrl() }}"
                                                 class="relative size-16 rounded-full border-2 border-zinc-900 object-cover" />
                                            <div class="absolute -bottom-0.5 -right-0.5 size-4 rounded-full border-2 border-zinc-900 bg-emerald-400"></div>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <h3 class="truncate text-base font-black text-white">{{ $manager->user->name }}</h3>
                                            <p class="truncate text-[11px] font-bold uppercase tracking-wider text-brand-400">
                                                {{ $manager->jobTitle?->name ?? 'Director' }}
                                            </p>
                                            @if($manager->department)
                                                <span class="mt-1 inline-block rounded-full bg-zinc-700/80 px-2 py-0.5 text-[10px] font-bold text-zinc-400">
                                                    {{ $manager->department->name }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    @if($reportCount > 0)
                                        <div class="mt-3 flex items-center gap-1.5 border-t border-white/5 pt-3 text-[11px] font-bold text-zinc-500">
                                            <svg class="size-3.5 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                                            </svg>
                                            {{ $reportCount }} direct {{ Str::plural('report', $reportCount) }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <x-org-node :node="$manager" :grouped="$groupedByManager" :visited="[$manager->user_id]" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</flux:main>
