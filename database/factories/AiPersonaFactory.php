<?php

namespace Database\Factories;

use App\Models\AiPersona;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPersona>
 */
class AiPersonaFactory extends Factory
{
    protected $model = AiPersona::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'system_prompt' => fake()->sentence(),
            'is_active' => true,
            'priority' => 0,
        ];
    }
}
