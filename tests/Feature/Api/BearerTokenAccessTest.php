<?php

namespace Tests\Feature\Api;

use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every other API test authenticates with actingAs($user, 'sanctum'), which swaps the
 * default guard process-wide and so cannot catch a wrong-guard regression (this is how
 * the InviteResource bug survived its first test run). These smoke tests drive one
 * route per API controller with a real Authorization: Bearer header instead.
 */
class BearerTokenAccessTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('android')->plainTextToken);
    }

    public function test_a_real_bearer_token_authenticates_every_api_controller(): void
    {
        $user = User::factory()->create();
        $number = Number::factory()->for($user)->create();

        $routes = [
            '/api/user',                                    // AuthController
            '/api/numbers',                                 // NumberController
            "/api/numbers/{$number->id}/delegates",         // DelegateController
            "/api/numbers/{$number->id}/blocks",            // BlockController
            '/api/messages',                                // MessageController
        ];

        foreach ($routes as $uri) {
            $this->bearer($user)->getJson($uri)->assertStatus(200);
        }

        $this->bearer($user)
            ->patchJson('/api/status', ['status' => 'busy'])
            ->assertStatus(200);
    }

    public function test_a_garbage_bearer_token_is_rejected_as_json(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|not-a-real-token')
            ->get('/api/numbers')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json');
    }
}
