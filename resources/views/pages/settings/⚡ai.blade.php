<?php

use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Services\AiAssistant;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('AI Assistant')] class extends Component {

    public string $provider = 'openai';
    public string $api_key = '';
    public string $model = '';
    public string $base_url = '';
    public bool $enabled = false;
    /** @var array<int, string> */
    public array $allowed_roles = [];

    public bool $keyIsSet = false;
    public ?string $testResult = null;
    public ?string $testError = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $s = AiSetting::current();
        $this->provider = $s->provider;
        $this->model = $s->model ?? '';
        $this->base_url = $s->base_url ?? '';
        $this->enabled = $s->enabled;
        $this->allowed_roles = $s->allowed_roles ?? [];
        $this->keyIsSet = filled($s->api_key);
    }

    /** When the provider changes, prefill the model/base URL placeholders. */
    public function updatedProvider(string $value): void
    {
        $preset = AiSetting::providers()[$value] ?? null;
        if ($preset) {
            $this->model = $preset['model'];
            $this->base_url = $preset['base_url'] ?? '';
        }
    }

    public function save(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $this->validate([
            'provider' => ['required', 'in:openai,gemini,custom'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:64'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'enabled' => ['boolean'],
            'allowed_roles' => ['array'],
            'allowed_roles.*' => ['string', 'in:'.collect(UserRole::cases())->map->value->implode(',')],
        ]);

        $s = AiSetting::current();
        $s->provider = $this->provider;
        $s->model = $this->model ?: null;
        $s->base_url = $this->base_url ?: null;
        $s->enabled = $this->enabled;
        $s->allowed_roles = array_values($this->allowed_roles);

        // Only overwrite the key when a new one was entered.
        if (filled($this->api_key)) {
            $s->api_key = $this->api_key;
        }

        $s->save();

        $this->keyIsSet = filled($s->api_key);
        $this->api_key = '';

        Flux::toast(variant: 'success', text: 'AI settings saved.');
    }

    /** Save first, then send a tiny prompt to verify the key/provider works. */
    public function test(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $this->save();
        $this->testResult = null;
        $this->testError = null;

        $ai = app(AiAssistant::class);

        if (! $ai->enabled()) {
            $this->testError = 'Enable AI and save an API key before testing.';

            return;
        }

        try {
            $reply = $ai->ask(
                'You are a connection tester. Reply with exactly: OK',
                'Say OK if you can read this.',
            );
            $this->testResult = 'Connection successful — model replied: "'.$reply.'"';
        } catch (\Throwable $e) {
            $this->testError = 'Connection failed: '.$e->getMessage();
        }
    }
}; ?>

