<?php

namespace App\Services\Ai\Providers;

/**
 * One external language model behind the AI assistant. A provider only turns
 * a system + user prompt into text: it never decides what data is sent (that
 * is AiContextBuilder's job) and never produces the counts Pulse answers
 * deterministically (AiIntentRouter).
 */
interface ChatProvider
{
    public function chat(string $systemPrompt, string $userPrompt): string;
}
