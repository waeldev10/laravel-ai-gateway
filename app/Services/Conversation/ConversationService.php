<?php

namespace App\Services\Conversation;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationService
{
    /**
     * Get the authenticated user's conversations, newest first.
     *
     * When a search term is given, only conversations whose title matches
     * the term are returned. Pinned conversations come first.
     */
    public function listFor(User $user, ?string $search = null): Collection
    {
        return $this->baseQueryFor($user, $search)->get();
    }

    /**
     * Get a backend-paginated slice of the authenticated user's conversations.
     *
     * Used by the dedicated search page to load results in batches.
     */
    public function searchPaginated(User $user, ?string $search, int $perPage = 5): LengthAwarePaginator
    {
        return $this->baseQueryFor($user, $search)->paginate(max(1, min($perPage, 100)));
    }

    /**
     * Shared retrieval behind listFor() and searchPaginated(): ownership scope,
     * optional title filter, pinned-first then newest ordering.
     */
    private function baseQueryFor(User $user, ?string $search): HasMany
    {
        return $user->conversations()
            ->when(filled($search), function ($query) use ($search) {
                $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%');
            })
            ->orderByDesc('pinned_at')
            ->latest()
            ->orderByDesc('id');
    }

    /**
     * Split an already-ordered conversation list into pinned/recent groups.
     *
     * Single authoritative grouping for the shared Sidebar + Search
     * navigation: pinned conversations come first, a pinned conversation
     * never also appears in recent, and the incoming order is preserved
     * inside each group (so recent keeps the newest-first ordering above).
     *
     * @return array{pinned: Collection<int, Conversation>, recent: Collection<int, Conversation>}
     */
    public function splitPinned(iterable $conversations): array
    {
        $items = $conversations instanceof Collection ? $conversations : collect($conversations);

        [$pinned, $recent] = $items->partition(fn (Conversation $conversation) => $conversation->pinned_at !== null);

        return ['pinned' => $pinned->values(), 'recent' => $recent->values()];
    }

    /**
     * Retrieve one of the user's conversations by id.
     *
     * Scoped to the user, so other users' conversations resolve as missing.
     */
    public function findFor(User $user, string $id): Conversation
    {
        return $user->conversations()->findOrFail($id);
    }

    /**
     * Rename a conversation after verifying the user owns it.
     */
    public function renameFor(User $user, Conversation $conversation, string $title): Conversation
    {
        Gate::forUser($user)->authorize('update', $conversation);

        $conversation->update(['title' => $title]);

        return $conversation->refresh();
    }

    /**
     * Pin or unpin a conversation after verifying the user owns it.
     */
    public function setPinnedFor(User $user, Conversation $conversation, bool $pinned): Conversation
    {
        Gate::forUser($user)->authorize('update', $conversation);

        $conversation->update(['pinned_at' => $pinned ? now() : null]);

        return $conversation->refresh();
    }

    /**
     * Create a conversation belonging to the given user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createFor(User $user, array $attributes): Conversation
    {
        return $user->conversations()->create($attributes);
    }

    /**
     * Start a new conversation from the user's first message.
     *
     * One atomic business operation: create the conversation, persist the
     * first user message with the exact submitted content, and assign a short
     * title derived from that message. Either everything persists or nothing
     * does. Ownership/authorization mirrors MessageService::createUserMessage
     * (the conversation always belongs to the acting user).
     */
    public function startFor(User $user, string $content): Conversation
    {
        return DB::transaction(function () use ($user, $content) {
            $conversation = $user->conversations()->create([
                'title' => $this->titleFor($content),
            ]);

            Gate::forUser($user)->authorize('view', $conversation);

            $conversation->messages()->create([
                'role' => MessageRole::User,
                'content' => $content,
            ]);

            return $conversation->refresh();
        });
    }

    /**
     * Derive a short sidebar/search-suitable title from the first message.
     *
     * Rule-based interim mechanism (no AI provider is integrated yet):
     * normalize whitespace, drop trailing sentence punctuation, and keep the
     * leading words up to ~60 characters at a word boundary. Never returns
     * the full message and never an empty string.
     */
    public function titleFor(string $content): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $content));

        if ($text === '') {
            return 'محادثة جديدة';
        }

        $text = rtrim($text, " \t\n\r\0\x0B?.!؟،؛:");

        $words = preg_split('/\s+/u', $text) ?: [];
        $words = array_values(array_filter($words, fn ($w) => $w !== ''));

        if ($words === []) {
            return 'محادثة جديدة';
        }

        $title = '';

        foreach (array_slice($words, 0, 8) as $word) {
            $candidate = $title === '' ? $word : $title.' '.$word;

            if (mb_strlen($candidate) > 60) {
                break;
            }

            $title = $candidate;
        }

        if ($title === '') {
            $title = mb_substr($words[0], 0, 60);
        }

        if (count($words) > 8 || mb_strlen($text) > mb_strlen($title)) {
            $title = rtrim($title).'…';
        }

        return $title;
    }

    /**
     * Retrieve a conversation after verifying the user owns it.
     */
    public function showFor(User $user, Conversation $conversation): Conversation
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $conversation;
    }

    /**
     * Delete a conversation after verifying the user owns it.
     */
    public function deleteFor(User $user, Conversation $conversation): void
    {
        Gate::forUser($user)->authorize('delete', $conversation);

        $conversation->delete();
    }

    /**
     * Bulk delete conversations after authorising every selected conversation.
     *
     * The ids are de-duplicated, every selected conversation is checked to
     * actually exist, and each one is individually authorised through the
     * conversation delete policy. The operation is all-or-nothing: if any id
     * is missing or any conversation is not owned by the user, nothing is
     * deleted and an authorization exception is thrown.
     *
     * @param  array<int, int>  $conversationIds
     *
     * @throws AuthorizationException
     */
    public function deleteMany(User $user, array $conversationIds): void
    {
        $ids = array_values(array_unique(array_map('strval', $conversationIds)));

        if ($ids === []) {
            return;
        }

        $conversations = Conversation::whereIn('id', $ids)->get();

        if ($conversations->count() !== count($ids)) {
            throw new AuthorizationException('One or more of the selected conversations do not exist.');
        }

        $conversations->each(
            fn (Conversation $conversation) => Gate::forUser($user)->authorize('delete', $conversation)
        );

        $user->conversations()->whereIn('id', $conversations->modelKeys())->delete();
    }
}
