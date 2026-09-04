<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders the compose screen and its slash-command composer partial. Nothing
 * covered this view before, so a Blade error in it would have shipped unnoticed.
 */
class MessageComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_compose_page_renders_with_the_composer(): void
    {
        $user = User::factory()->create();
        Number::factory()->for($user)->create();
        $category = MessageCategory::factory()->create(['name' => 'Greeting']);
        MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Hello there']);

        $this->actingAs($user)
            ->get(route('messages.compose'))
            ->assertStatus(200)
            ->assertSee('composerBody', false)      // the free-text field
            ->assertSee('Hello there')              // template available to the slash menu
            ->assertSee('Greeting');
    }

    public function test_the_composer_offers_only_active_non_reply_templates(): void
    {
        // Same rule as Api\MessageController@composeData. It used to be applied in
        // client-side JS; the composer renders the payload directly now, so the
        // filter had to move into the query.
        $user = User::factory()->create();
        Number::factory()->for($user)->create();
        $category = MessageCategory::factory()->create();
        MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Offered template']);
        MessageTemplate::factory()->for($category, 'category')->reply()->create(['body' => 'Reply only template']);
        MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Retired template', 'is_active' => false]);

        $response = $this->actingAs($user)->get(route('messages.compose'));

        $response->assertStatus(200)
            ->assertSee('Offered template')
            ->assertDontSee('Reply only template')
            ->assertDontSee('Retired template');
    }

    public function test_an_inactive_category_is_not_offered(): void
    {
        $user = User::factory()->create();
        Number::factory()->for($user)->create();
        $inactive = MessageCategory::factory()->create(['is_active' => false]);
        MessageTemplate::factory()->for($inactive, 'category')->create(['body' => 'Hidden template']);

        $this->actingAs($user)
            ->get(route('messages.compose'))
            ->assertStatus(200)
            ->assertDontSee('Hidden template');
    }

    public function test_composing_free_text_sends_without_a_template(): void
    {
        $user = User::factory()->create();
        $sender = Number::factory()->for($user)->create();
        $receiver = Number::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('messages.compose'))
            ->post(route('messages.store'), [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'body' => 'Typed straight into the box',
            ]);

        $this->assertDatabaseHas('messages', [
            'body' => 'Typed straight into the box',
            'template_id' => null,
        ]);

        // Composing drops you into the resulting thread, not back to the list.
        $response->assertRedirect(route('conversations.show', Message::firstOrFail()->conversation_id));
    }
}
