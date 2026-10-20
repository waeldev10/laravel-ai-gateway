<?php

namespace App\AI\Contracts;

use Generator;

interface AiProvider
{
    
    public function generate(array $messages): string;

    public function stream(array $messages): Generator;
}
