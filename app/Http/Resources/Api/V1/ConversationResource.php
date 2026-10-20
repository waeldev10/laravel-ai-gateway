<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable public representation of a conversation.
 *
 * Internal columns outside this list are never exposed.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'title' => $this->title,
            'is_pinned' => $this->pinned_at !== null,
            'pinned_at' => $this->pinned_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'messages_count' => $this->whenCounted('messages'),
        ];
    }
}
