<?php

namespace App\Livewire;

use App\Services\Ai\AiConversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Floating, RBAC-scoped HR assistant. Renders nothing unless the assistant is
 * switched on for the user's role. Answers come from the same
 * {@see AiConversation} as the full AI Assistant page.
 */
class AiCopilot extends Component
{
    /** @var array<int, array{role: string, content: string, links?: list<array{label: string, url: string, path: string}>}> */
    public array $messages = [];

    public string $input = '';

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

        $this->messages[] = ['role' => 'assistant', 'content' => $answer['content'], 'links' => $answer['links']];
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.ai-copilot', [
            'enabled' => $user ? app(AiConversation::class)->availableFor($user) : false,
        ]);
    }
}
