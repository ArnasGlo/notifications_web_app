<?php

namespace Tests\Feature;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The polling endpoints behind the auto-updating chat page and message list.
 * Authorization is the same isAccessibleBy() check the pages themselves use.
 */
class ConversationPollingWebTest extends TestCase
{
    use RefreshDatabase;

    // ── access control ───────────────────────────────────────────────────

    public function test_a_guest_is_redirected_from_the_thread_poll(): void
    {
        $message = Message::factory()->create();

        $this->get(route('conversations.updates', $message->conversation_id))
            ->assertRedirect(route('login'));
    }

    public function test_a_stranger_cannot_poll_someone_elses_thread(): void
    {
        $stranger = User::factory()->create();
        $message = Message::factory()->create();

        $this->actingAs($stranger)
            ->getJson(route('conversations.updates', $message->conversation_id))
            ->assertStatus(403);
    }

    public function test_an_assistant_can_poll_a_thread_on_a_delegated_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $theirs = Number::factory()->for($owner)->create();
        $outside = Number::factory()->create();

        Delegate::create(['number_id' => $theirs->id, 'assistant_user_id' => $assistant->id]);

        $message = Message::factory()->create([
            'sender_number_id' => $outside->id,
            'receiver_number_id' => $theirs->id,
            'body' => 'Visible to the assistant',
        ]);

