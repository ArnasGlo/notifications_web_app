<?php

namespace Tests\Feature;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Message::replyRefusal() is the one answer to "may this viewer reply to this
 * message?": ReplyToMessage throws what it returns, and a Reply action is shown
 * only where it returns null.
 */
class MessageReplyEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_and_an_assistant_of_the_receiving_number_may_reply(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $mine->id, 'assistant_user_id' => $assistant->id]);
        $message = Message::factory()->create(['receiver_number_id' => $mine->id]);

        $this->assertNull($message->replyRefusal($owner->accessibleNumberIds()));
        $this->assertTrue($message->canBeRepliedToFrom($assistant->accessibleNumberIds()));
    }

    public function test_the_sending_side_and_unrelated_viewers_are_refused_with_403(): void
    {
        $sender = User::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => Number::factory()->for($sender)->create()->id,
        ]);

        foreach ([$sender->accessibleNumberIds(), User::factory()->create()->accessibleNumberIds()] as $ids) {
            $this->assertSame(403, $message->replyRefusal($ids)?->status);
            $this->assertFalse($message->canBeRepliedToFrom($ids));
        }
    }

    public function test_a_reply_cannot_be_replied_to(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $original = Message::factory()->create(['sender_number_id' => $mine->id]);
        $reply = Message::factory()->reply($original->id)->create([
            'sender_number_id' => $original->receiver_number_id,
            'receiver_number_id' => $mine->id,
        ]);

        // Inbound to the owner, but one level deep is still the limit.
        $refusal = $reply->replyRefusal($owner->accessibleNumberIds());

        $this->assertSame(422, $refusal?->status);
        $this->assertSame('You cannot reply to a reply.', $refusal->getMessage());
    }

    public function test_earlier_replies_do_not_make_a_message_ineligible(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $message = Message::factory()->create(['receiver_number_id' => $mine->id]);

        Message::factory()->reply($message->id)->count(2)->create([
            'sender_number_id' => $mine->id,
            'receiver_number_id' => $message->sender_number_id,
        ]);

        $this->assertTrue($message->canBeRepliedToFrom($owner->accessibleNumberIds()));
    }
}
