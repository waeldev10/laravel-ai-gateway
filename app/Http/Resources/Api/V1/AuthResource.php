<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public representation of an authenticated API session: the user
 * through UserResource plus the one-time bearer secret.
 *
 * The secret only exists in the issuance response — it is never
 * stored and therefore never appears anywhere else.
 */
class AuthResource extends JsonResource
{
    /**
     * @param  array{user: User, token: string}  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'token' => $this->resource['token'],
            'token_type' => 'Bearer',
        ];
    }
}
