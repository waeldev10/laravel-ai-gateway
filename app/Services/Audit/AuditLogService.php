<?php

namespace App\Services\Audit;

use App\Enums\AiSource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public const AI_REQUEST_STARTED = 'ai.request_started';

    public const AI_REQUEST_COMPLETED = 'ai.request_completed';

    public const AI_REQUEST_FAILED = 'ai.request_failed';

    public const AI_USAGE_BLOCKED = 'ai.usage_blocked';

    public const AI_COOLDOWN_ACTIVATED = 'ai.cooldown_activated';

    public const CONVERSATION_CREATED = 'conversation.created';

    public const MESSAGE_SENT = 'message.sent';

    public const MESSAGE_EDITED = 'message.edited';

    public const MESSAGE_REGENERATED = 'message.regenerated';

    public const MESSAGE_CONTINUED = 'message.continued';

    public const PROVIDER_FAILURE = 'provider.failure';

    /**
     * Record one auditable application action: who, what, from which
     * source, when (created_at), on which resource (auditable morph),
     * with what result, under a relevant identifier in metadata.
     *
     * Never pass secrets, keys, headers, passwords, OTPs, prompts, or
     * completions: metadata is sanitized defensively (sensitive keys
     * dropped, strings truncated), but callers must only supply
     * operational identifiers (ids, provider/model names, error codes).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?User $user,
        AiSource|string $source,
        string $action,
        string $result = 'success',
        array $metadata = [],
        ?Model $auditable = null,
    ): AuditLog {
        $source = $source instanceof AiSource ? $source : AiSource::fromRaw($source);

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'source' => $source,
            'action' => mb_substr(trim($action), 0, 100),
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'result' => in_array($result, ['success', 'failed', 'blocked'], true) ? $result : 'success',
            'metadata' => self::sanitize($metadata),
        ]);
    }

    /**
     * Drop sensitive keys at any depth and truncate every string, so a
     * careless caller cannot persist credentials or full prompt content.
     * Only scalars and plain arrays survive; anything else is dropped.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function sanitize(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $name = (string) $key;

            if (preg_match('/key|token|secret|password|passwd|authorization|otp|credential|api_key|apikey|content|prompt|completion|response_body|request_body|headers?|cookie/i', $name)) {
                continue;
            }

            if (is_array($value)) {
                $nested = self::sanitize($value);

                if ($nested !== []) {
                    $clean[$name] = $nested;
                }

                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }

                $clean[$name] = mb_substr($value, 0, 500);

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
