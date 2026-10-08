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

    /**
     * Build the final normalized provider input for one AI attempt.
     *
     * Layered, cheapest-first, always bounded:
     *
     *   effective personas (system default + active customs, one system
     *     message each, deterministic order via AiPromptService)
     *     + relevant long-term memories (ranked AiMemory rows, own user
     *       only, bounded by configuration)
     *     + recent conversation history (bounded, chronological)
     *
     * Providers receive plain `{role, content}` messages only: no users,
     * no conversations, no personas, no memory models cross the AI
     * boundary. Memory logic never lives in providers or protocols.
     *
     * With no persona and no memories the output is exactly the recent
     * history — and with an empty history, an empty array.
     *
     * @return array<int, array{role: string, content: string}>
     *
     * @throws AuthorizationException
     */
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

    /**
     * Recent messages only, oldest first, capped by configuration — never
     * the entire conversation history.
     *
     * @return array<int, array{role: string, content: string}>
     */
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

    /**
     * The relevance cue is the current user message: the latest history
     * entry when it is user-authored (the caller persists it before
     * building), otherwise an empty cue that falls back to oldest-first.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
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

    /**
     * One compact system block of the user's own top-ranked memories —
     * strictly per-user isolated, bounded by configuration.
     */
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
