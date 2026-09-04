<?php

namespace Database\Factories;

use App\Models\MessageCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => MessageCategory::factory(),
            'body' => fake()->sentence(4),
            'is_reply' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the template is a reply template.
     *
     * @return $this
     */
    public function reply(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_reply' => true,
        ]);
    }
}
