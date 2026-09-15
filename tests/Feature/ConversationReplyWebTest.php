<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Delegate;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replying to a specific message from the chat page (Slice 3): the composer's
 * reply mode posts to MessageController@store with a parent_id, which runs the
 * same ReplyToMessage action as the API.
 */
class ConversationReplyWebTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alice's number sends Bob's number "Can you talk?", a template with one
     * mapped answer, "On my way".
     */
    private function thread(array $alice = []): array
    {
        $aliceUser = User::factory()->create($alice);
        $bob = User::factory()->create();
        $aliceNumber = Number::factory()->for($aliceUser)->create();
        $bobNumber = Number::factory()->for($bob)->create();

        $category = MessageCategory::factory()->create(['name' => 'Meeting']);
        $prompt = MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);
        $answer = MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'On my way']);
        $prompt->replyTemplates()->attach($answer->id);

        $message = Message::factory()->create([
            'sender_number_id' => $aliceNumber->id,
            'receiver_number_id' => $bobNumber->id,
            'template_id' => $prompt->id,
        ]);

        return [
            'alice' => $aliceUser, 'bob' => $bob, 'aliceNumber' => $aliceNumber, 'bobNumber' => $bobNumber,
            'answer' => $answer, 'message' => $message,
        ];
    }

    private function reply(User $as, Message $to, array $fields)
    {
        return $this->actingAs($as)
            ->from(route('conversations.show', $to->conversation_id))
            ->post(route('messages.store'), ['parent_id' => $to->id] + $fields);
    }

    // ── Sending a reply ──────────────────────────────────────────────────────

    public function test_a_template_reply_from_the_chat_is_linked_to_its_message(): void
    {
        ['bob' => $bob, 'message' => $message, 'answer' => $answer] = $this->thread();

        // The composer inserts the template's text and records its id.
        $this->reply($bob, $message, ['template_id' => $answer->id, 'body' => 'On my way'])
            ->assertRedirect(route('conversations.show', $message->conversation_id))
            ->assertSessionHas('success', 'Reply sent!');

        $this->assertDatabaseHas('messages', [
            'parent_id' => $message->id,
            'template_id' => $answer->id,
            'body' => 'On my way',
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
        ]);
    }

    public function test_a_typed_reply_needs_no_sender_or_receiver_fields(): void
    {
        ['bob' => $bob, 'message' => $message] = $this->thread();

        $this->reply($bob, $message, ['body' => 'Give me ten minutes'])
            ->assertRedirect(route('conversations.show', $message->conversation_id));

        $this->assertDatabaseHas('messages', [
            'parent_id' => $message->id,
            'template_id' => null,
            'body' => 'Give me ten minutes',
            'sender_number_id' => $message->receiver_number_id,
        ]);
    }

    public function test_an_assistant_can_reply_from_the_web_but_still_cannot_start_a_message(): void
    {
        ['bobNumber' => $bobNumber, 'aliceNumber' => $aliceNumber, 'message' => $message] = $this->thread();
        $assistant = User::factory()->create();
        Delegate::create(['number_id' => $bobNumber->id, 'assistant_user_id' => $assistant->id]);

        $this->reply($assistant, $message, ['body' => 'Bob is out, I can help'])
            ->assertRedirect(route('conversations.show', $message->conversation_id));
        $this->assertDatabaseHas('messages', ['parent_id' => $message->id, 'body' => 'Bob is out, I can help']);

        $this->actingAs($assistant)
            ->post(route('messages.store'), [
                'sender_number_id' => $bobNumber->id,
                'receiver_number_id' => $aliceNumber->id,
                'body' => 'Starting something new',
            ])
            ->assertStatus(403);
    }

    public function test_the_sending_side_cannot_reply_to_its_own_message(): void
    {
        ['alice' => $alice, 'message' => $message] = $this->thread();

        $this->reply($alice, $message, ['body' => 'Replying to myself'])->assertStatus(403);

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_dnd_refuses_a_typed_reply_keeping_the_text_but_lets_a_template_reply_through(): void
    {
        ['bob' => $bob, 'message' => $message, 'answer' => $answer] = $this->thread(['status' => 'dnd']);

        $this->reply($bob, $message, ['body' => 'Are you there?'])
            ->assertRedirect(route('conversations.show', $message->conversation_id))
            ->assertSessionHas('error', 'This number cannot receive your message (blocked or DND).')
            ->assertSessionHasInput('body', 'Are you there?')
            ->assertSessionHasInput('parent_id', $message->id);
        $this->assertDatabaseCount('messages', 1);

        $this->reply($bob, $message, ['template_id' => $answer->id, 'body' => 'On my way'])
            ->assertSessionHas('success', 'Reply sent!');
    }

    public function test_a_block_refuses_a_typed_reply_but_lets_a_template_reply_through(): void
    {
        ['bob' => $bob, 'bobNumber' => $bobNumber, 'message' => $message, 'answer' => $answer] = $this->thread();
        Block::create(['number_id' => $message->sender_number_id, 'type' => 'number', 'value' => $bobNumber->number]);

        $this->reply($bob, $message, ['body' => 'Hello?'])
            ->assertSessionHas('error', 'This number cannot receive your message (blocked or DND).');
        $this->assertDatabaseCount('messages', 1);

        $this->reply($bob, $message, ['template_id' => $answer->id, 'body' => 'On my way'])
            ->assertSessionHas('success', 'Reply sent!');
    }

    public function test_replying_to_a_reply_or_with_an_unmapped_template_is_refused_with_the_reason(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'message' => $message] = $this->thread();
        $aliceReply = Message::factory()->reply($message->id)->create([
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
        ]);
        $unmapped = MessageTemplate::factory()->reply()->create(['body' => 'Not for this']);

        $this->reply($alice, $aliceReply, ['body' => 'Deeper'])
            ->assertSessionHas('error', 'You cannot reply to a reply.');

        $this->reply($bob, $message, ['template_id' => $unmapped->id, 'body' => 'Not for this'])
            ->assertSessionHas('error', 'This template cannot be used as a reply to this message.');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_a_nonexistent_parent_fails_validation(): void
    {
        ['bob' => $bob] = $this->thread();

        $this->actingAs($bob)
            ->post(route('messages.store'), ['parent_id' => 999999, 'body' => 'Hello'])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        ['message' => $message] = $this->thread();

        $this->post(route('messages.store'), ['parent_id' => $message->id, 'body' => 'Hello'])
            ->assertRedirect(route('login'));
    }

    // ── The chat page ────────────────────────────────────────────────────────

    public function test_the_reply_action_appears_only_on_messages_the_viewer_can_answer(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'message' => $message] = $this->thread();
        $bobsOwn = Message::factory()->create([
            'sender_number_id' => $message->receiver_number_id,
            'receiver_number_id' => $message->sender_number_id,
        ]);
        $replyToBob = Message::factory()->reply($bobsOwn->id)->create([
            'sender_number_id' => $message->sender_number_id,
            'receiver_number_id' => $message->receiver_number_id,
        ]);

        $this->actingAs($bob)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertStatus(200)
            ->assertSee('data-reply-to="'.$message->id.'"', false)
            // The options travel with the action, for the "/" menu.
            ->assertSee('"body":"On my way"')
            ->assertDontSee('data-reply-to="'.$bobsOwn->id.'"', false)
            ->assertDontSee('data-reply-to="'.$replyToBob->id.'"', false)
            // And the reply says what it answers.
            ->assertSee('data-in-reply-to="'.$bobsOwn->id.'"', false);

        $this->actingAs($alice)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertDontSee('data-reply-to="'.$message->id.'"', false)
            ->assertSee('data-reply-to="'.$bobsOwn->id.'"', false);
    }

    public function test_bubbles_delivered_by_polling_carry_the_reply_action(): void
    {
        ['bob' => $bob, 'message' => $message] = $this->thread();

        $html = collect($this->actingAs($bob)
            ->getJson(route('conversations.updates', $message->conversation_id).'?after_id=0')
            ->assertStatus(200)
            ->json('messages'))->pluck('html')->implode('');

        $this->assertStringContainsString('data-reply-to="'.$message->id.'"', $html);
    }

    public function test_an_assistant_gets_a_reply_only_composer_and_an_owner_does_not(): void
    {
        ['bob' => $bob, 'bobNumber' => $bobNumber, 'message' => $message] = $this->thread();
        $assistant = User::factory()->create();
        Delegate::create(['number_id' => $bobNumber->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertSee('data-reply-only="1"', false)
            ->assertSee('data-reply-to="'.$message->id.'"', false);

        $this->actingAs($bob)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertSee('data-reply-only="0"', false);
    }

    public function test_the_compose_page_has_no_reply_mode(): void
    {
        ['bob' => $bob] = $this->thread();

        $this->actingAs($bob)
            ->get(route('messages.compose'))
            ->assertStatus(200)
            ->assertSee('composerBody', false)
            // The shared script looks for the field and bows out; the field itself is absent.
            ->assertDontSee('name="parent_id"', false)
            ->assertDontSee('id="composerReplyBanner"', false);
    }

    public function test_reply_mode_reopens_after_a_bounced_send_only_while_the_message_is_still_eligible(): void
    {
        ['bob' => $bob, 'message' => $message] = $this->thread();
        $url = route('conversations.show', $message->conversation_id);

        $this->actingAs($bob)
            ->withSession(['_old_input' => ['parent_id' => $message->id, 'body' => 'Half-typed']])
            ->get($url)
            ->assertSee('id="composerParentId" value="'.$message->id.'"', false)
            ->assertSee('Replying to:');

        // A message that can't be replied to from here — a reply, or one from
        // another conversation — isn't reopened, however it got into the input.
        $replyToBob = Message::factory()->reply($message->id)->create([
            'sender_number_id' => $message->sender_number_id,
            'receiver_number_id' => $message->receiver_number_id,
        ]);
        $elsewhere = Message::factory()->create(['receiver_number_id' => $message->receiver_number_id]);

        foreach ([$replyToBob, $elsewhere] as $stale) {
            $this->actingAs($bob)
                ->withSession(['_old_input' => ['parent_id' => $stale->id]])
                ->get($url)
                ->assertSee('id="composerParentId" value=""', false);
        }
    }
}
