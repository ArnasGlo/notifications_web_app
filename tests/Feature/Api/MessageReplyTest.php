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

    /**
     * Valid replies are rows in message_template_replies. Running the real
     * backfill maps each active prompt to the active replies in its category,
     * which is how every template that existed before the mapping got its rows.
     */
    private function mapRepliesByCategory(): void
    {
        $this->rerunMigration('2026_09_15_000001_create_message_template_replies_table');
    }

    public function test_reply_creates_a_message_with_sender_and_receiver_swapped(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'OK']);
        $this->mapRepliesByCategory();

        $response = $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sender.id', $receiverNumber->id)
            ->assertJsonPath('data.receiver.id', $senderNumber->id)
            ->assertJsonPath('data.template.id', $replyTemplate->id)
            ->assertJsonPath('data.body', 'OK');

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
        $this->mapRepliesByCategory();

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
        $this->mapRepliesByCategory();

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
        // Mapped, so the 403 is about which side the viewer is on, not the template.
        $this->mapRepliesByCategory();

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
        $this->mapRepliesByCategory();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(403);
    }

    public function test_a_message_can_be_replied_to_more_than_once_by_anyone_on_the_receiving_side(): void
    {
        // One-reply-per-message was relaxed in Slice 3: an owner and an assistant
        // sharing a number no longer race for a single reply.
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $assistant = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        Delegate::create(['number_id' => $receiverNumber->id, 'assistant_user_id' => $assistant->id]);
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $this->mapRepliesByCategory();

        foreach ([$receiverOwner, $assistant, $receiverOwner] as $replier) {
            $this->actingAs($replier, 'sanctum')
                ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
                ->assertStatus(201)
                ->assertJsonPath('data.parent_id', $message->id);
        }

        $this->assertSame(3, $message->replies()->count());
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
        $this->mapRepliesByCategory();

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

    public function test_an_unmapped_reply_template_is_rejected(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);
        // Active reply template, but in another category, so the backfill doesn't map it.
        $unmapped = MessageTemplate::factory()->for(MessageCategory::factory()->create(), 'category')->reply()->create();
        $this->mapRepliesByCategory();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $unmapped->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_pruned_mapping_is_refused_by_the_endpoint_not_just_hidden(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $kept = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $pruned = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $this->mapRepliesByCategory();

        // Same category, still active and still a reply template: only the
        // mapping row is gone.
        $message->template->replyTemplates()->detach($pruned->id);

        $offered = $this->actingAs($receiverOwner, 'sanctum')
            ->getJson("/api/messages/{$message->id}")
            ->json('data.reply_templates');
        $this->assertSame([$kept->id], array_column($offered, 'id'));

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $pruned->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This template cannot be used as a reply to this message.');
        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $kept->id])
            ->assertStatus(201);
    }

    public function test_a_mapped_reply_template_from_another_category_is_accepted(): void
    {
        // The mapping is the only rule: category no longer decides what answers what.
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $elsewhere = MessageTemplate::factory()->for(MessageCategory::factory()->create(), 'category')->reply()->create();
        $message->template->replyTemplates()->attach($elsewhere->id);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $elsewhere->id])
            ->assertStatus(201)
            ->assertJsonPath('data.template.id', $elsewhere->id);
    }

    public function test_a_non_reply_template_is_rejected_even_when_mapped(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $nonReply = MessageTemplate::factory()->for($category, 'category')->create(['is_reply' => false]);
        $message->template->replyTemplates()->attach($nonReply->id);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $nonReply->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_an_inactive_reply_template_is_rejected_even_when_mapped(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $inactiveReply = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $this->mapRepliesByCategory();
        $inactiveReply->update(['is_active' => false]);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $inactiveReply->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_reply_requires_a_template_or_a_body(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_id', 'body']);
    }

    public function test_reply_rejects_a_body_over_255_characters(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message] = $this->messageWithCategory($senderNumber, $receiverNumber);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['body' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    // ── Free-text replies (Slice 3) ──────────────────────────────────────────

    /** @return array{0: Message, 1: User, 2: MessageTemplate} a message to $receiverOwner with one mapped reply template */
    private function repliableMessage(?User $senderOwner = null): array
    {
        $senderNumber = Number::factory()->for($senderOwner ?? User::factory()->create())->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        [$message, $category] = $this->messageWithCategory($senderNumber, $receiverNumber);
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'On my way']);
        $this->mapRepliesByCategory();

        return [$message, $receiverOwner, $replyTemplate];
    }

    public function test_a_reply_can_be_typed_text(): void
    {
        [$message, $receiverOwner] = $this->repliableMessage();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['body' => 'Give me ten minutes'])
            ->assertStatus(201)
            ->assertJsonPath('data.parent_id', $message->id)
            ->assertJsonPath('data.body', 'Give me ten minutes')
            ->assertJsonPath('data.template', null);
    }

    public function test_a_template_sent_with_its_exact_text_is_still_a_template_reply(): void
    {
        [$message, $receiverOwner, $replyTemplate] = $this->repliableMessage();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id, 'body' => 'On my way'])
            ->assertStatus(201)
            ->assertJsonPath('data.template.id', $replyTemplate->id);
    }

    public function test_an_edited_template_is_a_free_text_reply_whatever_the_template_was(): void
    {
        // The original's own prompt template is no valid answer, but once its
        // text is edited it isn't that template any more — it's typed text.
        [$message, $receiverOwner] = $this->repliableMessage();

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", [
                'template_id' => $message->template_id,
                'body' => $message->body.' Tomorrow?',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.template', null);
    }

    public function test_dnd_refuses_a_free_text_reply_but_not_a_template_reply(): void
    {
        [$message, $receiverOwner, $replyTemplate] = $this->repliableMessage(User::factory()->create(['status' => 'dnd']));

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['body' => 'Are you there?'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This number cannot receive your message (blocked or DND).');
        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(201);
    }

    public function test_a_block_refuses_a_free_text_reply_but_not_a_template_reply(): void
    {
        [$message, $receiverOwner, $replyTemplate] = $this->repliableMessage();
        Block::create(['number_id' => $message->sender_number_id, 'type' => 'number', 'value' => $message->receiver->number]);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['body' => 'Why did you block me?'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This number cannot receive your message (blocked or DND).');
        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $replyTemplate->id])
            ->assertStatus(201);
    }

    public function test_a_free_text_reply_skips_the_busy_queue(): void
    {
        [$message, $receiverOwner] = $this->repliableMessage(User::factory()->create(['status' => 'busy']));

        $this->actingAs($receiverOwner, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['body' => 'No rush'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'sent');
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
