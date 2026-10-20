<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Exactly one of `title` / `content`: a titled shell, or a first
     * user message that also derives the title (via startFor).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['prohibits:content', 'required_without:content', 'string', 'max:255'],
            'content' => ['prohibits:title', 'required_without:title', 'string', 'min:1', 'max:10000'],
        ];
    }
}
