<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\Delegate;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageReplyTest extends TestCase
{
    use RefreshDatabase;

    private function messageWithCategory(Number $sender, Number $receiver): array
    {
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create();
        $message = Message::factory()->create([
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        return [$message, $category];
    }

    public function test_reply_creates_a_message_with_sender_and_receiver_swapped(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'OK']);

        $response = $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sender.id', $receiverNumber->id)
            ->assertJsonPath('data.receiver.id', $senderNumber->id)
            ->assertJsonPath('data.template.id', $replyTemplate->id);

        $this->assertDatabaseHas('messages', [
            'sender_number_id' => $receiverNumber->id,
            'receiver_number_id' => $senderNumber->id,
            'template_id' => $replyTemplate->id,
            'parent_id' => $message->id,
            'status' => 'sent',
        ]);
    }

    public function test_reply_bypasses_dnd_blocking_and_busy_checks(): void
    {
        $senderOwner = User::factory()->create(['status' => 'dnd']);
        $senderNumber = Number::factory()->for($senderOwner)->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        // The original sender is now DND and has blocked the receiver number outright.
        Block::create(['number_id' => $senderNumber->id, 'type' => 'number', 'value' => $receiverNumber->number]);

        $response = $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'sent');
    }

    public function test_a_delegate_of_the_receiver_number_can_reply(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $assistant = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        Delegate::create(['number_id' => $receiverNumber->id, 'assistant_user_id' => $assistant->id]);
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(201);
    }

    public function test_the_sender_side_viewer_cannot_reply(): void
    {
        $senderOwner = User::factory()->create();
        $senderNumber = Number::factory()->for($senderOwner)->create();
        $receiverNumber = Number::factory()->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->actingAs($senderOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_unrelated_user_cannot_reply(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverNumber = Number::factory()->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(403);
    }

    public function test_a_second_reply_is_rejected(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(201);

        $response = $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_replying_to_a_reply_is_rejected(): void
    {
        // Threads are one level deep (ANDROID_APP_CONTEXT.md §3). A depth-2 message
        // would be invisible in every list view, since index/numberInbox filter on
        // parent_id IS NULL and show() loads only direct children.
        $senderOwner = User::factory()->create();
        $senderNumber = Number::factory()->for($senderOwner)->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $replyId = $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(201)
            ->json('data.id');

        // The original sender receives the reply, so they clear the 403 gate.
        $this->actingAs($senderOwner, 'sanctum')
            ->postJson("/api/messages/{$replyId}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_a_template_from_a_different_category_is_rejected(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $otherCategory = MessageCategory::factory()->create();
        $wrongCategoryReply = MessageTemplate::factory()->for($otherCategory, 'category')->reply()->create();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $wrongCategoryReply->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_non_reply_template_is_rejected(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $nonReply = MessageTemplate::factory()->for($category, 'category')->create(['is_reply' => false]);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $nonReply->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_inactive_reply_template_is_rejected(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $inactiveReply = MessageTemplate::factory()->for($category, 'category')->reply()->create(['is_active' => false]);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $inactiveReply->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_reply_requires_a_template_id(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_id']);
    }

    public function test_reply_rejects_a_nonexistent_template(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_id']);
    }

    public function test_reply_returns_404_for_a_nonexistent_message(): void
    {
        $owner = User::factory()->create();
        $template = MessageTemplate::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages/999999/reply', ['template_id' => $template->id])
            ->assertStatus(404);
    }

    public function test_reply_requires_authentication(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverNumber = Number::factory()->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(401);
    }
}
