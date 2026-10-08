<?php

namespace App\Services\AI;

use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AiPromptService
{
    /**
     * Maximum user-owned personas. The system default never counts
     * toward it.
     */
    public const MAX_CUSTOM_PERSONAS = 3;

    /**
     * The effective personas for a user, in deterministic request order:
     * the system default first (when present), then the user's active
     * custom personas (highest priority first, oldest first on ties).
     *
     * This is the single centralized resolution path: callers send one
     * system message per entry and never load personas any other way.
     * The default is always included; any combination of the custom
     * personas may be active simultaneously.
     *
     * @return Collection<int, AiPersona>
     */
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

    /**
     * The user's active custom personas in deterministic order. The
     * system default is never part of this set — it is always active by
     * definition and resolved separately.
     *
     * @return Collection<int, AiPersona>
     */
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

    /**
     * Enforced server-side: the UI limit is presentation only.
     *
     * @param  array{name?: mixed, system_prompt?: mixed, is_active?: mixed, priority?: mixed}  $attributes
     *
     * @throws InvalidArgumentException
     */
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

    /**
     * @param  array{name?: mixed, system_prompt?: mixed, is_active?: mixed, priority?: mixed}  $attributes
     *
     * @throws AuthorizationException
     */
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

    /**
     * @throws AuthorizationException
     */
    public function deleteFor(User $user, AiPersona $persona): void
    {
        $this->authorizeOwnership($user, $persona);

        $persona->delete();
    }

    /**
     * Activate or deactivate exactly one custom persona. Each custom
     * persona toggles independently — any combination may be active at
     * once. The system default is always active and can never be passed
     * here: ownership rejects ownerless rows, so deactivating or
     * selecting the default always fails closed.
     *
     * @throws AuthorizationException
     */
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

    /**
     * @throws AuthorizationException
     */
    private function authorizeOwnership(User $user, AiPersona $persona): void
    {
        if ($persona->user_id === null || (string) $persona->user_id !== (string) $user->getKey()) {
            throw new AuthorizationException('You do not own this prompt.');
        }
    }

    /**
     * Only the four user-editable attributes are ever read: `is_default`
     * and `user_id` can never be set through user input, so a normal user
     * can neither claim nor fabricate the system default.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{string, string, bool, int}
     *
     * @throws InvalidArgumentException
     */
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
