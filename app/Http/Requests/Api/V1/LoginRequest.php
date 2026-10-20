<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Auth\LoginRequest as WebLoginRequest;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Single rule source, same pattern the Web Livewire form uses:
     * the API accepts exactly what the Web login accepts.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return (new WebLoginRequest)->rules();
    }
}
