<?php

namespace Database\Factories;

use App\Models\AiMemory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMemory>
 */
class AiMemoryFactory extends Factory
{
    protected $model = AiMemory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'content' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
