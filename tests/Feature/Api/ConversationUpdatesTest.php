<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The incremental-update endpoints an Android client polls (and would fetch on
 * a push notification later): new messages after a cursor, and threads that
 * moved since a server timestamp.
 */
class ConversationUpdatesTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/conversations/{id}/messages ─────────────────────────────

    public function test_new_messages_require_authentication(): void
    {
        $message = Message::factory()->create();

        $this->getJson("/api/conversations/{$message->conversation_id}/messages")
            ->assertStatus(401);
    }

    public function test_a_stranger_cannot_read_new_messages_in_a_thread(): void
    {
        $stranger = User::factory()->create();
        $message = Message::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/conversations/{$message->conversation_id}/messages")
            ->assertStatus(403);
    }

    public function test_an_assistant_can_read_new_messages_on_a_delegated_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $theirs = Number::factory()->for($owner)->create();
        $outside = Number::factory()->create();

        Delegate::create(['number_id' => $theirs->id, 'assistant_user_id' => $assistant->id]);

        $message = Message::factory()->create([
            'sender_number_id' => $outside->id, 'receiver_number_id' => $theirs->id,
        ]);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/conversations/{$message->conversation_id}/messages")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $message->id);
    }

    public function test_only_messages_after_the_cursor_are_returned(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $old = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'body' => 'Old',
        ]);
        $new = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'body' => 'New',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$old->conversation_id}/messages?after_id={$old->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.body', 'New');
    }

    public function test_without_a_cursor_the_whole_thread_comes_back_oldest_first(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $first = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'body' => 'First',
        ]);
        $second = Message::factory()->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id, 'body' => 'Second',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$first->conversation_id}/messages")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    public function test_the_response_carries_a_server_time_to_sync_against(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$message->conversation_id}/messages")
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['server_time']]);
    }

    public function test_a_bad_cursor_is_rejected(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $message = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$message->conversation_id}/messages?after_id=-5")
            ->assertStatus(422)
            ->assertJsonValidationErrors('after_id');
    }

    public function test_fetching_new_messages_marks_inbound_ones_read(): void
    {
        [$owner, $mine, $theirs] = $this->thread();

        $inbound = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'status' => 'sent',
        ]);
        $outbound = Message::factory()->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id, 'status' => 'sent',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/conversations/{$inbound->conversation_id}/messages")
            ->assertStatus(200);

        $this->assertSame('read', $inbound->refresh()->status);
        $this->assertNotNull($inbound->read_at);

        // The sender's own message is not "read" just because they looked at it.
        $this->assertSame('sent', $outbound->refresh()->status);
        $this->assertNull($outbound->read_at);
    }

    // ── GET /api/conversations/updates ───────────────────────────────────

    public function test_conversation_updates_require_authentication(): void
    {
        $this->getJson('/api/conversations/updates?since='.urlencode(now()->toIso8601String()))
            ->assertStatus(401);
    }

    public function test_since_is_required(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations/updates')
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');
    }

    public function test_only_threads_that_moved_are_returned(): void
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
            'sender_number_id' => $busy->id, 'receiver_number_id' => $mine->id, 'body' => 'Fresh',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations/updates?since='.urlencode($since->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.number', '+37060000222')
            ->assertJsonPath('data.0.last_message.body', 'Fresh')
            ->assertJsonPath('data.0.unread_count', 1)
            ->assertJsonStructure(['data', 'meta' => ['server_time']]);
    }

    public function test_updates_never_include_a_thread_between_strangers(): void
    {
        [$owner] = $this->thread();

        $a = Number::factory()->create();
        $b = Number::factory()->create();
        Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations/updates?since='.urlencode(now()->subHour()->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_updates_do_not_n_plus_one_over_threads(): void
    {
        [$owner, $mine] = $this->thread();

        Message::factory()->create([
            'sender_number_id' => Number::factory()->create()->id, 'receiver_number_id' => $mine->id,
        ]);

        $url = '/api/conversations/updates?since='.urlencode(now()->subHour()->toIso8601String());

        $one = $this->countQueries(fn () => $this->actingAs($owner, 'sanctum')->getJson($url)->assertJsonCount(1, 'data'));

        foreach (range(1, 3) as $i) {
            Message::factory()->create([
                'sender_number_id' => Number::factory()->create()->id, 'receiver_number_id' => $mine->id,
            ]);
        }

        $many = $this->countQueries(fn () => $this->actingAs($owner, 'sanctum')->getJson($url)->assertJsonCount(4, 'data'));

        $this->assertSame($one, $many, 'Four threads must cost the same as one.');
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
