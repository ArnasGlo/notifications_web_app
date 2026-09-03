<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Number>
 */
class NumberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'number' => '+3706' . fake()->unique()->numerify('#######'),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the number is inactive.
     *
     * @return $this
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
