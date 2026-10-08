@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    $categories = collect($sections)->groupBy('category');
    $extraNav = $isEmployeeJourney ? [
        ['id' => 'can-cannot', 'title' => 'What you can and cannot do'],
        ['id' => 'faq', 'title' => 'FAQ'],
    ] : [];
    $statusTone = fn (string $status): string => match (true) {
        str_contains($status, 'Approved'), $status === 'Locked' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        str_contains($status, 'Rejected') => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        str_contains($status, 'Info') => 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20',
        str_contains($status, 'Cancelled'), $status === 'Draft' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
        default => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
    };
    $tipStyle = [
        'tip' => ['light-bulb', 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200', 'Tip'],
        'info' => ['information-circle', 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-200', 'Good to know'],
        'warning' => ['exclamation-triangle', 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200', 'Heads up'],
    ];
    $version = $capturedAt ? substr(md5($capturedAt), 0, 8) : '0';
    $lightboxShots = collect($shots)->map(fn ($shot) => [
        'title' => $shot['title'] ?? '',
        'annotated' => $assetBase.'/'.$shot['annotated'].'?v='.$version,
        'original' => $assetBase.'/'.$shot['file'].'?v='.$version,
        'markers' => $shot['markers'] ?? [],
    ]);
@endphp

<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <div
        x-data="{
            shots: @js($lightboxShots),
            total: {{ count($sections) + count($extraNav) }},
            query: '',
            active: null,
            seen: [],
            visibleCount: {{ count($sections) + count($extraNav) }},
            lightbox: { open: false, items: [], index: 0, original: false },
            init() {
                try { this.seen = JSON.parse(localStorage.getItem('pulse.employeeGuide.seen') || '[]'); } catch (e) { this.seen = []; }
                const observer = new IntersectionObserver((entries) => {
                    entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                        this.active = entry.target.id;
                        if (! this.seen.includes(entry.target.id)) {
                            this.seen.push(entry.target.id);
                            try { localStorage.setItem('pulse.employeeGuide.seen', JSON.stringify(this.seen)); } catch (e) {}
                        }
                    });
                }, { rootMargin: '-20% 0px -60% 0px' });
                this.$root.querySelectorAll('[data-guide-section]').forEach((el) => observer.observe(el));
                this.$watch('query', () => this.$nextTick(() => {
                    this.visibleCount = [...this.$root.querySelectorAll('[data-guide-section]')].filter((el) => el.style.display !== 'none').length;
                }));
            },
            matches(text) {
                const words = this.query.toLowerCase().split(/\s+/).filter(Boolean);
                return words.every((word) => text.includes(word) || text.includes(word.replace(/s$/, '')));
            },
            progress() { return Math.round((Math.min(this.seen.length, this.total) / this.total) * 100); },
            progressLabel() { return `${Math.min(this.seen.length, this.total)} of ${this.total} sections read`; },
            openLightbox(id) {
                const ids = Object.keys(this.shots);
                this.lightbox.items = ids;
                this.lightbox.index = Math.max(0, ids.indexOf(id));
                this.lightbox.original = false;
                this.lightbox.open = true;
                document.body.style.overflow = 'hidden';
            },
            closeLightbox() { this.lightbox.open = false; document.body.style.overflow = ''; },
            step(direction) {
                const count = this.lightbox.items.length;
                this.lightbox.index = (this.lightbox.index + direction + count) % count;
            },
            current() { return this.shots[this.lightbox.items[this.lightbox.index]] ?? null; },
        }"
        x-on:keydown.escape.window="closeLightbox()"
        x-on:keydown.arrow-right.window="lightbox.open && step(1)"
        x-on:keydown.arrow-left.window="lightbox.open && step(-1)"
        class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6"
    >
        {{-- Hero --}}
        <header class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[#2a1406] via-[#1c0f06] to-black p-6 text-white shadow-lg sm:p-8">
            <div class="pointer-events-none absolute -right-16 -top-16 size-64 rounded-full bg-orange-500/20 blur-3xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-widest text-orange-200">
                        <flux:icon.lifebuoy class="size-3.5" /> Help Centre
                    </span>
                    <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">{{ $current['title'] }}</h1>
                    <p class="mt-2 text-sm text-zinc-300 sm:text-base">{{ $current['intro'] }}</p>
                </div>
                <div class="w-full lg:max-w-sm">
                    <label for="guide-search" class="sr-only">Search the guide</label>
                    <div class="relative">
                        <flux:icon.magnifying-glass class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                        <input id="guide-search" type="search" x-model.debounce.150ms="query" x-ref="search"
                               placeholder="Search: leave, payslip, attendance correction…"
                               class="w-full rounded-xl border-0 bg-white py-3 pl-10 pr-3 text-sm text-zinc-900 shadow-sm ring-1 ring-white/20 placeholder:text-zinc-400 focus:ring-2 focus:ring-orange-400">
                    </div>
                    <div class="mt-3">
                        <div class="flex items-center justify-between text-[11px] font-semibold text-zinc-400">
                            <span>Your progress</span>
                            <span x-text="progressLabel()"></span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-orange-500 transition-all duration-500" :style="`width: ${progress()}%`"></div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        {{-- Journeys: one per role this reader actually has; each lists only pages they can open. --}}
        @if(count($journeys) > 1)
            <nav aria-label="Guide for" class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">Guide for</span>
                @foreach($journeys as $j)
                    <button type="button" wire:click="$set('journey', '{{ $j['id'] }}')"
                            @class([
                                'rounded-full px-3.5 py-1.5 text-xs font-bold transition',
                                'bg-orange-500 text-white shadow-sm' => $j['id'] === $current['id'],
                                'border border-zinc-200 bg-white text-zinc-600 hover:border-orange-300 hover:text-orange-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300' => $j['id'] !== $current['id'],
                            ])
                            @if($j['id'] === $current['id']) aria-current="true" @endif>
                        {{ $j['label'] }}
                    </button>
                @endforeach
            </nav>
        @endif

        {{-- Category chips --}}
        <nav aria-label="Guide categories" class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
            @foreach($categories as $category => $items)
                <a href="#{{ $items->first()['id'] }}"
                   class="shrink-0 rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:border-orange-300 hover:text-orange-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                    {{ $category }}
                </a>
            @endforeach
            @if($isEmployeeJourney)
                <a href="#can-cannot" class="shrink-0 rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:border-orange-300 hover:text-orange-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">Can / Cannot</a>
                <a href="#faq" class="shrink-0 rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-xs font-semibold text-zinc-600 transition hover:border-orange-300 hover:text-orange-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">FAQ</a>
            @endif
        </nav>

        <div class="grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
            {{-- Table of contents --}}
            <aside class="hidden lg:block">
                <div class="sticky top-20 max-h-[calc(100vh-6rem)] overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="mb-3 text-[11px] font-bold uppercase tracking-widest text-zinc-400">Contents</p>
                    @foreach($categories as $category => $items)
                        <p class="mb-1 mt-3 text-[11px] font-bold uppercase tracking-wider text-zinc-500 first:mt-0">{{ $category }}</p>
                        <ul class="space-y-0.5">
                            @foreach($items as $item)
                                <li>
                                    <a href="#{{ $item['id'] }}"
                                       :class="active === '{{ $item['id'] }}' ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' : 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5'"
                                       class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] transition">
                                        <span class="size-1.5 shrink-0 rounded-full" :class="seen.includes('{{ $item['id'] }}') ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"></span>
                                        {{ $item['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                    @if($extraNav)
                    <p class="mb-1 mt-3 text-[11px] font-bold uppercase tracking-wider text-zinc-500">Reference</p>
                    <ul class="space-y-0.5">
                        @foreach($extraNav as $item)
                            <li>
                                <a href="#{{ $item['id'] }}"
                                   :class="active === '{{ $item['id'] }}' ? 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' : 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5'"
                                   class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] transition">
                                    <span class="size-1.5 shrink-0 rounded-full" :class="seen.includes('{{ $item['id'] }}') ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-600'"></span>
                                    {{ $item['title'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    @endif
                </div>
            </aside>

            <div class="min-w-0 space-y-6">
                {{-- No results --}}
                <div x-show="query.trim() !== '' && visibleCount === 0" x-cloak
                     class="rounded-2xl border border-dashed border-zinc-300 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon.magnifying-glass class="mx-auto size-8 text-zinc-300" />
                    <p class="mt-3 text-sm font-semibold text-zinc-700 dark:text-zinc-200">Nothing matches “<span x-text="query"></span>”.</p>
                    <p class="mt-1 text-xs text-zinc-500">Try a simpler word such as leave, payslip, attendance or profile.</p>
                </div>

                @if($isEmployeeJourney && empty($shots))
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                        Screenshots for this guide have not been generated yet. The written steps below are complete.
                    </div>
                @endif

                @foreach($sections as $section)
                    <section id="{{ $section['id'] }}" data-guide-section
                             data-search="{{ Str::lower($section['title'].' '.$section['category'].' '.$section['summary'].' '.$section['keywords'].' '.implode(' ', $section['steps'])) }}"
                             x-show="matches($el.dataset.search)"
                             class="scroll-mt-20 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-7">

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                                    <flux:icon :name="$section['icon']" class="size-5" />
                                </span>
                                <div>
                                    <p class="text-[11px] font-bold uppercase tracking-widest text-orange-600 dark:text-orange-400">{{ $section['category'] }}</p>
                                    <h2 class="mt-0.5 text-xl font-black tracking-tight text-zinc-900 dark:text-white">{{ $section['title'] }}</h2>
                                </div>
                            </div>
                            @if($section['links'])
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    @foreach($section['links'] as $link)
                                        <a href="{{ $link['url'] }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-orange-600">
                                            {{ $link['label'] }} <flux:icon.arrow-right class="size-3.5" />
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <p class="mt-4 text-[15px] leading-relaxed text-zinc-600 dark:text-zinc-300">{{ $section['summary'] }}</p>

                        {{-- Screenshots --}}
                        @php $sectionShots = collect($section['shots'])->filter(fn ($id) => isset($shots[$id])); @endphp
                        @foreach($sectionShots as $shotId)
                            @php $shot = $shots[$shotId]; @endphp
                            <figure class="mt-5 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
                                <button type="button" x-on:click="openLightbox('{{ $shotId }}')"
                                        class="group relative block w-full cursor-zoom-in" aria-label="Enlarge screenshot: {{ $shot['title'] }}">
                                    <img src="{{ $assetBase }}/{{ $shot['annotated'] }}?v={{ $version }}"
                                         alt="{{ $shot['title'] }}" loading="lazy"
                                         width="{{ $shot['width'] ?? 1440 }}" height="{{ $shot['height'] ?? 900 }}"
                                         class="h-auto w-full">
                                    <span class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-lg bg-black/70 px-2 py-1 text-[11px] font-semibold text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                                        <flux:icon.magnifying-glass-plus class="size-3.5" /> Enlarge
                                    </span>
                                </button>
                                <figcaption class="border-t border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                                    <p class="text-xs font-bold text-zinc-800 dark:text-zinc-100">{{ $shot['title'] }}</p>
                                    @if(! empty($shot['markers']))
                                        <ol class="mt-2 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
                                            @foreach($shot['markers'] as $i => $marker)
                                                <li class="flex gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                                                    <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-orange-500 text-[10px] font-black text-white">{{ $i + 1 }}</span>
                                                    <span><b class="text-zinc-800 dark:text-zinc-100">{{ $marker['label'] }}</b>@if(! empty($marker['note'])) · {{ $marker['note'] }}@endif</span>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif
                                </figcaption>
                            </figure>
                        @endforeach

                        <div class="mt-6 grid gap-6 xl:grid-cols-2">
                            {{-- Steps --}}
                            @if($section['steps'])
                                <div class="xl:col-span-2">
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Step by step</h3>
                                    <ol class="mt-3 space-y-2.5">
                                        @foreach($section['steps'] as $i => $step)
                                            <li class="flex gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                                                <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-900 text-[11px] font-bold text-white dark:bg-white dark:text-zinc-900">{{ $i + 1 }}</span>
                                                <span class="pt-0.5">{{ $step }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endif

                            {{-- Profile field tiers --}}
                            @if(! empty($section['profile_tiers']))
                                <div class="xl:col-span-2">
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Who can change which field</h3>
                                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                                        @foreach([
                                            Registry::TIER_EDITABLE => ['You can edit directly', 'Saves straight away.', 'bg-emerald-500'],
                                            Registry::TIER_APPROVAL => ['Request a change', 'HR approves it first. Your current value stays until then.', 'bg-amber-500'],
                                            Registry::TIER_LOCKED => ['Managed by HR', 'Contact HR to change these.', 'bg-zinc-400'],
                                        ] as $tier => [$heading, $explain, $dot])
                                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                                <p class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white"><span class="size-2 rounded-full {{ $dot }}"></span>{{ $heading }}</p>
                                                <p class="mt-1 text-xs text-zinc-500">{{ $explain }}</p>
                                                <ul class="mt-3 flex flex-wrap gap-1.5">
                                                    @foreach($profileTiers[$tier] as $label)
                                                        <li class="rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $label }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Glossary --}}
                            @if(! empty($section['glossary']))
                                <div class="xl:col-span-2">
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">What the terms mean</h3>
                                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                        @foreach($section['glossary'] as $entry)
                                            <div class="rounded-xl bg-zinc-50 px-4 py-3 dark:bg-white/5">
                                                <dt class="text-xs font-bold text-zinc-900 dark:text-white">{{ $entry['term'] }}</dt>
                                                <dd class="mt-0.5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $entry['meaning'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif

                            {{-- Statuses --}}
                            @if(! empty($section['statuses']))
                                <div class="xl:col-span-2">
                                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Statuses you may see</h3>
                                    @if(! empty($section['status_notes']))
                                        <dl class="mt-3 space-y-2">
                                            @foreach($section['status_notes'] as $status => $note)
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                                    <dt class="w-40 shrink-0"><span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide ring-1 {{ $statusTone($status) }}">{{ $status }}</span></dt>
                                                    <dd class="text-xs text-zinc-600 dark:text-zinc-400">{{ $note }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @else
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach($section['statuses'] as $status)
                                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide ring-1 {{ $statusTone($status) }}">{{ $status }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Pending requests table --}}
                            @if(! empty($section['request_table']))
                                <div class="xl:col-span-2 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                                    <table class="w-full min-w-[560px] text-left text-xs">
                                        <thead class="bg-zinc-50 text-[11px] uppercase tracking-wider text-zinc-500 dark:bg-white/5">
                                            <tr><th class="px-4 py-2.5">Request</th><th class="px-4 py-2.5">Where to track it</th><th class="px-4 py-2.5">Statuses</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                            @foreach($requestTypes as $row)
                                                <tr>
                                                    <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-white">{{ $row['type'] }}</td>
                                                    <td class="px-4 py-2.5"><a href="{{ $row['url'] }}" wire:navigate class="font-medium text-orange-600 hover:underline dark:text-orange-400">{{ $row['where'] }}</a></td>
                                                    <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">{{ $row['statuses'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            @if($section['can'])
                                <div class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 dark:border-emerald-500/10 dark:bg-emerald-500/5">
                                    <h3 class="flex items-center gap-2 text-sm font-bold text-emerald-800 dark:text-emerald-300"><flux:icon.check-circle class="size-4" /> What you can do</h3>
                                    <ul class="mt-2.5 space-y-1.5">
                                        @foreach($section['can'] as $item)
                                            <li class="flex gap-2 text-xs leading-relaxed text-zinc-700 dark:text-zinc-300"><span class="mt-1.5 size-1 shrink-0 rounded-full bg-emerald-500"></span>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($section['next'])
                                <div class="rounded-xl border border-sky-100 bg-sky-50/50 p-4 dark:border-sky-500/10 dark:bg-sky-500/5">
                                    <h3 class="flex items-center gap-2 text-sm font-bold text-sky-800 dark:text-sky-300"><flux:icon.arrow-path class="size-4" /> What happens next</h3>
                                    <ul class="mt-2.5 space-y-1.5">
                                        @foreach($section['next'] as $item)
                                            <li class="flex gap-2 text-xs leading-relaxed text-zinc-700 dark:text-zinc-300"><span class="mt-1.5 size-1 shrink-0 rounded-full bg-sky-500"></span>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>

                        @foreach($section['tips'] as $tip)
                            @php [$tipIcon, $tipClass, $tipLabel] = $tipStyle[$tip['type']] ?? $tipStyle['info']; @endphp
                            <div class="mt-4 flex gap-3 rounded-xl border p-3.5 text-xs leading-relaxed {{ $tipClass }}">
                                <flux:icon :name="$tipIcon" class="size-4 shrink-0" />
                                <p><b>{{ $tipLabel }}:</b> {{ $tip['text'] }}</p>
                            </div>
                        @endforeach
                    </section>
                @endforeach

                @if($isEmployeeJourney)
                {{-- Can / cannot --}}
                <section id="can-cannot" data-guide-section
                         data-search="can cannot permissions access allowed what can i do summary table admin settings roles payroll administration other employees records assets"
                         x-show="matches($el.dataset.search)"
                         class="scroll-mt-20 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-7">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-orange-600 dark:text-orange-400">Reference</p>
                    <h2 class="mt-0.5 text-xl font-black tracking-tight text-zinc-900 dark:text-white">What you can and cannot do</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Worked out from your own account's access right now. If something you need shows “No”, ask HR.</p>
                    <div class="mt-4 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full min-w-[520px] text-left text-sm">
                            <thead class="bg-zinc-50 text-[11px] uppercase tracking-wider text-zinc-500 dark:bg-white/5">
                                <tr><th class="px-4 py-2.5">Feature</th><th class="px-4 py-2.5">Access</th><th class="px-4 py-2.5">Details</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($capabilities as $row)
                                    <tr>
                                        <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-white">{{ $row['feature'] }}</td>
                                        <td class="px-4 py-2.5">
                                            @if($row['allowed'])
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.check class="size-3" /> Yes</span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] font-bold text-zinc-500 dark:bg-white/5 dark:text-zinc-400"><flux:icon.x-mark class="size-3" /> No</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-zinc-600 dark:text-zinc-400">{{ $row['can'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- FAQ --}}
                <section id="faq" data-guide-section
                         data-search="faq questions help {{ Str::lower(collect($faqs)->map(fn ($f) => $f['q'].' '.$f['a'])->implode(' ')) }}"
                         x-show="matches($el.dataset.search)"
                         class="scroll-mt-20 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-7">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-orange-600 dark:text-orange-400">Reference</p>
                    <h2 class="mt-0.5 text-xl font-black tracking-tight text-zinc-900 dark:text-white">Frequently asked questions</h2>
                    <div class="mt-4 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @foreach($faqs as $faq)
                            <details class="group px-4 py-3" data-search="{{ Str::lower($faq['q'].' '.$faq['a']) }}" x-show="matches($el.dataset.search)">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $faq['q'] }}
                                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 transition group-open:rotate-180" />
                                </summary>
                                <p class="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $faq['a'] }}</p>
                            </details>
                        @endforeach
                    </div>
                </section>
                @endif

                @if($capturedAt)
                    <p class="text-center text-[11px] text-zinc-400">Screenshots captured {{ \Illuminate\Support\Carbon::parse($capturedAt)->format('d M Y') }} from a demo employee account. Names and figures are fictional.</p>
                @endif
            </div>
        </div>

        {{-- Lightbox --}}
        <div x-show="lightbox.open" x-cloak x-transition.opacity
             class="fixed inset-0 z-[60] flex flex-col bg-black/90 p-3 sm:p-6"
             role="dialog" aria-modal="true" aria-label="Screenshot viewer">
            <div class="flex items-center justify-between gap-3 text-white">
                <p class="truncate text-sm font-semibold" x-text="current()?.title"></p>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" x-on:click="lightbox.original = ! lightbox.original"
                            class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold hover:bg-white/20"
                            x-text="lightbox.original ? 'Show numbered markers' : 'Show original'"></button>
                    <button type="button" x-on:click="closeLightbox()" class="rounded-lg bg-white/10 p-1.5 hover:bg-white/20" aria-label="Close">
                        <flux:icon.x-mark class="size-5" />
                    </button>
                </div>
            </div>
            <div class="relative mt-3 flex min-h-0 flex-1 items-center justify-center" x-on:click.self="closeLightbox()">
                <button type="button" x-show="lightbox.items.length > 1" x-on:click="step(-1)"
                        class="absolute left-0 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="Previous screenshot">
                    <flux:icon.chevron-left class="size-6" />
                </button>
                <img :src="current() ? (lightbox.original ? current().original : current().annotated) : ''" :alt="current()?.title"
                     class="max-h-full max-w-full rounded-lg object-contain shadow-2xl">
                <button type="button" x-show="lightbox.items.length > 1" x-on:click="step(1)"
                        class="absolute right-0 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20" aria-label="Next screenshot">
                    <flux:icon.chevron-right class="size-6" />
                </button>
            </div>
            <ol class="mx-auto mt-3 flex max-w-5xl flex-wrap justify-center gap-x-5 gap-y-1.5" x-show="! lightbox.original">
                <template x-for="(marker, i) in (current()?.markers ?? [])" :key="i">
                    <li class="flex items-center gap-1.5 text-xs text-zinc-200">
                        <span class="inline-flex size-5 items-center justify-center rounded-full bg-orange-500 text-[10px] font-black text-white" x-text="i + 1"></span>
                        <span x-text="marker.label"></span>
                    </li>
                </template>
            </ol>
        </div>
    </div>

</flux:main>
