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

class MessageShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiver_owner_viewing_a_sent_message_marks_it_read(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $message->id)
            ->assertJsonPath('data.status', 'read');

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'status' => 'read']);
    }

    public function test_sender_side_viewer_does_not_trigger_the_read_side_effect(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $number->id,
            'receiver_number_id' => $other->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'status' => 'sent', 'read_at' => null]);
    }

    public function test_a_delegate_of_the_receiver_can_view_and_triggers_the_read_side_effect(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($assistant, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)->assertJsonPath('data.status', 'read');
    }

    public function test_a_queued_message_is_not_auto_marked_read(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'status' => 'queued',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'status' => 'queued', 'read_at' => null]);
    }

    public function test_is_forbidden_for_a_user_with_no_relation_to_either_side(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $sender = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $number->id,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/messages/{$message->id}")
            ->assertStatus(403);
    }

    public function test_returns_404_for_a_nonexistent_message(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages/999999')
            ->assertStatus(404);
    }

    public function test_response_includes_an_existing_reply(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create();
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'Sure thing']);
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'template_id' => $template->id,
        ]);
        $reply = Message::factory()->reply($message->id)->create([
            'sender_number_id' => $number->id,
            'receiver_number_id' => $other->id,
            'template_id' => $replyTemplate->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.id', $reply->id)
            ->assertJsonPath('data.replies.0.template.body', 'Sure thing')
            ->assertJsonPath('data.replies.0.sender.id', $number->id)
            ->assertJsonPath('data.replies.0.receiver.id', $other->id);
    }

    public function test_response_has_empty_replies_when_none_exist(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)->assertJsonPath('data.replies', []);
    }

    public function test_response_includes_active_reply_templates_for_the_same_category(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create();
        $activeReply = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'OK', 'is_active' => true]);
        $inactiveReply = MessageTemplate::factory()->for($category, 'category')->reply()->create(['is_active' => false]);
        $nonReply = MessageTemplate::factory()->for($category, 'category')->create(['is_reply' => false]);
        $message = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'template_id' => $template->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/messages/{$message->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.reply_templates')
            ->assertJsonPath('data.reply_templates.0.id', $activeReply->id)
            ->assertJsonPath('data.reply_templates.0.body', 'OK');

        $ids = collect($response->json('data.reply_templates'))->pluck('id');
        $this->assertFalse($ids->contains($inactiveReply->id));
        $this->assertFalse($ids->contains($nonReply->id));
    }

    public function test_requires_authentication(): void
    {
        $message = Message::factory()->create();

        $this->getJson("/api/messages/{$message->id}")->assertStatus(401);
    }
}
