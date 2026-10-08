<?php

namespace App\Models;

use Database\Factories\AiPersonaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'name',
    'system_prompt',
    'is_active',
    'is_default',
    'priority',
])]
class AiPersona extends Model
{
    /** @use HasFactory<AiPersonaFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * System-level default personas have no owner.
     */
    public function isSystemDefault(): bool
    {
        return $this->user_id === null;
    }
}
