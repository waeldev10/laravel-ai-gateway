<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Auth\RegisterRequest as WebRegisterRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Single rule source, same pattern the Web Livewire form uses:
     * the API accepts exactly what the Web registration accepts,
     * including `password_confirmation` via the `confirmed` rule.
     *
     * @return array<string, array<int, string|Rules\Password>>
     */
    public function rules(): array
    {
        return (new WebRegisterRequest)->rules();
    }
}
