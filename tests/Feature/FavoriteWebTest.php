<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteWebTest extends TestCase
{
    use RefreshDatabase;

    // ── favorites screen ─────────────────────────────────────────────────

    public function test_index_lists_only_my_favorites(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = Number::factory()->create(['number' => '+37060011111']);
        $theirs = Number::factory()->create(['number' => '+37060022222']);
        Favorite::create(['user_id' => $user->id, 'number_id' => $mine->id]);
        Favorite::create(['user_id' => $other->id, 'number_id' => $theirs->id]);

        $this->actingAs($user)
            ->get(route('favorites.index'))
            ->assertStatus(200)
            ->assertSee('+37060011111')
            ->assertDontSee('+37060022222');
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('favorites.index'))->assertRedirect('/login');
    }

    public function test_store_adds_a_favorite_by_number(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create(['number' => '+37060011111']);

        $this->actingAs($user)
            ->from(route('favorites.index'))
            ->post(route('favorites.store'), ['number' => '+37060011111'])
            ->assertRedirect(route('favorites.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'number_id' => $number->id,
        ]);
    }

    public function test_store_rejects_a_number_that_does_not_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('favorites.index'))
            ->post(route('favorites.store'), ['number' => '+37060099999'])
            ->assertSessionHasErrors(['number']);

        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_store_is_idempotent(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create(['number' => '+37060011111']);

        $this->actingAs($user)->post(route('favorites.store'), ['number' => $number->number]);
        $this->actingAs($user)->post(route('favorites.store'), ['number' => $number->number]);

        $this->assertDatabaseCount('favorites', 1);
    }

    public function test_destroy_removes_my_favorite(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $user->id, 'number_id' => $number->id]);

        $this->actingAs($user)
            ->from(route('favorites.index'))
            ->delete(route('favorites.destroy', $favorite))
            ->assertRedirect(route('favorites.index'));

        $this->assertDatabaseMissing('favorites', ['id' => $favorite->id]);
    }

    public function test_destroy_another_users_favorite_is_forbidden(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->create();
        $favorite = Favorite::create(['user_id' => $other->id, 'number_id' => $number->id]);

        $this->actingAs($user)
            ->delete(route('favorites.destroy', $favorite))
            ->assertStatus(403);

        $this->assertDatabaseHas('favorites', ['id' => $favorite->id]);
    }

    // ── inbox search ─────────────────────────────────────────────────────

    public function test_the_inbox_search_filters_by_counterpart_number(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alice = Number::factory()->create(['number' => '+37060011111']);
        $bob = Number::factory()->create(['number' => '+37060022222']);
        $category = MessageCategory::factory()->create();

        Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
            'template_id' => MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Ping from Alice'])->id,
        ]);
        Message::factory()->create([
            'sender_number_id' => $bob->id,
            'receiver_number_id' => $mine->id,
            'template_id' => MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Ping from Bob'])->id,
        ]);

        $this->actingAs($owner)
            ->get(route('messages.index', ['q' => '+37060011111']))
            ->assertStatus(200)
            ->assertSee('Ping from Alice')
            ->assertDontSee('Ping from Bob');
    }

    public function test_the_unfiltered_inbox_still_shows_everything(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alice = Number::factory()->create(['number' => '+37060011111']);
        $category = MessageCategory::factory()->create();

        Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
            'template_id' => MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Ping from Alice'])->id,
        ]);

        $this->actingAs($owner)
            ->get(route('messages.index'))
            ->assertStatus(200)
            ->assertSee('Ping from Alice');
    }

    // ── starring from a thread ───────────────────────────────────────────

    public function test_the_thread_offers_a_favorite_button_for_the_counterpart(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alice = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->get(route('messages.show', $message))
            ->assertStatus(200)
            ->assertSee('Favorite');
    }

    public function test_starring_from_a_thread_returns_to_the_thread(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->create();
        $alice = Number::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => $alice->id,
            'receiver_number_id' => $mine->id,
        ]);

        $this->actingAs($owner)
            ->from(route('messages.show', $message))
            ->post(route('favorites.store'), ['number' => $alice->number])
            ->assertRedirect(route('messages.show', $message));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $owner->id,
            'number_id' => $alice->id,
        ]);
    }
}
