<?php

namespace Tests\Feature\Api;

use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageComposeDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_an_active_category_with_its_active_non_reply_templates(): void
    {
        $user = User::factory()->create();
        $category = MessageCategory::factory()->create(['name' => 'Meeting', 'icon' => 'fas fa-calendar', 'is_active' => true]);
        $template = MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.name', 'Meeting')
            ->assertJsonPath('data.0.icon', 'fas fa-calendar')
            ->assertJsonCount(1, 'data.0.templates')
            ->assertJsonPath('data.0.templates.0.id', $template->id)
            ->assertJsonPath('data.0.templates.0.body', 'Can you talk?');
    }

    public function test_excludes_an_inactive_category(): void
    {
        $user = User::factory()->create();
        $inactive = MessageCategory::factory()->create(['is_active' => false]);
        MessageTemplate::factory()->for($inactive, 'category')->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_excludes_an_inactive_template_from_an_active_category(): void
    {
        $user = User::factory()->create();
        $category = MessageCategory::factory()->create(['is_active' => true]);
        MessageTemplate::factory()->for($category, 'category')->create(['is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(0, 'data.0.templates');
    }

    public function test_excludes_a_reply_template_from_an_active_category(): void
    {
        $user = User::factory()->create();
        $category = MessageCategory::factory()->create(['is_active' => true]);
        MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(0, 'data.0.templates');
    }

    public function test_an_active_category_with_no_qualifying_templates_still_appears_with_an_empty_list(): void
    {
        $user = User::factory()->create();
        $category = MessageCategory::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.templates', []);
    }

    public function test_returns_an_empty_list_when_no_active_categories(): void
    {
        $user = User::factory()->create();
        MessageCategory::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/messages/compose-data')
            ->assertStatus(200)
            ->assertExactJson(['data' => []]);
    }

    public function test_response_has_no_pagination_links_or_meta(): void
    {
        $user = User::factory()->create();
        MessageCategory::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/messages/compose-data');

        $response->assertStatus(200)
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/messages/compose-data')->assertStatus(401);
    }
}
