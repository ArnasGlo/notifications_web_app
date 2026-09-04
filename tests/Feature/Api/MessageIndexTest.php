<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_a_top_level_message_with_the_full_resource_shape(): void
    {
        $owner = User::factory()->create();
        $myNumber = Number::factory()->for($owner)->create();
        $otherNumber = Number::factory()->create();
        $category = MessageCategory::factory()->create(['name' => 'Meeting', 'icon' => 'fas fa-calendar']);
        $template = MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);
        $message = Message::factory()->create([
            'sender_number_id' => $otherNumber->id,
            'receiver_number_id' => $myNumber->id,
            'template_id' => $template->id,
            'status' => 'sent',
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.status', 'sent')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.0.sender.id', $otherNumber->id)
            ->assertJsonPath('data.0.sender.number', $otherNumber->number)
            ->assertJsonPath('data.0.sender.user_id', $otherNumber->user_id)
            ->assertJsonPath('data.0.receiver.id', $myNumber->id)
            ->assertJsonPath('data.0.receiver.number', $myNumber->number)
            ->assertJsonPath('data.0.receiver.user_id', $owner->id)
            ->assertJsonPath('data.0.template.id', $template->id)
            ->assertJsonPath('data.0.template.body', 'Can you talk?')
            ->assertJsonPath('data.0.template.category.id', $category->id)
            ->assertJsonPath('data.0.template.category.name', 'Meeting')
            ->assertJsonPath('data.0.template.category.icon', 'fas fa-calendar');

        $response->assertJsonMissingPath('data.0.is_sender')
            ->assertJsonMissingPath('data.0.is_receiver')
            ->assertJsonMissingPath('data.0.is_unread');
    }

    public function test_index_includes_messages_for_a_delegated_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);
        $sender = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $number->id,
        ]);

        $response = $this->actingAs($assistant, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)->assertJsonPath('data.0.id', $message->id);
    }

    public function test_index_excludes_replies_but_keeps_the_parent(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $parent = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $number->id,
            'receiver_number_id' => $other->id,
            'parent_id' => $parent->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $parent->id);
    }

    public function test_index_excludes_messages_unrelated_to_the_user(): void
    {
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();
        $unrelatedA = Number::factory()->create();
        $unrelatedB = Number::factory()->create();
        Message::factory()->create([
            'sender_number_id' => $unrelatedA->id,
            'receiver_number_id' => $unrelatedB->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_index_orders_newest_first(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        $older = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'created_at' => now()->subMinutes(10),
        ]);
        $newer = Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_index_paginates_at_twenty_per_page(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $other = Number::factory()->create();
        Message::factory()->count(25)->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $number->id,
        ]);

        $page1 = $this->actingAs($owner, 'sanctum')->getJson('/api/messages');

        $page1->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1);

        $page2 = $this->actingAs($owner, 'sanctum')->getJson('/api/messages?page=2');

        $page2->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_returns_an_empty_list_when_no_messages(): void
    {
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages')
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    // ── ?q= counterpart filter ───────────────────────────────────────────

    /** @return array{0: User, 1: Number, 2: Number, 3: Number} */
    private function inboxWithTwoCounterparts(): array
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create(['number' => '+37060000001']);
        $alice = Number::factory()->create(['number' => '+37060011111']);
        $bob = Number::factory()->create(['number' => '+37060022222']);

        return [$owner, $mine, $alice, $bob];
    }

    public function test_index_filters_to_the_conversation_with_one_counterpart(): void
    {
        [$owner, $mine, $alice, $bob] = $this->inboxWithTwoCounterparts();
        $fromAlice = Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $bob->id,
            'receiver_number_id' => $mine->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q='.urlencode('+37060011111'));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromAlice->id);
    }

    public function test_the_filter_matches_both_directions_of_a_conversation(): void
    {
        [$owner, $mine, $alice] = $this->inboxWithTwoCounterparts();
        $inbound = Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
        ]);
        $outbound = Message::factory()->create([
            'sender_number_id' => $mine->id,
            'receiver_number_id' => $alice->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q='.urlencode('+37060011111'));

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inbound->id));
        $this->assertTrue($ids->contains($outbound->id));
    }

    public function test_the_filter_ignores_a_number_that_merely_contains_the_query(): void
    {
        // Real data shape from the dev DB: '+370864179' is a literal prefix of
        // '+37086417999'. A substring match returned both, so filtering by the
        // shorter number looked like it did nothing at all.
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create(['number' => '+37060000001']);
        $short = Number::factory()->create(['number' => '+370864179']);
        $long = Number::factory()->create(['number' => '+37086417999']);

        $withShort = Message::factory()->create([
            'sender_number_id' => $short->id,
            'receiver_number_id' => $mine->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $long->id,
            'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q='.urlencode('+370864179'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withShort->id);
    }

    public function test_a_non_numeric_number_is_matched_literally(): void
    {
        // numbers.number is an arbitrary unique string, not necessarily digits —
        // the dev DB really contains 'kazkas1'. Normalising the query to digits
        // reduced it to '1', which then matched nearly every number.
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create(['number' => '+37060000001']);
        $named = Number::factory()->create(['number' => 'kazkas1']);
        $other = Number::factory()->create(['number' => '+37060011111']);

        $withNamed = Message::factory()->create([
            'sender_number_id' => $named->id,
            'receiver_number_id' => $mine->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $other->id,
            'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q=kazkas1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withNamed->id);
    }

    public function test_a_blank_query_returns_the_unfiltered_inbox(): void
    {
        [$owner, $mine, $alice, $bob] = $this->inboxWithTwoCounterparts();
        Message::factory()->create(['sender_number_id' => $alice->id, 'receiver_number_id' => $mine->id]);
        Message::factory()->create(['sender_number_id' => $bob->id, 'receiver_number_id' => $mine->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q=')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_a_query_matching_no_number_returns_nothing(): void
    {
        // Including a bare LIKE wildcard, which must not behave as "match all".
        [$owner, $mine, $alice] = $this->inboxWithTwoCounterparts();
        Message::factory()->create(['sender_number_id' => $alice->id, 'receiver_number_id' => $mine->id]);

        foreach (['%', 'nobody', '1111'] as $q) {
            $this->actingAs($owner, 'sanctum')
                ->getJson('/api/messages?q='.urlencode($q))
                ->assertStatus(200)
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_the_filter_cannot_reach_messages_between_two_strangers(): void
    {
        // The accessibility scope stays applied unconditionally, so no crafted
        // query can widen the result past the caller's own numbers.
        $owner = User::factory()->create();
        Number::factory()->for($owner)->create();
        $strangerA = Number::factory()->create(['number' => '+37060099991']);
        $strangerB = Number::factory()->create(['number' => '+37060099992']);
        Message::factory()->create([
            'sender_number_id' => $strangerA->id,
            'receiver_number_id' => $strangerB->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q='.urlencode('+37060099991'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_pagination_links_preserve_the_filter(): void
    {
        [$owner, $mine, $alice] = $this->inboxWithTwoCounterparts();
        Message::factory()->count(25)->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/messages?q='.urlencode('+37060011111'));

        $response->assertStatus(200)
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25);

        $this->assertStringContainsString('q=', $response->json('links.next'));
        $this->assertStringContainsString('37060011111', urldecode($response->json('links.next')));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/messages')->assertStatus(401);
    }
}
