<?php

namespace App\Services\Theme;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UiColorService
{
    public const HEX_RULE = 'regex:/^#[0-9a-fA-F]{6}$/';

    /**
     * Tokens customizable through the Settings modal.
     *
     * @return array<int, string>
     */
    public function tokens(): array
    {
        $tokens = config('ui.colors.tokens', ['primary', 'primary_text', 'accent', 'link']);

        return array_values(array_filter($tokens, fn ($t) => is_string($t) && $t !== ''));
    }

    /**
     * Authoritative defaults from config/ui.php (single source).
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function defaults(): array
    {
        $defaults = config('ui.colors.defaults', []);

        $light = is_array($defaults['light'] ?? null) ? $defaults['light'] : [];
        $dark = is_array($defaults['dark'] ?? null) ? $defaults['dark'] : [];

        $out = ['light' => [], 'dark' => []];

        foreach ($this->tokens() as $token) {
            $out['light'][$token] = $this->sanitizedHex($light[$token] ?? null) ?? '#000000';
            // Dark falls back to the light default only if the config omits it,
            // so a missing dark key can never produce an empty variable.
            $out['dark'][$token] = $this->sanitizedHex($dark[$token] ?? null) ?? $out['light'][$token];
        }

        return $out;
    }

    /**
     * Saved per-user customizations (single #RRGGBB per token or null).
     *
     * @return array<string, string|null>
     */
    public function customFor(?User $user): array
    {
        $stored = $user?->ui_colors;

        if (! is_array($stored)) {
            $stored = [];
        }

        $out = [];

        foreach ($this->tokens() as $token) {
            $out[$token] = $this->sanitizedHex($stored[$token] ?? null);
        }

        return $out;
    }

    /**
     * Effective values for rendering: custom when present, otherwise the
     * scheme-specific default. Guests receive the defaults.
     *
     * @return array{light: array<string, string>, dark: array<string, string>, custom: array<string, string|null>}
     */
    public function effectiveFor(?User $user): array
    {
        $defaults = $this->defaults();
        $custom = $this->customFor($user);

        $light = [];
        $dark = [];

        foreach ($this->tokens() as $token) {
            $light[$token] = $custom[$token] ?? $defaults['light'][$token];
            $dark[$token] = $custom[$token] ?? $defaults['dark'][$token];
        }

        return ['light' => $light, 'dark' => $dark, 'custom' => $custom];
    }

    /**
     * Validate and persist the user's colors. Only known tokens in strict
     * #RRGGBB format are stored; anything else is rejected.
     *
     * @param  array<string, mixed>  $colors
     *
     * @throws ValidationException
     */
    public function saveFor(User $user, array $colors): User
    {
        $rules = [];

        foreach ($this->tokens() as $token) {
            $rules[$token] = ['required', 'string', self::HEX_RULE];
        }

        $validated = Validator::make($colors, $rules)->validate();

        $normalized = [];

        foreach ($this->tokens() as $token) {
            $normalized[$token] = strtoupper((string) $validated[$token]);
        }

        $user->forceFill(['ui_colors' => $normalized])->save();

        return $user->refresh();
    }

    /**
     * Clear customizations so both schemes fall back to config defaults.
     */
    public function resetFor(User $user): User
    {
        $user->forceFill(['ui_colors' => null])->save();

        return $user->refresh();
    }

    private function sanitizedHex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }
}
