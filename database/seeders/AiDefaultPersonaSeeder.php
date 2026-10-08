<?php

namespace Database\Seeders;

use App\Models\AiPersona;
use Illuminate\Database\Seeder;

class AiDefaultPersonaSeeder extends Seeder
{
    public const DEFAULT_NAME = 'Default Assistant';

    public const DEFAULT_PROMPT = 'You are a helpful AI assistant. Answer clearly and concisely, and match the user language.';

    /**
     * Ensure exactly one system default prompt exists.
     *
     * Idempotent and non-destructive: running twice never duplicates, and
     * an existing customized default is never overwritten. If legacy
     * system rows exist without a flagged default, the oldest active one
     * is promoted instead of creating a duplicate.
     */
    public function run(): void
    {
        $flagged = AiPersona::query()
            ->whereNull('user_id')
            ->where('is_default', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($flagged->isNotEmpty()) {
            // Normalize accidents: keep the oldest flagged row, unflag rest.
            foreach ($flagged->skip(1) as $duplicate) {
                $duplicate->update(['is_default' => false]);
            }

            return;
        }

        $legacy = AiPersona::query()
            ->whereNull('user_id')
            ->where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($legacy instanceof AiPersona) {
            $legacy->update(['is_default' => true]);

            return;
        }

        AiPersona::create([
            'user_id' => null,
            'name' => self::DEFAULT_NAME,
            'system_prompt' => $this->defaultPromptText(),
            'is_active' => true,
            'is_default' => true,
            'priority' => 0,
        ]);
    }

    private function defaultPromptText(): string
    {
        $configured = trim((string) config('ai.prompt.default', ''));

        return $configured !== '' ? mb_substr($configured, 0, 8000) : self::DEFAULT_PROMPT;
    }
}
