<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

/**
 * Thin wrapper around the OpenAI chat API.
 *
 * Every caller must check {@see self::enabled()} first so that AI features
 * degrade gracefully (hide entirely) when no OPENAI_API_KEY is configured.
 */
class AiAssistant
{
    /**
     * Whether AI features are configured and may be used.
     */
    public function enabled(): bool
    {
        return filled(config('openai.api_key'));
    }

    /**
     * The chat model to use (override with OPENAI_MODEL).
     */
    public function model(): string
    {
        return (string) (config('openai.model') ?: env('OPENAI_MODEL', 'gpt-4o-mini'));
    }

    /**
     * Send a system + user prompt and return the assistant's plain-text reply.
     */
    public function ask(string $systemPrompt, string $userPrompt): string
    {
        $response = OpenAI::chat()->create([
            'model' => $this->model(),
            'temperature' => 0.3,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        return trim($response->choices[0]->message->content ?? '');
    }
}
