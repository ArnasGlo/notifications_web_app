<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_the_authenticated_users_own_numbers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Number::factory()->for($user)->create();
        Number::factory()->for($other)->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/numbers');

        $response->assertStatus(200);
        $owned = $response->json('data.owned');
        $this->assertCount(1, $owned);
        $this->assertSame($own->id, $owned[0]['id']);
    }

    public function test_index_returns_numbers_the_user_is_delegated_on_with_owner_info(): void
    {
        $assistant = User::factory()->create();
        $owner = User::factory()->create(['name' => 'Jane Owner']);
        $delegatedNumber = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $delegatedNumber->id, 'assistant_user_id' => $assistant->id]);

        $response = $this->actingAs($assistant, 'sanctum')->getJson('/api/numbers');

        $response->assertStatus(200);
        $assisting = $response->json('data.assisting');
        $this->assertCount(1, $assisting);
        $this->assertSame($delegatedNumber->id, $assisting[0]['id']);
        $this->assertSame($owner->id, $assisting[0]['owner']['id']);
        $this->assertSame('Jane Owner', $assisting[0]['owner']['name']);
    }

    public function test_index_returns_empty_lists_for_a_user_with_no_numbers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/numbers');

        $response->assertStatus(200)
            ->assertExactJson(['data' => ['owned' => [], 'assisting' => []]]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/numbers')->assertStatus(401);
    }

    public function test_store_creates_a_number_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/numbers', [
            'number' => '+37060012345',
            'country' => 'Lithuania',
            'city' => 'Vilnius',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.number', '+37060012345')
            ->assertJsonPath('data.status', 'active');
        $this->assertNotEmpty($response->json('data.share_token'));
        $this->assertDatabaseHas('numbers', ['number' => '+37060012345', 'user_id' => $user->id]);
    }

    public function test_store_requires_the_number_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/numbers', ['country' => 'Lithuania'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number']);
    }

    public function test_store_rejects_a_duplicate_number(): void
    {
        $user = User::factory()->create();
        $existing = Number::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/numbers', ['number' => $existing->number])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number']);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/numbers', ['number' => '+37060012345'])->assertStatus(401);
    }

    public function test_update_modifies_country_city_and_status_for_the_owner(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->patchJson("/api/numbers/{$number->id}", [
            'country' => 'Latvia',
            'city' => 'Riga',
            'status' => 'inactive',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.country', 'Latvia')
            ->assertJsonPath('data.city', 'Riga')
            ->assertJsonPath('data.status', 'inactive');
        $this->assertDatabaseHas('numbers', ['id' => $number->id, 'status' => 'inactive']);
    }

    public function test_update_rejects_an_invalid_status(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/numbers/{$number->id}", ['status' => 'not-a-status'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_update_is_forbidden_for_a_number_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/numbers/{$number->id}", ['city' => 'Kaunas'])
            ->assertStatus(403);
    }

    public function test_update_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->patchJson("/api/numbers/{$number->id}", ['city' => 'Kaunas'])->assertStatus(401);
    }

    public function test_destroy_removes_the_number_for_the_owner(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('numbers', ['id' => $number->id]);
    }

    public function test_destroy_is_forbidden_for_a_number_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('numbers', ['id' => $number->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->deleteJson("/api/numbers/{$number->id}")->assertStatus(401);
    }
}
