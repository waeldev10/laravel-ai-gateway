<?php

namespace App\AI\Contracts;

use App\AI\Exceptions\AiException;
use Generator;

interface AiProvider
{
    /**
     * Generate the assistant's reply text for normalized chat messages.
     *
     * The application only knows "messages in, assistant text out". How an
     * individual provider builds its HTTP request or parses its response
     * stays behind this contract.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws AiException
     */
    public function generate(array $messages): string;

    /**
     * Stream the assistant's reply as incremental text deltas.
     *
     * Yields each text chunk as it arrives from the upstream provider. The
     * caller accumulates the chunks; this contract never persists anything.
     * All provider/protocol-specific streaming behavior stays behind this
     * contract — callers only see plain strings.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return Generator<int, string, mixed, void>
     *
     * @throws AiException
     */
    public function stream(array $messages): Generator;
}
