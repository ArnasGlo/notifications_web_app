<?php

namespace Tests\Feature;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationWebTest extends TestCase
{
    use RefreshDatabase;

    // ── the Messages page ────────────────────────────────────────────────

    public function test_the_messages_page_lists_one_row_per_thread(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create(['number' => '+37060011111']);

        // Three messages with one counterpart must collapse into a single row.
        Message::factory()->count(3)->create([
            'sender_number_id' => $theirs->id,
            'receiver_number_id' => $mine->id,
            'body' => 'Repeated body',
        ]);

        $response = $this->actingAs($owner)->get(route('messages.index'));

        $response->assertStatus(200)->assertSee('+37060011111');
        $this->assertCount(1, $response->viewData('conversations'));
    }

    public function test_the_row_shows_the_latest_message_and_unread_count(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
            'body' => 'Older message', 'status' => 'sent', 'created_at' => now()->subHour(),
        ]);
        Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
            'body' => 'Newest message', 'status' => 'sent', 'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('messages.index'))
            ->assertStatus(200)
            ->assertSee('Newest message')
            ->assertDontSee('Older message')
            ->assertSee('>2<', false);      // unread badge
    }

    public function test_threads_are_ordered_by_latest_activity(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $stale = Number::factory()->create(['number' => '+37060000111']);
        $fresh = Number::factory()->create(['number' => '+37060000222']);

        Message::factory()->create([
            'sender_number_id' => $stale->id, 'receiver_number_id' => $mine->id,
            'created_at' => now()->subDays(3),
        ]);
        Message::factory()->create([
            'sender_number_id' => $fresh->id, 'receiver_number_id' => $mine->id,
            'created_at' => now(),
        ]);

        $content = $this->actingAs($owner)->get(route('messages.index'))->getContent();

        $this->assertLessThan(
            strpos($content, '+37060000111'),
            strpos($content, '+37060000222'),
            'The most recently active thread should come first.'
        );
    }

    public function test_the_quick_jump_dropdown_lists_the_viewers_threads(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create(['number' => '+37060033333']);
        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->get(route('messages.index'))
            ->assertStatus(200)
            ->assertSee('conversationJump', false)
            ->assertSee(route('conversations.show', $message->conversation_id), false);
    }

    // ── the chat page ────────────────────────────────────────────────────

    public function test_the_chat_page_shows_the_full_history_including_replies(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        $first = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
            'body' => 'Inbound opener', 'created_at' => now()->subHours(2),
        ]);
        Message::factory()->reply($first->id)->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id,
            'body' => 'My threaded reply', 'created_at' => now()->subHour(),
        ]);

        $this->actingAs($owner)
            ->get(route('conversations.show', $first->conversation_id))
            ->assertStatus(200)
            ->assertSee('Inbound opener')
            ->assertSee('My threaded reply');   // hidden from the old flat inbox
    }

    public function test_opening_the_chat_page_marks_inbound_messages_read(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        $inbound = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'status' => 'sent',
        ]);
        $outbound = Message::factory()->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id, 'status' => 'sent',
        ]);

        $this->actingAs($owner)
            ->get(route('conversations.show', $inbound->conversation_id))
            ->assertStatus(200);

        $this->assertDatabaseHas('messages', ['id' => $inbound->id, 'status' => 'read']);
        $this->assertDatabaseHas('messages', ['id' => $outbound->id, 'status' => 'sent']);
    }

    public function test_the_chat_page_is_forbidden_for_someone_outside_the_thread(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();
        $message = Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('conversations.show', $message->conversation_id))
            ->assertStatus(403);
    }

    public function test_a_delegate_can_open_a_thread_for_a_number_they_assist(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $theirs = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $number->id,
        ]);

        $this->actingAs($assistant)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertStatus(200);
    }

    public function test_the_chat_composer_sends_into_the_same_thread(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();
        $existing = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $response = $this->actingAs($owner)
            ->from(route('conversations.show', $existing->conversation_id))
            ->post(route('messages.store'), [
                'sender_number_id' => $mine->id,
                'receiver_number_id' => $theirs->id,
                'body' => 'Sent from the thread',
            ]);

        $response->assertRedirect(route('conversations.show', $existing->conversation_id));
        $this->assertDatabaseHas('messages', [
            'body' => 'Sent from the thread',
            'conversation_id' => $existing->conversation_id,
        ]);
    }

    // ── per-number list ──────────────────────────────────────────────────

    public function test_the_number_page_lists_that_numbers_threads_only(): void
    {
        $owner = User::factory()->create();
        $numberA = Number::factory()->for($owner)->create();
        $numberB = Number::factory()->for($owner)->create();
        $onA = Number::factory()->create(['number' => '+37060044444']);
        $onB = Number::factory()->create(['number' => '+37060055555']);

        Message::factory()->create(['sender_number_id' => $onA->id, 'receiver_number_id' => $numberA->id]);
        Message::factory()->create(['sender_number_id' => $onB->id, 'receiver_number_id' => $numberB->id]);

        $this->actingAs($owner)
            ->get(route('numbers.messages', $numberA))
            ->assertStatus(200)
            ->assertSee('+37060044444')
            ->assertDontSee('+37060055555');
    }

    public function test_the_number_page_is_forbidden_for_an_unrelated_user(): void
    {
        $number = Number::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('numbers.messages', $number))
            ->assertStatus(403);
    }
}
