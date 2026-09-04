<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\Delegate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockTest extends TestCase
{
    use RefreshDatabase;

    // ── index ────────────────────────────────────────────────────────────

    public function test_index_lists_blocks_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $number->id, 'type' => 'city', 'value' => 'Vilnius']);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/blocks");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $block->id)
            ->assertJsonPath('data.0.type', 'city')
            ->assertJsonPath('data.0.value', 'Vilnius');
    }

    public function test_index_returns_an_empty_list_when_no_blocks(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/blocks")
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    public function test_index_is_forbidden_for_the_assistant_themselves(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/blocks")
            ->assertStatus(403);
    }

    public function test_index_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/blocks")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->getJson("/api/numbers/{$number->id}/blocks")->assertStatus(401);
    }

    // ── store ────────────────────────────────────────────────────────────

    public function test_store_creates_a_number_type_block_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", [
                'type' => 'number',
                'value' => '+37060011122',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'number')
            ->assertJsonPath('data.value', '+37060011122');

        $this->assertDatabaseHas('blocks', [
            'number_id' => $number->id,
            'type' => 'number',
            'value' => '+37060011122',
        ]);
    }

    public function test_store_creates_a_user_type_block_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $blockedUser = User::factory()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", [
                'type' => 'user',
                'value' => (string) $blockedUser->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.value', (string) $blockedUser->id);

        $this->assertDatabaseHas('blocks', [
            'number_id' => $number->id,
            'type' => 'user',
            'value' => (string) $blockedUser->id,
        ]);
    }

    public function test_store_creates_a_city_type_block_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", [
                'type' => 'city',
                'value' => 'Kaunas',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'city')
            ->assertJsonPath('data.value', 'Kaunas');

        $this->assertDatabaseHas('blocks', [
            'number_id' => $number->id,
            'type' => 'city',
            'value' => 'Kaunas',
        ]);
    }

    public function test_store_creates_a_country_type_block_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", [
                'type' => 'country',
                'value' => 'Lithuania',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'country')
            ->assertJsonPath('data.value', 'Lithuania');

        $this->assertDatabaseHas('blocks', [
            'number_id' => $number->id,
            'type' => 'country',
            'value' => 'Lithuania',
        ]);
    }

    public function test_store_is_forbidden_for_the_assistant_themselves(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", ['type' => 'city', 'value' => 'Kaunas'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('blocks', ['number_id' => $number->id]);
    }

    public function test_store_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", ['type' => 'city', 'value' => 'Kaunas'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('blocks', ['number_id' => $number->id]);
    }

    public function test_store_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->postJson("/api/numbers/{$number->id}/blocks", ['type' => 'city', 'value' => 'Kaunas'])
            ->assertStatus(401);
    }

    public function test_store_rejects_an_invalid_type(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", ['type' => 'email', 'value' => 'x@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_store_requires_a_value(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$number->id}/blocks", ['type' => 'city'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    // ── destroy ──────────────────────────────────────────────────────────

    public function test_destroy_removes_the_block_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $number->id, 'type' => 'city', 'value' => 'Kaunas']);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/blocks/{$block->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('blocks', ['id' => $block->id]);
    }

    public function test_destroy_is_forbidden_for_the_assistant_themselves(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $number->id, 'type' => 'city', 'value' => 'Kaunas']);
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/blocks/{$block->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    }

    public function test_destroy_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $number->id, 'type' => 'city', 'value' => 'Kaunas']);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/blocks/{$block->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    }

    public function test_destroy_returns_404_when_block_does_not_belong_to_the_number(): void
    {
        $owner = User::factory()->create();
        $numberA = Number::factory()->for($owner)->create();
        $numberB = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $numberB->id, 'type' => 'city', 'value' => 'Kaunas']);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/numbers/{$numberA->id}/blocks/{$block->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('blocks', ['id' => $block->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $block = Block::create(['number_id' => $number->id, 'type' => 'city', 'value' => 'Kaunas']);

        $this->deleteJson("/api/numbers/{$number->id}/blocks/{$block->id}")->assertStatus(401);
    }

    // ── canReceiveFrom() integration ────────────────────────────────────

    public function test_a_created_number_type_block_makes_can_receive_from_return_false(): void
    {
        $owner = User::factory()->create();
        $receiver = Number::factory()->for($owner)->create();
        $sender = Number::factory()->create(['number' => '+37060099988']);

        $this->assertTrue($receiver->canReceiveFrom($sender));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$receiver->id}/blocks", [
                'type' => 'number',
                'value' => '+37060099988',
            ])
            ->assertStatus(201);

        $this->assertFalse($receiver->fresh()->canReceiveFrom($sender));
    }

    public function test_a_created_user_type_block_makes_can_receive_from_return_false(): void
    {
        $owner = User::factory()->create();
        $senderOwner = User::factory()->create();
        $receiver = Number::factory()->for($owner)->create();
        $sender = Number::factory()->for($senderOwner)->create();

        $this->assertTrue($receiver->canReceiveFrom($sender));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/numbers/{$receiver->id}/blocks", [
                'type' => 'user',
                'value' => (string) $senderOwner->id,
            ])
            ->assertStatus(201);

        $this->assertFalse($receiver->fresh()->canReceiveFrom($sender));
    }

    // ── documented sqlite/MySQL collation divergence ────────────────────

    public function test_city_block_case_sensitivity_diverges_between_sqlite_and_mysql(): void
    {
        // canReceiveFrom() does a plain SQL `where('value', ...)` equality match with no
        // app-level normalisation (deliberately — see BlockStoreRequest/BlockController).
        // MySQL's default collation (utf8mb4_unicode_ci / utf8mb4_0900_ai_ci) is
        // case-insensitive, so a block on "vilnius" would also match a sender city of
        // "Vilnius" in production. sqlite's default TEXT comparison is case-sensitive
        // (`vilnius` !== `Vilnius` in a plain `=` comparison, no COLLATE NOCASE applied),
        // and this test suite runs on sqlite (see phpunit.xml). So on sqlite this test
        // demonstrates the block does NOT match a differently-cased city and the message
        // would be allowed through; on MySQL the same data would match and the message
        // would be blocked. This is a genuine behavioural divergence between the test
        // environment and production — documented here, not fixed.
        $owner = User::factory()->create();
        $receiver = Number::factory()->for($owner)->create();
        $sender = Number::factory()->create(['city' => 'Vilnius']);

        Block::create(['number_id' => $receiver->id, 'type' => 'city', 'value' => 'vilnius']);

        // On sqlite (this test run): case-sensitive comparison, block does NOT match.
        $this->assertTrue($receiver->canReceiveFrom($sender));

        // On MySQL (production): the default case-insensitive collation WOULD match
        // "vilnius" against "Vilnius", and canReceiveFrom() would instead return false.
        // Not asserted here since it cannot be exercised on sqlite.
    }
}
