<?php

namespace Database\Factories;

use App\Enums\AiSource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => fake()->randomElement(AiSource::cases()),
            'action' => 'ai.request_completed',
            'result' => 'success',
            'metadata' => ['provider' => 'openai'],
        ];
    }
}
