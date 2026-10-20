<?php

namespace App\Services\AI;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AiContextService
{
    public function __construct(
        private readonly AiPromptService $prompts,
        private readonly AiMemoryService $memories,
    ) {}


    public function build(User $user, Conversation $conversation): array
    {
        if ((string) $conversation->user_id !== (string) $user->getKey()) {
            throw new AuthorizationException('You do not own this conversation.');
        }

        $messages = [];

        foreach ($this->prompts->effectivePersonas($user) as $persona) {
            
            $text = trim((string) $persona->system_prompt);

            if ($text !== '') {
                $messages[] = ['role' => 'system', 'content' => $text];
            }
        }

        if ($messages === []) {
            $fallback = trim((string) config('ai.prompt.default', ''));

            if ($fallback !== '') {
                $messages[] = ['role' => 'system', 'content' => $fallback];
            }
        }

        $history = $this->recentHistory($conversation);

        $memoryBlock = $this->memoryBlock($user, $this->cue($history));

        if ($memoryBlock !== null) {
            $messages[] = ['role' => 'system', 'content' => $memoryBlock];
        }

        foreach ($history as $item) {
            $messages[] = $item;
        }

        return $messages;
    }

  
    private function recentHistory(Conversation $conversation): array
    {
        $limit = max(1, (int) config('ai.context.history_limit', 20));

        $recent = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $recent
            ->map(fn (Message $message) => [
                'role' => $message->role instanceof MessageRole ? $message->role->value : (string) $message->role,
                'content' => $message->content,
            ])
            ->all();
    }

   
    private function cue(array $history): string
    {
        if ($history === []) {
            return '';
        }

        $last = $history[count($history) - 1];

        return ($last['role'] ?? null) === MessageRole::User->value
            ? (string) ($last['content'] ?? '')
            : '';
    }

    private function memoryBlock(User $user, string $cue): ?string
    {
        $facts = $this->memories
            ->relevantFor($user, $cue)
            ->map(fn ($memory) => trim((string) $memory->content))
            ->filter()
            ->values();

        if ($facts->isEmpty()) {
            return null;
        }

        return 'Relevant user context:'.PHP_EOL
            .implode(PHP_EOL, $facts->map(fn (string $fact) => '- '.mb_substr($fact, 0, 500))->all());
    }
}
