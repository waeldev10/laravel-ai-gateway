<?php

namespace App\AI;

use App\Services\AI\AiService as ApplicationAiService;

/**
 * Backward-compatibility alias.
 *
 * The application-level AI orchestration service lives in
 * `App\Services\AI\AiService` (AI integration != application service).
 * This class remains so existing imports keep resolving; new code must
 * import the application service directly.
 *
 * @deprecated Use App\Services\AI\AiService instead.
 */
class AiService extends ApplicationAiService {}
