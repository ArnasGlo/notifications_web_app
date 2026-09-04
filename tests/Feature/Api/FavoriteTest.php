<?php

namespace Tests\Feature\Api;

use App\Models\Favorite;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    // ── index ────────────────────────────────────────────────────────────

    public function test_index_lists_only_the_authenticated_users_favorites(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = Number::factory()->create(['number' => '+37060011111']);
        $theirs = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $user->id, 'number_id' => $mine->id]);
        Favorite::create(['user_id' => $other->id, 'number_id' => $theirs->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/favorites');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $favorite->id)
            ->assertJsonPath('data.0.number.id', $mine->id)
            ->assertJsonPath('data.0.number.number', '+37060011111');
    }

    public function test_index_returns_an_empty_list_when_there_are_no_favorites(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/favorites')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    public function test_index_does_not_expose_the_owner_of_a_favorited_number(): void
    {
        // Favoriting is unilateral, so the resource deliberately stays at the
        // shape GET /api/numbers/search already returns.
        $user = User::factory()->create();
        $owner = User::factory()->create(['name' => 'Secret Owner']);
        $number = Number::factory()->for($owner)->create();
        Favorite::create(['user_id' => $user->id, 'number_id' => $number->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/favorites');

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.0.number.owner')
            ->assertJsonMissingPath('data.0.number.user_id')
            ->assertDontSee('Secret Owner');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/favorites')->assertStatus(401);
    }

    // ── store ────────────────────────────────────────────────────────────

    public function test_store_creates_a_favorite(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/favorites', ['number_id' => $number->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.number.id', $number->id);

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'number_id' => $number->id,
        ]);
    }

    public function test_store_is_idempotent(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/favorites', ['number_id' => $number->id])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/favorites', ['number_id' => $number->id])
            ->assertStatus(200);

        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_two_users_can_favorite_the_same_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $number = Number::factory()->create();

        $this->actingAs($a, 'sanctum')->postJson('/api/favorites', ['number_id' => $number->id])->assertStatus(201);
        $this->actingAs($b, 'sanctum')->postJson('/api/favorites', ['number_id' => $number->id])->assertStatus(201);

        $this->assertDatabaseCount('favorites', 2);
    }

    public function test_store_requires_a_number_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/favorites', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number_id']);
    }

    public function test_store_rejects_a_nonexistent_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/favorites', ['number_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number_id']);
    }

    public function test_store_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->postJson('/api/favorites', ['number_id' => $number->id])->assertStatus(401);
    }

    // ── destroy ──────────────────────────────────────────────────────────

    public function test_destroy_removes_my_favorite(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $user->id, 'number_id' => $number->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/favorites/{$favorite->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('favorites', ['id' => $favorite->id]);
    }

    public function test_destroy_another_users_favorite_is_forbidden(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $other->id, 'number_id' => $number->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/favorites/{$favorite->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('favorites', ['id' => $favorite->id]);
    }

    public function test_destroy_returns_404_for_a_nonexistent_favorite(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/favorites/999999')
            ->assertStatus(404);
    }

    public function test_destroy_requires_authentication(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $user->id, 'number_id' => $number->id]);

        $this->deleteJson("/api/favorites/{$favorite->id}")->assertStatus(401);
    }
}
