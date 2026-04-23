<?php

use Livewire\Component;
use App\Models\Employee;
use App\Models\JobPosting;
use Livewire\Attributes\Url;

new class extends Component
{
    public string $query = '';

    public bool $show = false;

    // We can use Livewire attributes to listen to events, but for a global shortcut, Alpine is easiest.
    // We'll let Alpine open the modal. When opened, we can focus the input.

    public function getResultsProperty()
    {
        if (strlen($this->query) < 2) {
            return collect();
        }

        $term = '%' . $this->query . '%';

        // Search Employees
        $employees = Employee::whereHas('user', function ($q) use ($term) {
            $q->where('name', 'like', $term)
              ->orWhere('email', 'like', $term);
        })->with('user', 'jobTitle')->take(5)->get()->map(function ($emp) {
            return [
                'type' => 'Employee',
                'title' => $emp->user->name,
                'subtitle' => $emp->jobTitle?->title ?? $emp->user->email,
                'icon' => 'user',
                'url' => route('employees.index'), // In reality, might link to employee show page
            ];
        });

        return $employees;
    }
};
?>

<div x-data="{ open: false }" 
     @keydown.window.ctrl.k.prevent="$flux.modal('global-search').show(); open = true; setTimeout(() => $refs.searchInput.focus(), 100)"
     @keydown.window.meta.k.prevent="$flux.modal('global-search').show(); open = true; setTimeout(() => $refs.searchInput.focus(), 100)">
     
    <flux:modal name="global-search" variant="flyout" class="max-w-2xl px-0 pt-0 pb-2 top-20 shadow-2xl overflow-hidden rounded-xl">
        <div class="relative">
            <flux:icon.magnifying-glass class="absolute left-4 top-4 size-5 text-zinc-400" />
            <input 
                x-ref="searchInput"
                wire:model.live.debounce.300ms="query"
                type="text" 
                class="w-full bg-transparent border-0 border-b border-zinc-200 dark:border-zinc-800 focus:ring-0 focus:outline-none pl-12 pr-4 py-4 text-base text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                placeholder="Search employees, jobs... (try typing 2+ chars)"
                autocomplete="off"
            >
            @if(strlen($query) > 0)
                <button wire:click="$set('query', '')" class="absolute right-4 top-4 text-zinc-400 hover:text-zinc-600">
                    <flux:icon.x-mark class="size-5" />
                </button>
            @endif
        </div>

        <div class="px-2 pt-2 max-h-[50vh] overflow-y-auto">
            @if(strlen($query) < 2)
                <div class="px-4 py-8 text-center text-sm text-zinc-500">
                    Type at least 2 characters to search...
                </div>
            @elseif($this->results->isEmpty())
                <div class="px-4 py-8 text-center text-sm text-zinc-500">
                    No results found for "{{ $query }}".
                </div>
            @else
                <div class="space-y-1">
                    @foreach($this->results as $result)
                        <a href="{{ $result['url'] }}" wire:navigate @click="$flux.modal('global-search').close()" class="flex items-center gap-4 px-3 py-2.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800/50 group transition-colors">
                            <div class="flex items-center justify-center size-10 rounded-lg bg-zinc-100 dark:bg-zinc-800 group-hover:bg-white dark:group-hover:bg-zinc-700 text-zinc-500 dark:text-zinc-400 transition-colors">
                                <flux:icon :icon="$result['icon']" class="size-5" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $result['title'] }}</h4>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $result['subtitle'] }}</p>
                            </div>
                            <div class="shrink-0 text-xs text-zinc-400 bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded uppercase tracking-wider font-semibold">
                                {{ $result['type'] }}
                            </div>
                            <flux:icon.chevron-right class="size-4 text-zinc-300 opacity-0 group-hover:opacity-100 transition-opacity" />
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
        
        <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-900/50 border-t border-zinc-100 dark:border-zinc-800 text-xs text-zinc-500 flex justify-between items-center mt-2">
            <div>
                Search via <span class="font-mono bg-zinc-200 dark:bg-zinc-800 px-1 py-0.5 rounded">⌘</span> + <span class="font-mono bg-zinc-200 dark:bg-zinc-800 px-1 py-0.5 rounded">K</span>
            </div>
            <div>
                <span class="font-mono bg-zinc-200 dark:bg-zinc-800 px-1 py-0.5 rounded">ESC</span> to close
            </div>
        </div>
    </flux:modal>
</div>