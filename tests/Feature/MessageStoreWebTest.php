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
 * ANDROID_APP_CONTEXT.md §2 says inactive numbers can't send or receive, but the web
 * app only enforced it by omitting inactive numbers from the compose picker and the
 * lookup endpoint. Both MessageController@store methods now check it server-side;
 * these tests pin the web half.
 */
class MessageStoreWebTest extends TestCase
{
    use RefreshDatabase;

    private function template(): MessageTemplate
    {
        return MessageTemplate::factory()
            ->for(MessageCategory::factory()->create(), 'category')
            ->create();
    }

    private function send(User $as, Number $sender, Number $receiver)
    {
        return $this->actingAs($as)
            ->from(route('messages.compose'))
            ->post(route('messages.store'), [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $this->template()->id,
            ]);
    }

    public function test_an_active_pair_still_sends_and_lands_in_the_thread(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->create();

        $response = $this->send($owner, $sender, $receiver);

        $this->assertDatabaseCount('messages', 1);
        $message = Message::firstOrFail();
        $response->assertRedirect(route('conversations.show', $message->conversation_id));
    }

    public function test_an_inactive_sender_is_rejected(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->inactive()->create();
        $receiver = Number::factory()->create();

        $this->send($owner, $sender, $receiver)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_an_inactive_receiver_is_rejected(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->inactive()->create();

        $this->send($owner, $sender, $receiver)->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 0);
    }
}
