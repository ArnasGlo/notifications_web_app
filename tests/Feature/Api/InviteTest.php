<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_the_number_for_a_new_user(): void
    {
        $owner = User::factory()->create(['name' => 'Owner Name']);
        $newUser = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $response = $this->actingAs($newUser, 'sanctum')->getJson("/api/invite/{$number->share_token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.number.id', $number->id)
            ->assertJsonPath('data.number.number', $number->number)
            ->assertJsonPath('data.owner.name', 'Owner Name')
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.already_assistant', false);
    }

    public function test_show_flags_is_owner_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/invite/{$number->share_token}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.already_assistant', false);
    }

    public function test_show_flags_already_assistant_for_an_existing_assistant(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/invite/{$number->share_token}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.already_assistant', true);
    }

    public function test_show_returns_404_for_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/invite/not-a-real-token')
            ->assertStatus(404);
    }

    public function test_show_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->getJson("/api/invite/{$number->share_token}")->assertStatus(401);
    }

    public function test_accept_creates_a_delegate_for_a_new_user(): void
    {
        $owner = User::factory()->create();
        $newUser = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $response = $this->actingAs($newUser, 'sanctum')->postJson("/api/invite/{$number->share_token}/accept");

        $response->assertStatus(201)
            ->assertJsonPath('data.assistant.id', $newUser->id);
        $this->assertDatabaseHas('number_delegates', [
            'number_id' => $number->id,
            'assistant_user_id' => $newUser->id,
        ]);
    }

    public function test_accept_is_idempotent_for_an_existing_assistant(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/invite/{$number->share_token}/accept")
            ->assertStatus(200);

        $this->assertDatabaseCount('number_delegates', 1);
    }

    public function test_accept_rejects_the_owner_accepting_their_own_link(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/invite/{$number->share_token}/accept")
            ->assertStatus(422);

        $this->assertDatabaseMissing('number_delegates', ['number_id' => $number->id]);
    }

    public function test_accept_returns_404_for_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/invite/not-a-real-token/accept')
            ->assertStatus(404);
    }

    public function test_accept_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->postJson("/api/invite/{$number->share_token}/accept")->assertStatus(401);
    }
}
