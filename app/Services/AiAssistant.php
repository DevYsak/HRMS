<?php

namespace App\Services;

use App\Models\AiSetting;
use App\Models\User;
use OpenAI\Contracts\ClientContract;

/**
 * Thin wrapper around an OpenAI-compatible chat API.
 *
 * Configuration is database-driven via {@see AiSetting} (managed by a super
 * admin in Settings → AI Assistant), falling back to the OPENAI_* env values
 * for backwards compatibility. Every caller must check {@see self::enabled()}
 * (or {@see self::enabledForUser()}) first so AI features degrade gracefully
 * (hide entirely) when no key is configured.
 */
class AiAssistant
{
    /**
     * The effective API key — database setting first, then env fallback.
     */
    public function apiKey(): ?string
    {
        $key = AiSetting::current()->api_key;

        return filled($key) ? $key : config('openai.api_key');
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
     * Send a system + user prompt and return the assistant's plain-text reply.
     */
    public function ask(string $systemPrompt, string $userPrompt): string
    {
        $response = $this->client()->chat()->create([
            'model' => $this->model(),
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }

    /**
     * Resolve an OpenAI-compatible client from the live configuration.
     *
     * When a key is set in the database (admin-configured), a client is built
     * per call so provider/key changes take effect immediately. When no DB key
     * is set, the package's container-bound client is used so it honours the
     * env config and remains fakeable via OpenAI::fake() in tests.
     */
    protected function client(): ClientContract
    {
        $setting = AiSetting::current();

        if (blank($setting->api_key)) {
            return app('openai');
        }

        $factory = \OpenAI::factory()->withApiKey((string) $setting->api_key);

        $baseUrl = $setting->resolvedBaseUrl() ?: config('openai.base_uri');
        if (filled($baseUrl)) {
            $factory = $factory->withBaseUri($baseUrl);
        }

        if (filled(config('openai.organization'))) {
            $factory = $factory->withOrganization(config('openai.organization'));
        }

        return $factory->make();
    }
}
