<?php

namespace App\Services\AI;

use App\Enums\MessageRole;
use App\Models\AiMemory;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AiMemoryService
{
    /**
     * High-signal self-fact patterns captured verbatim from user text.
     * Each entry maps a match to a stable stored phrasing plus the prefix
     * identifying replaceable siblings (e.g. a renamed user replaces the
     * previous name instead of accumulating contradictions).
     *
     * Deliberately heuristic v1: deterministic, offline, and testable.
     * Smarter (model-based) extraction can replace `extractFact()` later
     * without touching callers, which only use the public remember,
     * capture, and relevant methods.
     */
    private const NAME_EN = '/\bmy name is\s+([A-Za-z\x{0600}-\x{06FF}][A-Za-z0-9\x{0600}-\x{06FF}\'’\-\. ]{1,40})/iu';

    private const HOME_EN = '/\bi live in\s+([A-Za-z\x{0600}-\x{06FF}][A-Za-z0-9\x{0600}-\x{06FF}\'’\-\. ]{1,60})/iu';

    private const NAME_AR = '/\bاسمي\s+([\x{0600}-\x{06FF}]{2,40})/u';

    private const HOME_AR = '/\bأعيش في\s+([\x{0600}-\x{06FF}A-Za-z0-9\'’\-\. ]{2,60})/u';

    /**
     * Explicitly store one user-scoped fact. Idempotent on normalized
     * content: re-storing the same fact returns the existing row instead
     * of duplicating it.
     *
     * @throws InvalidArgumentException
     */
    public function remember(User $user, string $content): AiMemory
    {
        $content = $this->normalize($content);

        if ($content === '' || mb_strlen($content) > 1000) {
            throw new InvalidArgumentException('The memory content must be 1–1000 characters.');
        }

        $existing = $user->aiMemories()
            ->where('is_active', true)
            ->whereRaw('LOWER(content) = ?', [mb_strtolower($content)])
            ->first();

        if ($existing instanceof AiMemory) {
            return $existing;
        }

        $this->enforceCap($user);

        return $user->aiMemories()->create([
            'content' => $content,
            'is_active' => true,
        ]);
    }

    /**
     * Capture a durable self-fact from one user message, if it carries
     * one. Returns the stored memory, or null when nothing worth keeping
     * was found. Never throws for unparseable input.
     */
    public function captureFromMessage(User $user, string $message): ?AiMemory
    {
        $fact = $this->extractFact($message);

        if ($fact === null) {
            return null;
        }

        [$content, $replacePrefix] = $fact;

        if ($replacePrefix !== null) {
            $user->aiMemories()
                ->where('is_active', true)
                ->where('content', 'like', $replacePrefix.'%')
                ->delete();
        }

        return $this->remember($user, $content);
    }

    /**
     * Capture from the latest user-authored message of a conversation.
     * Returns null when the conversation has no user message yet, when it
     * belongs to someone else, or when nothing durable was said.
     */
    public function captureFromConversation(User $user, Conversation $conversation): ?AiMemory
    {
        if ((string) $conversation->user_id !== (string) $user->getKey()) {
            return null;
        }

        $latest = $conversation->messages()
            ->where('role', MessageRole::User)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || trim((string) $latest->content) === '') {
            return null;
        }

        return $this->captureFromMessage($user, (string) $latest->content);
    }

    /**
     * Rank the user's active memories against the current cue (normally
     * the latest user message) by plain token overlap — highest overlap
     * first, oldest first on ties — and take the top entries. Bounded by
     * configuration, so the full store is never sent wholesale.
     *
     * @return Collection<int, AiMemory>
     */
    public function relevantFor(User $user, string $cue, ?int $limit = null): Collection
    {
        $limit ??= max(0, (int) config('ai.context.memory_limit', 5));

        if ($limit === 0) {
            return new Collection;
        }

        $cueTokens = $this->tokens($cue);

        $ranked = $user->aiMemories()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AiMemory $memory) => [
                'memory' => $memory,
                'score' => count(array_intersect($cueTokens, $this->tokens((string) $memory->content))),
            ])
            ->sort(fn (array $a, array $b) => $b['score'] <=> $a['score'])
            ->values()
            ->take(max(1, $limit));

        return $ranked->map(fn (array $row) => $row['memory'])->values();
    }

    /**
     * @return array{string, ?string}|null [stored content, replace prefix]
     */
    private function extractFact(string $message): ?array
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        if ($message === '') {
            return null;
        }

        $patterns = [
            [self::NAME_EN, 'User\'s name is %s.', 'User\'s name is'],
            [self::NAME_AR, 'User\'s name is %s.', 'User\'s name is'],
            [self::HOME_EN, 'User lives in %s.', 'User lives in'],
            [self::HOME_AR, 'User lives in %s.', 'User lives in'],
        ];

        foreach ($patterns as [$pattern, $template, $prefix]) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }

            $value = trim((string) preg_replace('/[\s.!,?؟،؛:]+$/u', '', $matches[1] ?? ''));

            if (mb_strlen($value) < 2 || mb_strlen($value) > 60) {
                continue;
            }

            return [sprintf($template, $value), $prefix];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn ($word) => mb_strlen($word) >= 2
        )));
    }

    private function normalize(string $content): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $content));
    }

    /**
     * Memories are a bounded cache, not an archive: beyond the cap the
     * oldest rows make room. The cap is generous; retrieval is separately
     * bounded per request by `ai.context.memory_limit`.
     */
    private function enforceCap(User $user): void
    {
        $max = max(1, (int) config('ai.memory.max_per_user', 50));

        $excess = $user->aiMemories()->count() - $max + 1;

        if ($excess <= 0) {
            return;
        }

        $user->aiMemories()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($excess)
            ->delete();
    }
}
