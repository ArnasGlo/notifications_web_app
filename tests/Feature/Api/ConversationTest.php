<?php

namespace Tests\Feature\Api;

use App\Models\Conversation;
use App\Models\Delegate;
use App\Models\Message;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    // ── index ────────────────────────────────────────────────────────────

    public function test_index_returns_one_row_per_thread_with_the_viewers_view_of_it(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create(['number' => '+37060000001']);
        $theirs = Number::factory()->create(['number' => '+37060011111']);

        Message::factory()->create([
            'sender_number_id' => $theirs->id,
            'receiver_number_id' => $mine->id,
            'body' => 'First',
        ]);
        Message::factory()->create([
            'sender_number_id' => $mine->id,
            'receiver_number_id' => $theirs->id,
            'body' => 'Latest of the two',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/conversations');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.number', '+37060011111')
            ->assertJsonPath('data.0.my_number.number', '+37060000001')
            ->assertJsonPath('data.0.last_message.body', 'Latest of the two')
            ->assertJsonPath('data.0.last_message.is_outbound', true);
    }

    public function test_index_counts_only_inbound_unread_messages(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        Message::factory()->count(2)->create([
            'sender_number_id' => $theirs->id,
            'receiver_number_id' => $mine->id,
            'status' => 'sent',
        ]);
        // Outbound and already-read messages must not count.
        Message::factory()->create(['sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id, 'status' => 'sent']);
        Message::factory()->create(['sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id, 'status' => 'read']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations')
            ->assertStatus(200)
            ->assertJsonPath('data.0.unread_count', 2);
    }

    public function test_index_is_ordered_by_latest_activity(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $older = Number::factory()->create();
        $newer = Number::factory()->create();

        Message::factory()->create([
            'sender_number_id' => $older->id, 'receiver_number_id' => $mine->id,
            'created_at' => now()->subDays(2),
        ]);
        Message::factory()->create([
            'sender_number_id' => $newer->id, 'receiver_number_id' => $mine->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/conversations');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertSame($newer->id, $response->json('data.0.counterpart.id'));
        $this->assertSame($older->id, $response->json('data.1.counterpart.id'));
    }

    public function test_index_excludes_threads_the_viewer_is_not_part_of(): void
    {
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();
        $a = Number::factory()->create();
        $b = Number::factory()->create();
        Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_a_delegate_sees_threads_for_the_number_they_assist(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $theirs = Number::factory()->create();
        Message::factory()->create(['sender_number_id' => $theirs->id, 'receiver_number_id' => $number->id]);

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/conversations')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.id', $theirs->id);
    }

    public function test_index_can_be_filtered_to_one_number(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alice = Number::factory()->create(['number' => '+37060011111']);
        $bob = Number::factory()->create(['number' => '+37060022222']);
        Message::factory()->create(['sender_number_id' => $alice->id, 'receiver_number_id' => $mine->id]);
        Message::factory()->create(['sender_number_id' => $bob->id, 'receiver_number_id' => $mine->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations?q='.urlencode('+37060011111'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.counterpart.id', $alice->id);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/conversations')->assertStatus(401);
    }

    // ── show ─────────────────────────────────────────────────────────────

    public function test_show_returns_the_whole_thread_including_replies_in_order(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $theirs = Number::factory()->create();

        $first = Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
            'body' => 'Oldest', 'created_at' => now()->subHours(2),
        ]);
        // A reply — invisible in the flat inbox, which filters parent_id IS NULL.
        Message::factory()->reply($first->id)->create([
            'sender_number_id' => $mine->id, 'receiver_number_id' => $theirs->id,
            'body' => 'Middle reply', 'created_at' => now()->subHour(),
        ]);
        Message::factory()->create([
            'sender_number_id' => $theirs->id, 'receiver_number_id' => $mine->id,
            'body' => 'Newest', 'created_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations/'.$first->conversation_id);

        $response->assertStatus(200)->assertJsonCount(3, 'data');
        $this->assertSame(
            ['Oldest', 'Middle reply', 'Newest'],
            collect($response->json('data'))->pluck('body')->all()
        );
    }

    public function test_show_marks_inbound_messages_read_but_leaves_outbound_alone(): void
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

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/conversations/'.$inbound->conversation_id)
            ->assertStatus(200);

        $this->assertDatabaseHas('messages', ['id' => $inbound->id, 'status' => 'read']);
        $this->assertDatabaseHas('messages', ['id' => $outbound->id, 'status' => 'sent', 'read_at' => null]);
    }

    public function test_show_is_forbidden_for_someone_outside_the_thread(): void
    {
        $a = Number::factory()->create();
        $b = Number::factory()->create();
        $message = Message::factory()->create(['sender_number_id' => $a->id, 'receiver_number_id' => $b->id]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/conversations/'.$message->conversation_id)
            ->assertStatus(403);
    }

    public function test_show_returns_404_for_an_unknown_conversation(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/conversations/999999')
            ->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $conversation = Conversation::between(
            Number::factory()->create()->id,
            Number::factory()->create()->id
        );

        $this->getJson('/api/conversations/'.$conversation->id)->assertStatus(401);
    }
}
