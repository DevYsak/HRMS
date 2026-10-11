<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\ChatProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;

/**
 * Entry point to the optional external language model.
 *
 * Configuration is database-driven via {@see AiSetting} (managed in Settings →
 * AI Assistant, key encrypted at rest), falling back to environment values
 * (OPENAI_API_KEY / ANTHROPIC_API_KEY) — never a key in code. The provider is
 * chosen by the setting: OpenAI, Gemini and custom endpoints speak the
 * OpenAI-compatible API; Anthropic has its own {@see AnthropicProvider}.
 *
 * Every caller must check {@see self::enabled()} (or {@see self::enabledForUser()})
 * first so AI features degrade gracefully when no key is configured.
 */
class AiAssistant
{
    /**
     * The effective API key — database setting first, then the provider's env fallback.
     */
    public function apiKey(): ?string
    {
        $setting = AiSetting::current();

        if (filled($setting->api_key)) {
            return $setting->api_key;
        }

        return $setting->protocol() === 'anthropic'
            ? config('services.anthropic.key')
            : config('openai.api_key');
    }

    /**
     * Whether AI features are configured and switched on.
     */
    public function enabled(): bool
    {
        return AiSetting::current()->enabled && filled($this->apiKey());
    }

    /**
     * Whether the given user's role is permitted to use AI features.
     */
    public function enabledForUser(User $user): bool
    {
        return $this->enabled() && AiSetting::current()->roleAllowed($user->role->value);
    }

    /**
     * The chat model to use.
     */
    public function model(): string
    {
        return AiSetting::current()->resolvedModel();
    }

    /**
     * The provider label shown to users (never the key).
     */
    public function providerLabel(): string
    {
        $setting = AiSetting::current();

        return AiSetting::providers()[$setting->provider]['label'] ?? ucfirst((string) $setting->provider);
    }

    /**
     * Send a system + user prompt and return the assistant's plain-text reply.
     */
    public function ask(string $systemPrompt, string $userPrompt): string
    {
        return $this->provider()->chat($systemPrompt, $userPrompt);
    }

    /**
     * Resolve the configured provider from the live setting, so a provider or
     * key change in Settings takes effect on the next request.
     */
    public function provider(): ChatProvider
    {
        $setting = AiSetting::current();

        if ($setting->protocol() === 'anthropic') {
            return new AnthropicProvider((string) $this->apiKey(), $this->model(), $setting->resolvedBaseUrl());
        }

        // With no DB key the OpenAI provider uses the env-configured client.
        return new OpenAiCompatibleProvider($setting->api_key, $this->model(), $setting->resolvedBaseUrl());
    }
}
