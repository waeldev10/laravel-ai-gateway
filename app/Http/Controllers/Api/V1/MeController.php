<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::resource(new UserResource($request->user()));
    }
}
