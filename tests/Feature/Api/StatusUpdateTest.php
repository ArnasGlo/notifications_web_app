<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_status_to_busy(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/status', ['status' => 'busy']);

        $response->assertStatus(200)->assertJsonPath('data.status', 'busy');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'busy']);
    }

    public function test_updates_status_to_dnd(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->patchJson('/api/status', ['status' => 'dnd']);

        $response->assertStatus(200)->assertJsonPath('data.status', 'dnd');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'dnd']);
    }

    public function test_returning_to_active_flips_queued_messages_on_owned_numbers_to_sent(): void
    {
        $user = User::factory()->create(['status' => 'busy']);
        $number = Number::factory()->for($user)->create();
        $other = Number::factory()->create();
        $queued = Message::factory()->queued()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/status', ['status' => 'active'])
            ->assertStatus(200);

        $this->assertDatabaseHas('messages', ['id' => $queued->id, 'status' => 'sent']);
    }

    public function test_returning_to_active_does_not_flip_queued_messages_on_a_delegated_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create(['status' => 'busy']);
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $other = Number::factory()->create();
        $queued = Message::factory()->queued()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $this->actingAs($assistant, 'sanctum')
            ->patchJson('/api/status', ['status' => 'active'])
            ->assertStatus(200);

        $this->assertDatabaseHas('messages', ['id' => $queued->id, 'status' => 'queued']);
    }

    public function test_returning_to_active_does_not_affect_already_sent_or_read_messages(): void
    {
        $user = User::factory()->create(['status' => 'busy']);
        $number = Number::factory()->for($user)->create();
        $other = Number::factory()->create();
        $sent = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'status' => 'sent',
        ]);
        $read = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'status' => 'read',
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/status', ['status' => 'active'])
            ->assertStatus(200);

        $this->assertDatabaseHas('messages', ['id' => $sent->id, 'status' => 'sent']);
        $this->assertDatabaseHas('messages', ['id' => $read->id, 'status' => 'read']);
    }

    public function test_rejects_an_invalid_status_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/status', ['status' => 'invisible'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_requires_a_status_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/status', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson('/api/status', ['status' => 'busy'])->assertStatus(401);
    }
}
