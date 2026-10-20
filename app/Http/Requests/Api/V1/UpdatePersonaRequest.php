<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Partial update: absent attributes keep their stored values
     * (resolved by the service). `is_default` and `user_id` are never
     * accepted, so the system default cannot be claimed or altered.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'system_prompt' => ['sometimes', 'string', 'max:8000'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:-1000', 'max:1000'],
        ];
    }
}
