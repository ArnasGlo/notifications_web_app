<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token']);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android');

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->postJson('/api/logout')
            ->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_a_revoked_token_can_no_longer_authenticate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('android')->plainTextToken;
        $user->tokens()->delete();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertStatus(401);
    }

    public function test_user_endpoint_returns_the_wrapped_resource_without_internal_fields(): void
    {
        $user = User::factory()->create(['name' => 'Test User', 'status' => 'busy']);
        $token = $user->createToken('android')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Test User')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.status', 'busy')
            ->assertJsonMissingPath('data.is_admin')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.email_verified_at');
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
