<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AiPersona;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable public representation of a persona.
 *
 * Covers both the read-only system default (`is_system`) and the
 * user's own custom personas. Only user-editable attributes are
 * ever accepted on input; `is_default`/`user_id` stay server-owned.
 *
 * @mixin AiPersona
 */
class PersonaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'system_prompt' => $this->system_prompt,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'is_system' => $this->user_id === null,
            'priority' => (int) $this->priority,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
