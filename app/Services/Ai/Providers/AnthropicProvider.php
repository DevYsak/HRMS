<?php

namespace App\Services\Ai\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic's Messages API over Laravel's HTTP client (no extra SDK). The key
 * comes from the encrypted AI setting or ANTHROPIC_API_KEY — never code.
 */
class AnthropicProvider implements ChatProvider
{
    public const DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2023-06-01';

    public function __construct(
        private string $apiKey,
        private string $model,
        private ?string $baseUrl = null,
    ) {}

    public function chat(string $systemPrompt, string $userPrompt): string
    {
        $response = Http::baseUrl(rtrim($this->baseUrl ?: self::DEFAULT_BASE_URL, '/'))
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
            ->acceptJson()
            ->timeout(30)
            ->post('/messages', [
                'model' => $this->model,
                'max_tokens' => 800,
                'temperature' => 0.3,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic request failed with HTTP '.$response->status().'.');
        }

        return trim(collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n"));
    }
}
