<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Message\MessageService;
use Illuminate\Foundation\Http\FormRequest;

class ListMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'before' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.MessageService::HISTORY_PAGE_SIZE],
        ];
    }
}
