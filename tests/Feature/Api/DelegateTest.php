<?php

namespace Tests\Feature\Api;

use App\Models\Delegate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelegateTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_delegates_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create(['name' => 'Assistant Name', 'email' => 'assistant@example.com']);
        $number = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $response = $this->actingAs($owner, 'sanctum')->getJson("/api/numbers/{$number->id}/delegates");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $delegate->id)
            ->assertJsonPath('data.0.assistant.id', $assistant->id)
            ->assertJsonPath('data.0.assistant.name', 'Assistant Name')
            ->assertJsonPath('data.0.assistant.email', 'assistant@example.com');
    }

    public function test_index_returns_an_empty_list_when_no_delegates(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/delegates")
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
            ->getJson("/api/numbers/{$number->id}/delegates")
            ->assertStatus(403);
    }

    public function test_index_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/numbers/{$number->id}/delegates")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $number = Number::factory()->create();

        $this->getJson("/api/numbers/{$number->id}/delegates")->assertStatus(401);
    }

    public function test_destroy_removes_the_delegate_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/delegates/{$delegate->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('number_delegates', ['id' => $delegate->id]);
    }

    public function test_destroy_is_forbidden_for_the_assistant_themselves(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/delegates/{$delegate->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('number_delegates', ['id' => $delegate->id]);
    }

    public function test_destroy_is_forbidden_for_an_unrelated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/numbers/{$number->id}/delegates/{$delegate->id}")
            ->assertStatus(403);
    }

    public function test_destroy_returns_404_when_delegate_does_not_belong_to_the_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $numberA = Number::factory()->for($owner)->create();
        $numberB = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $numberB->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/numbers/{$numberA->id}/delegates/{$delegate->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('number_delegates', ['id' => $delegate->id]);
    }

    public function test_destroy_requires_authentication(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $delegate = Delegate::create(['number_id' => $number->id, 'assistant_user_id' => $assistant->id]);

        $this->deleteJson("/api/numbers/{$number->id}/delegates/{$delegate->id}")->assertStatus(401);
    }
}
