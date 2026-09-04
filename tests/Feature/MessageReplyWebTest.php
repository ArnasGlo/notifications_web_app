<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The web reply rules used to live only in resources/views/messages/show.blade.php,
 * so a direct POST bypassed all of them. They now sit on the Message model and are
 * enforced by both MessageController@reply and Api\MessageController@reply — these
 * tests pin the web half so the two clients cannot drift apart again.
 */
class MessageReplyWebTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Message, 1: MessageCategory, 2: User} */
    private function threadFor(User $receiverOwner): array
    {
        $senderNumber = Number::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create();

        $message = Message::factory()->create([
            'sender_number_id' => $senderNumber->id,
            'receiver_number_id' => $receiverNumber->id,
            'template_id' => $template->id,
        ]);

        return [$message, $category, $senderNumber->user];
    }

    private function postReply(User $as, Message $message, MessageTemplate $template)
    {
        return $this->actingAs($as)
            ->from(route('messages.show', $message))
            ->post(route('messages.reply', $message), ['template_id' => $template->id]);
    }

    public function test_a_valid_reply_is_still_accepted(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category] = $this->threadFor($receiverOwner);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->postReply($receiverOwner, $message, $replyTemplate)
            ->assertRedirect(route('messages.show', $message));

        $this->assertDatabaseHas('messages', [
            'parent_id' => $message->id,
            'template_id' => $replyTemplate->id,
            'status' => 'sent',
        ]);
    }

    public function test_a_second_reply_is_rejected(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category] = $this->threadFor($receiverOwner);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->postReply($receiverOwner, $message, $replyTemplate);
        $this->postReply($receiverOwner, $message, $replyTemplate)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_a_non_reply_template_is_rejected(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category] = $this->threadFor($receiverOwner);
        $nonReply = MessageTemplate::factory()->for($category, 'category')->create(['is_reply' => false]);

        $this->postReply($receiverOwner, $message, $nonReply)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_inactive_reply_template_is_rejected(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category] = $this->threadFor($receiverOwner);
        $inactive = MessageTemplate::factory()->for($category, 'category')->reply()->create(['is_active' => false]);

        $this->postReply($receiverOwner, $message, $inactive)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_template_from_a_different_category_is_rejected(): void
    {
        $receiverOwner = User::factory()->create();
        [$message] = $this->threadFor($receiverOwner);
        $otherCategory = MessageCategory::factory()->create();
        $wrongCategory = MessageTemplate::factory()->for($otherCategory, 'category')->reply()->create();

        $this->postReply($receiverOwner, $message, $wrongCategory)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_replying_to_a_reply_is_rejected(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category, $senderOwner] = $this->threadFor($receiverOwner);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->postReply($receiverOwner, $message, $replyTemplate);
        $reply = Message::where('parent_id', $message->id)->firstOrFail();

        // The original sender receives the reply, so they clear the 403 gate.
        $this->postReply($senderOwner, $reply, $replyTemplate)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_the_sender_side_viewer_cannot_reply(): void
    {
        $receiverOwner = User::factory()->create();
        [$message, $category, $senderOwner] = $this->threadFor($receiverOwner);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $this->actingAs($senderOwner)
            ->post(route('messages.reply', $message), ['template_id' => $replyTemplate->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('messages', 1);
    }
}
