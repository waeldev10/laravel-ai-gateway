<?php

namespace App\Support;

/**
 * Sanctum personal-access-token abilities for /api/v1.
 *
 * Kept deliberately small: read vs write per domain, plus a dedicated
 * `ai:generate` gate for the expensive synchronous AI endpoint. Tokens
 * never expose provider keys or configuration — abilities only control
 * which of the user's own resources the bearer may touch.
 */
final class ApiAbilities
{
    public const CONVERSATIONS_READ = 'conversations:read';

    public const CONVERSATIONS_WRITE = 'conversations:write';

    public const MESSAGES_READ = 'messages:read';

    public const MESSAGES_WRITE = 'messages:write';

    public const AI_GENERATE = 'ai:generate';

    public const PERSONAS_READ = 'personas:read';

    public const PERSONAS_WRITE = 'personas:write';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CONVERSATIONS_READ,
            self::CONVERSATIONS_WRITE,
            self::MESSAGES_READ,
            self::MESSAGES_WRITE,
            self::AI_GENERATE,
            self::PERSONAS_READ,
            self::PERSONAS_WRITE,
        ];
    }
}
