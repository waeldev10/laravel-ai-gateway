<?php

namespace Database\Factories;

use App\Enums\AiRequestStatus;
use App\Enums\AiSource;
use App\Models\AiRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
{
    protected $model = AiRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => fake()->randomElement(AiSource::cases()),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'conversation_id' => Conversation::factory(),
            'status' => AiRequestStatus::Completed,
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
            'duration_ms' => 1200,
            'retry_count' => 0,
        ];
    }
}
