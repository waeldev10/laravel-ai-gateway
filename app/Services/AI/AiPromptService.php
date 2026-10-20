<?php

namespace App\Services\AI;

use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AiPromptService
{
   
    public function effectivePersonas(?User $user): Collection
    {
        $personas = new Collection;

        $default = $this->systemDefault();

        if ($default instanceof AiPersona) {
            $personas->push($default);
        }

        if ($user !== null) {
            foreach ($this->activeCustomPersonas($user) as $persona) {
                $personas->push($persona);
            }
        }

        return $personas;
    }

  
    public function activeCustomPersonas(User $user): Collection
    {
        return $user->aiPersonas()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function customCountFor(User $user): int
    {
        return $user->aiPersonas()->count();
    }

    /**
     * The system-level default: the flagged, ownerless, active record —
     * falling back to the highest-priority active system persona when no
     * row is flagged (e.g. seeded before flagging existed).
     */
    public function systemDefault(): ?AiPersona
    {
        $flagged = AiPersona::query()
            ->whereNull('user_id')
            ->where('is_default', true)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($flagged instanceof AiPersona) {
            return $flagged;
        }

        return AiPersona::query()
            ->whereNull('user_id')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Everything visible to the user: system defaults plus their own
     * personas. Other users' personas are never exposed.
     *
     * @return Collection<int, AiPersona>
     */
    public function listFor(User $user): Collection
    {
        return AiPersona::query()
            ->where(fn ($query) => $query
                ->whereNull('user_id')
                ->orWhere('user_id', $user->getKey()))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();
    }

   
    public function createFor(User $user, array $attributes): AiPersona
    {
        if ($this->customCountFor($user) >= self::MAX_CUSTOM_PERSONAS) {
            throw new InvalidArgumentException('لا يمكنك إنشاء أكثر من 3 برومبتات مخصصة.');
        }

        [$name, $prompt, $active, $priority] = $this->validated($attributes);

        return $user->aiPersonas()->create([
            'name' => $name,
            'system_prompt' => $prompt,
            'is_active' => $active,
            'priority' => $priority,
        ]);
    }

   
    public function updateFor(User $user, AiPersona $persona, array $attributes): AiPersona
    {
        $this->authorizeOwnership($user, $persona);

        [$name, $prompt, $active, $priority] = $this->validated($attributes, $persona);

        $persona->update([
            'name' => $name,
            'system_prompt' => $prompt,
            'is_active' => $active,
            'priority' => $priority,
        ]);

        return $persona->refresh();
    }

  
    public function deleteFor(User $user, AiPersona $persona): void
    {
        $this->authorizeOwnership($user, $persona);

        $persona->delete();
    }

    public function setActiveFor(User $user, AiPersona $persona, bool $active): AiPersona
    {
        $this->authorizeOwnership($user, $persona);

        // Query-builder update, never the possibly stale in-memory model:
        // the passed persona may predate an earlier change, which would
        // make a model update a dirty-check no-op.
        $key = $persona->getKey();

        $user->aiPersonas()->where('id', $key)->update(['is_active' => $active]);

        return $user->aiPersonas()->findOrFail($key);
    }

   
    private function authorizeOwnership(User $user, AiPersona $persona): void
    {
        if ($persona->user_id === null || (string) $persona->user_id !== (string) $user->getKey()) {
            throw new AuthorizationException('You do not own this prompt.');
        }
    }


    private function validated(array $attributes, ?AiPersona $existing = null): array
    {
        $name = trim((string) ($attributes['name'] ?? $existing?->name ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('The prompt name must be 1–100 characters.');
        }

        $prompt = trim((string) ($attributes['system_prompt'] ?? $existing?->system_prompt ?? ''));

        if ($prompt === '' || mb_strlen($prompt) > 8000) {
            throw new InvalidArgumentException('The system prompt must be 1–8000 characters.');
        }

        $active = $attributes['is_active'] ?? $existing?->is_active ?? true;
        $active = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $priority = (int) ($attributes['priority'] ?? $existing?->priority ?? 0);
        $priority = max(-1000, min(1000, $priority));

        return [$name, $prompt, $active, $priority];
    }
}
