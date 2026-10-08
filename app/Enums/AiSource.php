<?php

namespace App\Enums;

enum AiSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Telegram = 'telegram';

    /**
     * Normalize a raw source identifier into a known enum case.
     *
     * Unknown identifiers fall back to Web so callers never persist
     * arbitrary channel strings: adding a source means adding a case.
     */
    public static function fromRaw(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Web;
    }
}
