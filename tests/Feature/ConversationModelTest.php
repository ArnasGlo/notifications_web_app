<?php

namespace Tests\Feature;

use App\Actions\ReplyToMessage;
use App\Actions\SendMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pair-identity guarantees the whole conversation feature rests on.
 */
class ConversationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_is_filed_into_a_conversation_automatically(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();

        $message = Message::factory()->create([
            'sender_number_id' => $a->id,
            'receiver_number_id' => $b->id,
        ]);

        $this->assertNotNull($message->conversation_id);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_the_pair_is_stored_normalised_regardless_of_direction(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();
        [$low, $high] = [min($a->id, $b->id), max($a->id, $b->id)];

        Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);
        Message::factory()->create(['sender_number_id' => $b->id, 'receiver_number_id' => $a->id]);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', [
            'number_one_id' => $low,
            'number_two_id' => $high,
        ]);
    }

    public function test_a_user_owning_both_numbers_still_gets_one_thread(): void
    {
        // This is the case that ruled out deriving conversations in SQL: a
        // viewer-relative CASE WHEN would file 3->4 and 4->3 into different
        // groups, splitting one thread in half.
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alsoMine = Number::factory()->for($owner)->create();

        $out = Message::factory()->create(['sender_number_id' => $mine->id, 'receiver_number_id' => $alsoMine->id]);
        $back = Message::factory()->create(['sender_number_id' => $alsoMine->id, 'receiver_number_id' => $mine->id]);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame($out->conversation_id, $back->conversation_id);
        $this->assertCount(2, Conversation::first()->messages);
    }

    public function test_between_is_idempotent(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();

        $first = Conversation::between($a->id, $b->id);
        $second = Conversation::between($b->id, $a->id);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_a_reply_lands_in_the_same_conversation(): void
    {
        $senderNumber = Number::factory()->create();
        $receiverOwner = User::factory()->create();
        $receiverNumber = Number::factory()->for($receiverOwner)->create();
        $category = MessageCategory::factory()->create();
        $template = MessageTemplate::factory()->for($category, 'category')->create();
        $replyTemplate = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $message = Message::factory()->create([
            'sender_number_id' => $senderNumber->id,
            'receiver_number_id' => $receiverNumber->id,
            'template_id' => $template->id,
        ]);

        $reply = app(ReplyToMessage::class)($receiverOwner, $message, $replyTemplate);

        $this->assertSame($message->conversation_id, $reply->conversation_id);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_sending_through_the_action_advances_last_activity(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->create();
        $template = MessageTemplate::factory()
            ->for(MessageCategory::factory()->create(), 'category')->create();

        $message = app(SendMessage::class)($owner, [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        $conversation = Conversation::firstOrFail();
        $this->assertNotNull($conversation->last_message_at);
        $this->assertSame(
            $message->created_at->toDateTimeString(),
            $conversation->last_message_at->toDateTimeString()
        );
    }

    public function test_a_backdated_message_does_not_drag_last_activity_backwards(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();

        Message::factory()->create([
            'sender_number_id' => $a->id,
            'receiver_number_id' => $b->id,
            'created_at' => now(),
        ]);
        $latest = Conversation::firstOrFail()->last_message_at;

        Message::factory()->create([
            'sender_number_id' => $a->id,
            'receiver_number_id' => $b->id,
            'created_at' => now()->subDays(3),
        ]);

        $this->assertSame(
            $latest->toDateTimeString(),
            Conversation::firstOrFail()->fresh()->last_message_at->toDateTimeString()
        );
    }

    public function test_separate_own_numbers_talking_to_one_person_are_separate_threads(): void
    {
        $owner = User::factory()->create();
        $mineA = Number::factory()->for($owner)->create();
        $mineB = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        Message::factory()->create(['sender_number_id' => $theirs->id, 'receiver_number_id' => $mineA->id]);
        Message::factory()->create(['sender_number_id' => $theirs->id, 'receiver_number_id' => $mineB->id]);

        $this->assertDatabaseCount('conversations', 2);
    }

    public function test_counterpart_and_my_number_resolve_from_the_viewers_side(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();
        Message::factory()->create(['sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id]);

        $conversation = Conversation::firstOrFail();
        $accessible = $owner->accessibleNumberIds();

        $this->assertSame($mine->id, $conversation->myNumberFor($accessible)->id);
        $this->assertSame($theirs->id, $conversation->counterpartFor($accessible)->id);
        $this->assertTrue($conversation->isAccessibleBy($owner));
        $this->assertFalse($conversation->isAccessibleBy(User::factory()->create()));
    }
}
