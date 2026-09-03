<?php

namespace Tests\Feature\Api;

use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_finds_an_active_number(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->create(['number' => '+37060000123']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/numbers/search?number=' . urlencode($number->number))
            ->assertStatus(200)
            ->assertExactJson([
                'data' => [
                    'id' => $number->id,
                    'number' => $number->number,
                ],
            ]);
    }

    public function test_lookup_returns_404_when_number_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/numbers/search?number=' . urlencode('+37060099999'))
            ->assertStatus(404);
    }

    public function test_lookup_returns_404_for_an_inactive_number(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->inactive()->create(['number' => '+37060000999']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/numbers/search?number=' . urlencode($number->number))
            ->assertStatus(404);
    }

    public function test_lookup_requires_the_number_parameter(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/numbers/search')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number']);
    }

    public function test_lookup_requires_authentication(): void
    {
        $this->getJson('/api/numbers/search?number=' . urlencode('+37060000123'))
            ->assertStatus(401);
    }
}
