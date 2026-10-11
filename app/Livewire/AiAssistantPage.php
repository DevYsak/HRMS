<?php

namespace App\Livewire;

use App\Services\Ai\AiConversation;
use App\Services\AiAssistant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The full-page AI assistant. Answers come from {@see AiConversation}: Pulse
 * data first (exact, authorised), the optional external model second, help
 * links last. This component only holds the conversation.
 */
#[Title('AI Assistant')]
class AiAssistantPage extends Component
{
    /** @var array<int, array{role: string, content: string, links?: list<array{label: string, url: string, path: string}>, source?: string}> */
    public array $messages = [];

    public string $input = '';

    public ?string $activeQuestion = null;

    public function sendQuick(string $prompt): void
    {
        $this->activeQuestion = $prompt;
        $this->input = $prompt;
        $this->send();
    }

    public function send(): void
    {
        $conversation = app(AiConversation::class);
        $question = trim($this->input);
        $user = Auth::user();

        if (! $user || ! $conversation->availableFor($user) || $question === '') {
            return;
        }

        $this->validate(['input' => ['required', 'string', 'max:500']]);

        $this->messages[] = ['role' => 'user', 'content' => $question];
        $this->input = '';

        $answer = $conversation->respond($user, $question);

        $this->messages[] = [
            'role' => 'assistant',
            'content' => $answer['content'],
            'links' => $answer['links'],
            'source' => $answer['source'],
        ];
    }

    public function clearChat(): void
    {
        $this->messages = [];
        $this->activeQuestion = null;
    }

    public function render()
    {
        $user = Auth::user();
        $conversation = app(AiConversation::class);
        $available = $user && $conversation->availableFor($user);
        $assistant = app(AiAssistant::class);

        return view('livewire.ai-assistant-page', [
            'enabled' => $available,
            'modelConnected' => $available && $assistant->enabledForUser($user),
            'providerLabel' => $assistant->providerLabel(),
            'scopeLabel' => $user ? $conversation->scopeLabel($user) : '',
            'suggestions' => $available ? $conversation->suggestedQuestions($user) : [],
            'canConfigure' => (bool) $user?->hasPermission('manage_ai_settings'),
            'introVideoUrl' => config('services.ai_assistant.intro_video_url'),
        ])->layout('layouts.app');
    }
}
