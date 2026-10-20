<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StorePersonaRequest;
use App\Http\Requests\Api\V1\UpdatePersonaRequest;
use App\Http\Resources\Api\V1\PersonaResource;
use App\Services\AI\AiPromptService;
use App\Support\ApiAbilities;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PersonaController extends ApiController
{
    public function __construct(private readonly AiPromptService $prompts) {}

    /**
     * Everything visible to the user: the system default plus their
     * own customs. Other users' personas are never exposed.
     */
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_READ)) {
            return $denied;
        }

        return ApiResponse::resource(
            PersonaResource::collection($this->prompts->listFor($request->user()))
        );
    }

    public function store(StorePersonaRequest $request): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_WRITE)) {
            return $denied;
        }

        try {
            $persona = $this->prompts->createFor($request->user(), $request->validated());
        } catch (InvalidArgumentException $e) {
            // Lengths are pre-validated by the Form Request, so a
            // service rejection here is the 3-custom limit (409) or a
            // blank-after-trim edge the request could not see (422).
            if ($this->prompts->customCountFor($request->user()) >= AiPromptService::MAX_CUSTOM_PERSONAS) {
                return ApiResponse::problem('persona_limit_reached', 409, 'Persona limit reached.', $e->getMessage());
            }

            return ApiResponse::problem('validation_failed', 422, 'Validation failed.', 'The given data was invalid.');
        }

        return ApiResponse::resource(new PersonaResource($persona), 201);
    }

    public function show(Request $request, string $persona): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_READ)) {
            return $denied;
        }

        return ApiResponse::resource(new PersonaResource($this->visibleFor($request, $persona)));
    }

    public function update(UpdatePersonaRequest $request, string $persona): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_WRITE)) {
            return $denied;
        }

        // Owned customs only: the system default and other users'
        // personas resolve as missing, never as forbidden.
        $target = $request->user()->aiPersonas()->findOrFail($persona);

        $updated = $this->prompts->updateFor($request->user(), $target, $request->validated());

        return ApiResponse::resource(new PersonaResource($updated));
    }

    public function destroy(Request $request, string $persona): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_WRITE)) {
            return $denied;
        }

        $target = $request->user()->aiPersonas()->findOrFail($persona);

        $this->prompts->deleteFor($request->user(), $target);

        return ApiResponse::action('Persona deleted.');
    }

    /**
     * Explicit activate / deactivate pair over the shared service
     * path. Customs toggle independently; the system default is
     * always active and can never reach the service through the
     * owned scoped lookup above.
     */
    public function activate(Request $request, string $persona): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_WRITE)) {
            return $denied;
        }

        $target = $request->user()->aiPersonas()->findOrFail($persona);

        $updated = $this->prompts->setActiveFor($request->user(), $target, true);

        return ApiResponse::resource(new PersonaResource($updated));
    }

    public function deactivate(Request $request, string $persona): JsonResponse
    {
        if ($denied = $this->denyWithoutAbility($request, ApiAbilities::PERSONAS_WRITE)) {
            return $denied;
        }

        $target = $request->user()->aiPersonas()->findOrFail($persona);

        $updated = $this->prompts->setActiveFor($request->user(), $target, false);

        return ApiResponse::resource(new PersonaResource($updated));
    }

    /**
     * Read-scoped lookup across exactly what listFor() exposes, so a
     * forged or foreign id 404s instead of leaking existence.
     */
    private function visibleFor(Request $request, string $id): mixed
    {
        $found = $this->prompts->listFor($request->user())->firstWhere('id', $id);

        return $found ?? abort(404);
    }
}
