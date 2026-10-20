<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable public representation of a chat message.
 *
 * No server-rendered HTML, no internal columns.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'role' => $this->role instanceof MessageRole ? $this->role->value : (string) $this->role,
            'content' => $this->content,
            'status' => $this->status instanceof MessageStatus ? $this->status->value : ($this->status !== null ? (string) $this->status : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
