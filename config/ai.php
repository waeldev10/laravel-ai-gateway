<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The globally configured AI provider. Supported values: "openai",
    | "gemini", "openrouter", "opencode", "glm". Provider selection is
    | global for now; per-user or per-conversation selection is a future
    | concern.
    |
    | API keys and model names are provided by the developer through the
    | environment (see .env.example). This application cannot create
    | provider accounts or retrieve private API keys.
    |
    */

    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Application Rate Limit
    |--------------------------------------------------------------------------
    |
    | Protects the application and its AI budget: how many assistant
    | generations a single user may trigger per minute. Upstream provider
    | limits (HTTP 429) are handled separately by the provider layer.
    |
    */

    'rate_limit' => [
        'max_attempts' => env('AI_RATE_LIMIT_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Usage Limit (Usage Window + Cooldown)
    |--------------------------------------------------------------------------
    |
    | Our own business rule — completely separate from HTTP request
    | throttling (rate_limit above), upstream provider rate limits
    | (HTTP 429, handled by the provider layer), and provider retries.
    |
    | A user may trigger up to `max_requests` AI generations per
    | `window_minutes`. When the window budget is exhausted the user is
    | blocked for `cooldown_minutes`, then usage is allowed again.
    | Disabled entirely with AI_USAGE_ENABLED=false.
    |
    */

    'usage' => [
        'enabled' => env('AI_USAGE_ENABLED', true),
        'window_minutes' => env('AI_USAGE_WINDOW_MINUTES', 15),
        'max_requests' => env('AI_USAGE_MAX_REQUESTS', 30),
        'cooldown_minutes' => env('AI_USAGE_COOLDOWN_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation Context / Memory
    |--------------------------------------------------------------------------
    |
    | Bounds for the context-building step. Only recent messages from the
    | current conversation plus a bounded set of long-term user memory
    | records are sent — never the entire history.
    |
    */

    'context' => [
        'history_limit' => env('AI_CONTEXT_HISTORY_LIMIT', 20),
        'memory_limit' => env('AI_CONTEXT_MEMORY_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Long-term User Memory Store
    |--------------------------------------------------------------------------
    |
    | Bound for the per-user memory store itself (retrieval per request is
    | separately bounded by `context.memory_limit`). Beyond the cap the
    | oldest memories make room; capture is heuristic (AiMemoryService)
    | and never blocks or fails an AI request.
    |
    */

    'memory' => [
        'max_per_user' => env('AI_MEMORY_MAX_PER_USER', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Prompt / Persona
    |--------------------------------------------------------------------------
    |
    | Fallback system prompt used when no active persona resolves for the
    | user (no user persona, no system default persona). Provider-agnostic:
    | resolved to plain text before crossing the AI boundary.
    |
    */

    'prompt' => [
        'default' => env('AI_DEFAULT_PROMPT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Behavior
    |--------------------------------------------------------------------------
    |
    | Transient failures (rate limits, temporary server errors, connection
    | and timeout failures) are retried centrally with exponential backoff,
    | jitter, and Retry-After support. Authentication, invalid requests,
    | and configuration errors are never retried.
    |
    */

    'retry' => [
        'max_attempts' => env('AI_RETRY_ATTEMPTS', 3),
        'base_sleep_ms' => env('AI_RETRY_BASE_SLEEP_MS', 100),
        'max_sleep_ms' => env('AI_RETRY_MAX_SLEEP_MS', 2000),
    ],

    'providers' => [

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'protocol' => env('OPENAI_PROTOCOL', 'chat_completions'),
            'timeout' => env('OPENAI_TIMEOUT', 30),
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'protocol' => env('GEMINI_PROTOCOL', 'generate_content'),
            'timeout' => env('GEMINI_TIMEOUT', 30),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'protocol' => env('OPENROUTER_PROTOCOL', 'chat_completions'),
            'referer' => env('OPENROUTER_REFERER'),
            'title' => env('OPENROUTER_TITLE'),
            'timeout' => env('OPENROUTER_TIMEOUT', 30),
        ],

        'opencode' => [
            'api_key' => env('OPENCODE_API_KEY'),
            'model' => env('OPENCODE_MODEL'),
            'base_url' => env('OPENCODE_BASE_URL', 'https://opencode.ai/zen/v1'),
            'protocol' => env('OPENCODE_PROTOCOL', 'responses'),
            'timeout' => env('OPENCODE_TIMEOUT', 30),
        ],

        'glm' => [
            'api_key' => env('GLM_API_KEY'),
            'model' => env('GLM_MODEL'),
            'base_url' => env('GLM_BASE_URL', 'https://api.z.ai/api/paas/v4'),
            'protocol' => env('GLM_PROTOCOL', 'chat_completions'),
            'timeout' => env('GLM_TIMEOUT', 30),
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'protocol' => env('DEEPSEEK_PROTOCOL', 'chat_completions'),
            'timeout' => env('DEEPSEEK_TIMEOUT', 30),
        ],

    ],

];