<x-pages::settings.layout :heading="__('AI Assistant')" :subheading="__('Configure the AI provider, API key, and which roles can use AI features')">

    <form wire:submit="save" class="space-y-8 py-4">

        {{-- ── Master toggle ── --}}
        <div class="flex items-start justify-between gap-4 rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Enable AI Features</p>
                <p class="text-xs text-zinc-500 mt-0.5">Turns the HR Copilot, AI Assistant page, and attendance insights on or off for the whole company.</p>
            </div>
            <flux:switch wire:model="enabled" />
        </div>

        {{-- ── Provider ── --}}
        <div>
            <flux:label>AI Provider</flux:label>
            <flux:select wire:model.live="provider" class="mt-1.5">
                @foreach(\App\Models\AiSetting::providers() as $key => $preset)
                    <option value="{{ $key }}">{{ $preset['label'] }}</option>
                @endforeach
            </flux:select>
            @error('provider') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- ── API Key ── --}}
        <div>
            <flux:label>API Key</flux:label>
            <flux:input
                type="password"
                wire:model="api_key"
                viewable
                :placeholder="$keyIsSet ? '•••••••••••••• (saved — leave blank to keep)' : 'Paste your API key'" class="mt-1.5" />
            @error('api_key') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs text-zinc-500">
                Stored encrypted at rest. @if($keyIsSet) A key is currently saved. @endif
            </p>
        </div>

        {{-- ── Model + Base URL ── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <flux:label>Model</flux:label>
                <flux:input wire:model="model" placeholder="e.g. gpt-4o-mini" class="mt-1.5" />
                @error('model') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <flux:label>Base URL <span class="text-zinc-400 font-normal">(optional)</span></flux:label>
                <flux:input wire:model="base_url" placeholder="Leave blank for provider default" class="mt-1.5" />
                @error('base_url') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <flux:separator variant="subtle" />

        {{-- ── Role access ── --}}
        <div>
            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Which roles can use AI?</p>
            <p class="text-xs text-zinc-500 mt-0.5 mb-3">Each role only ever sees data it is already allowed to see; AI never widens access.</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach(\App\Enums\UserRole::cases() as $role)
                    <label class="flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 cursor-pointer hover:border-brand-400 transition">
                        <input type="checkbox" wire:model="allowed_roles" value="{{ $role->value }}" class="rounded border-zinc-300 text-brand-600 focus:ring-brand-500" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $role->label() }}</span>
                    </label>
                @endforeach
            </div>
            @error('allowed_roles') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        {{-- ── Actions ── --}}
        <div class="flex flex-wrap items-center gap-3 pt-2">
            <flux:button type="submit" variant="primary">Save Settings</flux:button>
            <flux:button type="button" wire:click="test" variant="ghost" icon="signal" wire:loading.attr="disabled" wire:target="test">
                <span wire:loading.remove wire:target="test">Save &amp; Test Connection</span>
                <span wire:loading wire:target="test">Testing…</span>
            </flux:button>
        </div>

        @if($testResult)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-800/40 dark:bg-emerald-900/15 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400">
                {{ $testResult }}
            </div>
        @endif
        @if($testError)
            <div class="rounded-lg border border-red-200 bg-red-50 dark:border-red-800/40 dark:bg-red-900/15 px-4 py-3 text-sm text-red-700 dark:text-red-400">
                {{ $testError }}
            </div>
        @endif

    </form>

    {{-- ════════════════ TUTORIAL ════════════════ --}}
    <flux:separator class="my-8" />

    <div class="space-y-5">
        <div class="flex items-center gap-2">
            <flux:icon.academic-cap class="size-5 text-brand-600" />
            <h3 class="text-base font-bold text-zinc-900 dark:text-white">How to get an API key</h3>
        </div>

        {{-- Gemini --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-sm font-bold text-zinc-900 dark:text-white">Google Gemini <span class="text-emerald-600 font-semibold">— has a free tier</span></p>
                <a href="https://aistudio.google.com/apikey" target="_blank" class="text-xs font-semibold text-brand-600 hover:underline">Open Google AI Studio ↗</a>
            </div>
            <ol class="list-decimal list-inside space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                <li>Go to <span class="font-mono text-xs">aistudio.google.com/apikey</span> and sign in with a Google account.</li>
                <li>Click <strong>Create API key</strong> and copy it.</li>
                <li>Above, set Provider = <strong>Google Gemini</strong>, paste the key, and Save.</li>
                <li>Model auto-fills to <span class="font-mono text-xs">gemini-flash-lite-latest</span>; base URL is set automatically.</li>
            </ol>
            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                ⚠️ The Gemini <strong>free tier</strong> may use your prompts to improve Google's models. For real employee data, use a <strong>paid</strong> Gemini key or restrict roles while testing.
            </p>
        </div>

        {{-- OpenAI --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4">
            <div class="flex items-center justify-between gap-2 mb-2">
                <p class="text-sm font-bold text-zinc-900 dark:text-white">OpenAI (ChatGPT) <span class="text-zinc-400 font-normal">— paid, pay-as-you-go</span></p>
                <a href="https://platform.openai.com/api-keys" target="_blank" class="text-xs font-semibold text-brand-600 hover:underline">Open OpenAI Platform ↗</a>
            </div>
            <ol class="list-decimal list-inside space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                <li>Go to <span class="font-mono text-xs">platform.openai.com/api-keys</span> and sign in.</li>
                <li>Add a payment method under <strong>Billing</strong> (required, or requests return 429).</li>
                <li>Click <strong>Create new secret key</strong> and copy it (shown once).</li>
                <li>Above, set Provider = <strong>OpenAI</strong>, paste the key, and Save.</li>
            </ol>
        </div>

        <p class="text-xs text-zinc-500">
            Tip: a consumer <strong>ChatGPT Plus</strong> or <strong>Gemini Advanced</strong> subscription is <em>not</em> an API key — you need a key from the developer pages above.
        </p>
    </div>

</x-pages::settings.layout>
