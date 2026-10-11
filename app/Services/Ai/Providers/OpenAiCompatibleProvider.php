<?php

namespace App\Services\Ai\Providers;

use OpenAI;
use OpenAI\Contracts\ClientContract;

/**
 * OpenAI and every OpenAI-compatible endpoint (Google Gemini's compatibility
 * API, self-hosted gateways). With no key of its own it uses the package's
 * container client, which reads OPENAI_* from the environment and stays
 * fakeable with OpenAI::fake() in tests.
 */
class OpenAiCompatibleProvider implements ChatProvider
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private ?string $baseUrl = null,
    ) {}

    public function chat(string $systemPrompt, string $userPrompt): string
    {
        $response = $this->client()->chat()->create([
            'model' => $this->model,
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }

    private function client(): ClientContract
    {
        if (blank($this->apiKey)) {
            return app('openai');
        }

        $factory = OpenAI::factory()->withApiKey((string) $this->apiKey);

        $baseUrl = $this->baseUrl ?: config('openai.base_uri');
        if (filled($baseUrl)) {
            $factory = $factory->withBaseUri($baseUrl);
        }

        if (filled(config('openai.organization'))) {
            $factory = $factory->withOrganization(config('openai.organization'));
        }

        return $factory->make();
    }
}