        $this->actingAs($assistant)
            ->getJson(route('conversations.updates', $message->conversation_id))
            ->assertStatus(200)
            ->assertJsonPath('messages.0.id', $message->id);
    }

    // ── page wiring ──────────────────────────────────────────────────────

    public function test_the_chat_page_starts_polling_from_the_newest_message(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->get(route('conversations.show', $message->conversation_id))
            ->assertStatus(200)
            ->assertSee('startPolling', false)
            ->assertSee('data-last-id="'.$message->id.'"', false)
            ->assertSee($this->jsUrl(route('conversations.updates', $message->conversation_id)), false);
    }

    public function test_the_messages_page_starts_polling_the_list(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->get(route('messages.index'))
            ->assertStatus(200)
            ->assertSee('startPolling', false)
            ->assertSee('id="conversationList"', false)
            ->assertSee($this->jsUrl(route('messages.updates')), false);
    }

    public function test_the_per_number_inbox_polls_scoped_to_that_number(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->get(route('numbers.messages', $mine))
            ->assertStatus(200)
            ->assertSee('startPolling', false)
            ->assertSee('number_id: '.$mine->id, false);
    }

    // ── the message cursor ───────────────────────────────────────────────

    public function test_only_messages_newer_than_the_cursor_are_returned(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $old = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'body' => 'Already on screen',
        ]);
        $new = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'body' => 'Arrived since',
        ]);

        $response = $this->actingAs($owner)->getJson(
            route('conversations.updates', $old->conversation_id).'?after_id='.$old->id
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $new->id)
            ->assertJsonPath('last_id', $new->id);

        $this->assertStringContainsString('Arrived since', $response->json('messages.0.html'));
    }

    public function test_no_new_messages_returns_an_empty_list_and_keeps_the_cursor(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('conversations.updates', $message->conversation_id).'?after_id='.$message->id)
            ->assertStatus(200)
            ->assertJsonCount(0, 'messages')
            ->assertJsonPath('last_id', $message->id);
    }

    public function test_the_poll_rejects_a_non_numeric_cursor(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('conversations.updates', $message->conversation_id).'?after_id=abc')
            ->assertStatus(422);
    }

    // ── read state ───────────────────────────────────────────────────────

    public function test_polling_marks_inbound_messages_read(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $inbound = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'status' => 'sent',
        ]);

        $this->actingAs($owner)
            ->getJson(route('conversations.updates', $inbound->conversation_id))
            ->assertStatus(200);

        $inbound->refresh();
        $this->assertSame('read', $inbound->status);
        $this->assertNotNull($inbound->read_at);
    }

    public function test_polling_does_not_mark_the_viewers_own_outbound_messages_read(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $outbound = Message::factory()->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id, 'status' => 'sent',
        ]);

        $this->actingAs($owner)
            ->getJson(route('conversations.updates', $outbound->conversation_id))
            ->assertStatus(200);

        $outbound->refresh();
        $this->assertSame('sent', $outbound->status);
        $this->assertNull($outbound->read_at);
    }

    public function test_polling_leaves_queued_messages_queued(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $queued = Message::factory()->queued()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->getJson(route('conversations.updates', $queued->conversation_id))
            ->assertStatus(200);

        $this->assertSame('queued', $queued->refresh()->status);
    }

    // ── the list poll ────────────────────────────────────────────────────

    public function test_the_list_poll_returns_only_threads_that_changed(): void
    {
        [$owner, $mine] = $this->thread();
        $quiet = Number::factory()->create(['number' => '+37060000111']);
        $busy = Number::factory()->create(['number' => '+37060000222']);

        Message::factory()->create([
            'sender_number_id' => $quiet->id, 'receiver_number_id' => $mine->id,
            'created_at' => now()->subDay(),
        ]);

        $since = now()->subHour();

        Message::factory()->create([
            'sender_number_id' => $busy->id, 'receiver_number_id' => $mine->id,
            'body' => 'Just arrived',
        ]);

        $response = $this->actingAs($owner)->getJson(
            route('messages.updates').'?since='.urlencode($since->toIso8601String())
        );

        $response->assertStatus(200)->assertJsonCount(1, 'conversations');
        $this->assertStringContainsString('+37060000222', $response->json('conversations.0.html'));
        $this->assertNotNull($response->json('server_time'));
    }

    public function test_the_list_poll_cannot_reach_a_thread_between_strangers(): void
    {
        [$owner] = $this->thread();

        // Two numbers with nothing to do with the viewer.
        $a = Number::factory()->create();
        $b = Number::factory()->create();
        Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);

        $this->actingAs($owner)
            ->getJson(route('messages.updates').'?since='.urlencode(now()->subHour()->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonCount(0, 'conversations');
    }

    public function test_the_list_poll_requires_a_since_timestamp(): void
    {
        [$owner] = $this->thread();

        $this->actingAs($owner)
            ->getJson(route('messages.updates'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');
    }

    public function test_the_list_poll_refuses_a_number_the_viewer_cannot_access(): void
    {
        [$owner] = $this->thread();
        $notMine = Number::factory()->create();

        $this->actingAs($owner)->getJson(
            route('messages.updates')
            .'?since='.urlencode(now()->subHour()->toIso8601String())
            .'&number_id='.$notMine->id
        )->assertStatus(403);
    }

    public function test_the_list_poll_honours_the_exact_number_filter(): void
    {
        [$owner, $mine] = $this->thread();
        $wanted = Number::factory()->create(['number' => '+37086417999']);
        $other = Number::factory()->create(['number' => '+370864179']);

        Message::factory()->create(['sender_number_id' => $wanted->id, 'receiver_number_id' => $mine->id]);
        Message::factory()->create(['sender_number_id' => $other->id, 'receiver_number_id' => $mine->id]);

        $response = $this->actingAs($owner)->getJson(
            route('messages.updates')
            .'?since='.urlencode(now()->subHour()->toIso8601String())
            .'&q=%2B37086417999'
        );

        $response->assertStatus(200)->assertJsonCount(1, 'conversations');
        $this->assertStringContainsString('+37086417999', $response->json('conversations.0.html'));
    }

    // ── query cost ───────────────────────────────────────────────────────

    public function test_the_thread_poll_costs_the_same_whatever_the_message_count(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $first = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);
        $url = route('conversations.updates', $first->conversation_id);

        $one = $this->countQueries(fn () => $this->actingAs($owner)->getJson($url)->assertStatus(200));

        Message::factory()->count(4)->create([
            'conversation_id' => $first->conversation_id,
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $many = $this->countQueries(fn () => $this->actingAs($owner)->getJson($url)->assertStatus(200));

        $this->assertSame($one, $many, 'Rendering more bubbles must not cost more queries.');
    }

    public function test_the_list_poll_costs_the_same_whatever_the_thread_count(): void
    {
        [$owner, $mine] = $this->thread();

        Message::factory()->create([
            'sender_number_id' => Number::factory()->create()->id, 'receiver_number_id' => $mine->id,
        ]);

        $url = route('messages.updates').'?since='.urlencode(now()->subHour()->toIso8601String());

        $one = $this->countQueries(fn () => $this->actingAs($owner)->getJson($url)->assertJsonCount(1, 'conversations'));

        foreach (range(1, 3) as $i) {
            Message::factory()->create([
                'sender_number_id' => Number::factory()->create()->id, 'receiver_number_id' => $mine->id,
            ]);
        }

        $many = $this->countQueries(fn () => $this->actingAs($owner)->getJson($url)->assertJsonCount(4, 'conversations'));

        $this->assertSame($one, $many, 'Rendering more rows must not cost more queries.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Number, 2: Number} */
    private function thread(): array
    {
        $owner = User::factory()->create();

        return [
            $owner,
            Number::factory()->for($owner)->create(),
            Number::factory()->create(),
        ];
    }

    /** Blade's @json escapes slashes, so a URL in the page HTML looks like this. */
    private function jsUrl(string $url): string
    {
        return str_replace('/', '\/', $url);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
