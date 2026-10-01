<?php

namespace App\Services\Message;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class MessageService
{
    /**
     * Retrieve a conversation's messages in chronological order after
     * verifying the user is authorised to access the conversation.
     */
    public function listFor(User $user, Conversation $conversation): Collection
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Persist a user message on the given conversation after verifying the
     * user is authorised to access it. The conversation and role are always
     * determined by the server, never taken from the client.
     */
    public function createUserMessage(User $user, Conversation $conversation, string $content): Message
    {
        Gate::forUser($user)->authorize('view', $conversation);

        return $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
        ]);
    }

    /**
     * Update a user-authored message after verifying ownership.
     *
     * Authorization mirrors creation: the acting user must own the parent
     * conversation (the existing ConversationPolicy `view` rule), and only
     * user-role messages are editable — assistant messages are never exposed
     * for editing. Shape validation stays with the caller.
     */
    public function updateUserMessage(User $user, Message $message, string $content): Message
    {
        if ($message->role !== MessageRole::User) {
            throw new AuthorizationException('Only user messages can be edited.');
        }

        Gate::forUser($user)->authorize('view', $message->conversation);

        $message->update(['content' => $content]);

        return $message->refresh();
    }
}
