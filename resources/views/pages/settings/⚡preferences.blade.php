<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Preferences')] class extends Component {

    public string $timezone = 'Asia/Kolkata';
    public string $date_format = 'd M Y';
    public string $time_format = 'H:i';

    public function mount(): void
    {
        $user = Auth::user();
        $this->timezone = $user->timezone ?? 'Asia/Kolkata';
        $this->date_format = $user->date_format ?? 'd M Y';
        $this->time_format = $user->time_format ?? 'H:i';
    }

    public function save(): void
    {
        $this->validate([
            'timezone'    => ['required', 'string', 'timezone'],
            'date_format' => ['required', 'string', 'in:d M Y,d/m/Y,m/d/Y,Y-m-d'],
            'time_format' => ['required', 'string', 'in:H:i,h:i A'],
        ]);

        Auth::user()->update([
            'timezone'    => $this->timezone,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
        ]);

        Flux::toast(variant: 'success', text: 'Preferences saved.');
    }
}; ?>

<x-pages::settings.layout :heading="__('Preferences')" :subheading="__('Choose your timezone, date format, and time display')">

    <form wire:submit="save" class="space-y-8 py-4">

        {{-- ── Timezone ── --}}
        <div>
            <div class="flex items-center gap-2 mb-3">
                <div class="flex size-7 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/20">
                    <svg class="size-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Timezone</p>
                    <p class="text-xs text-zinc-500">All deadlines, timestamps, and reports use this timezone.</p>
                </div>
            </div>
            <flux:select wire:model="timezone" label="Select your timezone">
                @php
                    $zones = [
                        'Asia/Kolkata'       => 'IST — India Standard Time (UTC+5:30)',
                        'UTC'                => 'UTC — Coordinated Universal Time',
                        'America/New_York'   => 'EST — Eastern Standard Time (UTC-5)',
                        'America/Chicago'    => 'CST — Central Standard Time (UTC-6)',
                        'America/Denver'     => 'MST — Mountain Standard Time (UTC-7)',
                        'America/Los_Angeles'=> 'PST — Pacific Standard Time (UTC-8)',
                        'Europe/London'      => 'GMT — Greenwich Mean Time (UTC+0)',
                        'Europe/Paris'       => 'CET — Central European Time (UTC+1)',
                        'Europe/Berlin'      => 'CET — Berlin (UTC+1)',
                        'Asia/Dubai'         => 'GST — Gulf Standard Time (UTC+4)',
                        'Asia/Singapore'     => 'SGT — Singapore Time (UTC+8)',
                        'Asia/Kuala_Lumpur'  => 'MYT — Malaysia Time (UTC+8)',
                        'Asia/Shanghai'      => 'CST — China Standard Time (UTC+8)',
                        'Asia/Tokyo'         => 'JST — Japan Standard Time (UTC+9)',
                        'Australia/Sydney'   => 'AEST — Australian Eastern Time (UTC+10)',
                    ];
                @endphp
                @foreach($zones as $tz => $label)
                    <option value="{{ $tz }}">{{ $label }}</option>
                @endforeach
            </flux:select>
            @error('timezone') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <flux:separator variant="subtle" />

        {{-- ── Date Format ── --}}
        <div>
            <div class="flex items-center gap-2 mb-4">
                <div class="flex size-7 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/20">
                    <svg class="size-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Date Format</p>
                    <p class="text-xs text-zinc-500">Choose how dates are displayed across the app.</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @php
                    $dateFormats = [
                        'd M Y'  => ['label' => 'DD MMM YYYY', 'example' => now()->format('d M Y')],
                        'd/m/Y'  => ['label' => 'DD/MM/YYYY', 'example' => now()->format('d/m/Y')],
                        'm/d/Y'  => ['label' => 'MM/DD/YYYY', 'example' => now()->format('m/d/Y')],
                        'Y-m-d'  => ['label' => 'YYYY-MM-DD', 'example' => now()->format('Y-m-d')],
                    ];
                @endphp
                @foreach($dateFormats as $fmt => $info)
                    <button type="button" wire:click="$set('date_format', '{{ $fmt }}')"
                        class="group relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3 text-center transition-all
                            {{ $date_format === $fmt
                                ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                        @if($date_format === $fmt)
                            <div class="absolute right-2 top-2 size-4 rounded-full bg-brand-500 flex items-center justify-center">
                                <svg class="size-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            </div>
                        @endif
                        <span class="text-xs font-bold {{ $date_format === $fmt ? 'text-brand-700 dark:text-brand-300' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $info['label'] }}</span>
                        <span class="text-[11px] {{ $date_format === $fmt ? 'text-brand-500' : 'text-zinc-400' }}">{{ $info['example'] }}</span>
                    </button>
                @endforeach
            </div>
            @error('date_format') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <flux:separator variant="subtle" />

        {{-- ── Time Format ── --}}
        <div>
            <div class="flex items-center gap-2 mb-4">
                <div class="flex size-7 items-center justify-center rounded-lg bg-brand-50 dark:bg-brand-900/20">
                    <svg class="size-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">Time Format</p>
                    <p class="text-xs text-zinc-500">Choose between 12-hour (AM/PM) and 24-hour clock display.</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 max-w-xs">
                @php
                    $timeFormats = [
                        'H:i'   => ['label' => '24-hour', 'example' => now()->format('H:i')],
                        'h:i A' => ['label' => '12-hour', 'example' => now()->format('h:i A')],
                    ];
                @endphp
                @foreach($timeFormats as $fmt => $info)
                    <button type="button" wire:click="$set('time_format', '{{ $fmt }}')"
                        class="group relative flex flex-col items-center gap-1.5 rounded-xl border-2 px-3 py-3 text-center transition-all
                            {{ $time_format === $fmt
                                ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                        @if($time_format === $fmt)
                            <div class="absolute right-2 top-2 size-4 rounded-full bg-brand-500 flex items-center justify-center">
                                <svg class="size-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            </div>
                        @endif
                        <span class="text-xs font-bold {{ $time_format === $fmt ? 'text-brand-700 dark:text-brand-300' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $info['label'] }}</span>
                        <span class="text-[11px] font-mono {{ $time_format === $fmt ? 'text-brand-500' : 'text-zinc-400' }}">e.g. {{ $info['example'] }}</span>
                    </button>
                @endforeach
            </div>
            @error('time_format') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div class="pt-2">
            <flux:button type="submit" variant="primary">Save Preferences</flux:button>
        </div>

    </form>

</x-pages::settings.layout>
