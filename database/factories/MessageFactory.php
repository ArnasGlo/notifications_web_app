<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\Number;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_number_id' => Number::factory(),
            'receiver_number_id' => Number::factory(),
            'template_id' => MessageTemplate::factory(),
            // Mirrors a message seeded from its template, which is what every
            // message was before free text existed. Override for typed messages.
            'body' => fn (array $attributes) => MessageTemplate::find($attributes['template_id'])?->body
                ?? fake()->sentence(4),
            'parent_id' => null,
            'status' => 'sent',
        ];
    }

    /**
     * Indicate that the message is queued (recipient busy).
     *
     * @return $this
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'queued',
        ]);
    }

    /**
     * Indicate that the message is a reply to the given parent message.
     *
     * @return $this
     */
    public function reply(int $parentId): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parentId,
        ]);
    }
}
