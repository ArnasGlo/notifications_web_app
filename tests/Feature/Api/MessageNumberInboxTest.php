<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageNumberInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_a_top_level_message_with_the_full_resource_shape(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $category = MessageCategory::factory()->create(['name' => 'Meeting', 'icon' => 'fas fa-calendar']);
        $template = MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'template_id' => $template->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.status', 'sent')
            ->assertJsonPath('data.0.sender.id', $other->id)
            ->assertJsonPath('data.0.receiver.id', $number->id)
            ->assertJsonPath('data.0.template.body', 'Can you talk?')
            ->assertJsonPath('data.0.template.category.name', 'Meeting');
    }

    public function test_index_lists_messages_where_the_number_is_sender_or_receiver(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $otherA = Number::factory()->create();
        $otherB = Number::factory()->create();
        $received = Message::factory()->create([
            'sender_number_id' => $otherA->id,
            'receiver_number_id' => $number->id,
        ]);
        $sent = Message::factory()->create([
            'sender_number_id' => $number->id,
            'receiver_number_id' => $otherB->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($received->id));
        $this->assertTrue($ids->contains($sent->id));
    }

    public function test_index_is_accessible_to_a_delegate_of_the_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $response = $this->actingAs($assistant, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)->assertJsonPath('data.0.id', $message->id);
    }

    public function test_index_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/messages")
            ->assertStatus(403);
    }

    public function test_index_excludes_replies_but_keeps_the_parent(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $parent = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $number->id,
            'receiver_number_id' => $other->id,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $parent->id);
    }

    public function test_index_excludes_messages_for_a_different_number(): void
    {
        $owner = User::factory()->create();
        $numberA = Number::factory()->for($owner)->create();
        $numberB = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $numberB->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$numberA->id}/messages");

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_index_orders_newest_first(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $older = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'created_at' => now()->subMinutes(10),
        ]);
        $newer = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_index_paginates_at_twenty_per_page(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        Message::factory()->count(25)->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/messages");

        $response->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20);
    }

    public function test_index_returns_an_empty_list_when_no_messages(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/messages")
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_returns_404_for_a_nonexistent_number(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/numbers/999999/messages')
            ->assertStatus(404);
    }

    public function test_index_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->getJson("/api/numbers/{$number->id}/messages")->assertStatus(401);
    }
}
