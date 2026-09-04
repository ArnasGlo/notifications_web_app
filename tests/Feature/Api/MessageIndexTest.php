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

class MessageIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_a_top_level_message_with_the_full_resource_shape(): void
    {
        $owner = User::factory()->create();
        $myNumber = Number::factory()->for($owner)->create();
        $otherNumber = Number::factory()->create();
        $category = MessageCategory::factory()->create(['name' => 'Meeting', 'icon' => 'fas fa-calendar']);
        $template = MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);
        $message = Message::factory()->create([
            'sender_number_id' => $otherNumber->id,
            'receiver_number_id' => $myNumber->id,
            'template_id' => $template->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.status', 'sent')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.0.sender.id', $otherNumber->id)
            ->assertJsonPath('data.0.sender.number', $otherNumber->number)
            ->assertJsonPath('data.0.sender.user_id', $otherNumber->user_id)
            ->assertJsonPath('data.0.receiver.id', $myNumber->id)
            ->assertJsonPath('data.0.receiver.number', $myNumber->number)
            ->assertJsonPath('data.0.receiver.user_id', $owner->id)
            ->assertJsonPath('data.0.template.id', $template->id)
            ->assertJsonPath('data.0.template.body', 'Can you talk?')
            ->assertJsonPath('data.0.template.category.id', $category->id)
            ->assertJsonPath('data.0.template.category.name', 'Meeting')
            ->assertJsonPath('data.0.template.category.icon', 'fas fa-calendar');

        $response->assertJsonMissingPath('data.0.is_sender')
            ->assertJsonMissingPath('data.0.is_receiver')
            ->assertJsonMissingPath('data.0.is_unread');
    }

    public function test_index_includes_messages_for_a_delegated_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $sender = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $number->id,
        ]);

        $response = $this->actingAs($assistant, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)->assertJsonPath('data.0.id', $message->id);
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

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $parent->id);
    }

    public function test_index_excludes_messages_unrelated_to_the_user(): void
    {
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();
        $unrelatedA = Number::factory()->create();
        $unrelatedB = Number::factory()->create();
        Message::factory()->create([
            'sender_number_id' => $unrelatedA->id,
            'receiver_number_id' => $unrelatedB->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

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

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

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

        $page1 = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $page1->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1);

        $page2 = $this->actingAs($owner, 'sanctum')->getJson('/api/messages?page=2');

        $page2->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_returns_an_empty_list_when_no_messages(): void
    {
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/messages')->assertStatus(401);
    }
}
