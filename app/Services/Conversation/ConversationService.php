<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ConversationService
{
    /**
     * Get the authenticated user's conversations, newest first.
     *
     * When a search term is given, only conversations whose title matches
     * the term are returned.
     */
    public function listFor(User $user, ?string $search = null): Collection
    {
        return $user->conversations()
            ->when(filled($search), function ($query) use ($search) {
                $query->where('title', 'like', '%'.addcslashes($search, '\\%_').'%');
            })
            ->latest()
            ->get();
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
        $ids = array_values(array_unique(array_map('intval', $conversationIds)));

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
