<?php

namespace Tests\Feature\Api;

use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every request here is made WITHOUT an Accept: application/json header (plain
 * get()/post(), not getJson()/postJson()) — the shape an Android HTTP client sends
 * by default. The API must still answer in JSON rather than redirecting to the web
 * login page or rendering a Blade error view.
 */
class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_json_401_not_a_redirect(): void
    {
        $response = $this->get('/api/numbers');

        $response->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_forbidden_api_request_returns_json_403(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $token = $other->createToken('android')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->delete("/api/numbers/{$number->id}")
            ->assertStatus(403)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_unknown_api_route_returns_json_404(): void
    {
        $this->get('/api/no-such-endpoint')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_validation_failure_returns_json_422(): void
    {
        $this->post('/api/login', ['email' => 'nobody@example.com'])
            ->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors(['password']);
    }

    public function test_web_routes_still_redirect_guests_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }
}
