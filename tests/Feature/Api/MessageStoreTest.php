<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\Delegate;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageStoreTest extends TestCase
{
    use RefreshDatabase;

    private function template(): MessageTemplate
    {
        $category = MessageCategory::factory()->create();

        return MessageTemplate::factory()->for($category, 'category')->create();
    }

    public function test_store_creates_a_sent_message_for_an_active_receiver(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        $sender = Number::factory()->for($owner)->create();
        $receiverOwner = User::factory()->create(['status' => 'active']);
        $receiver = Number::factory()->for($receiverOwner)->create();
        $template = $this->template();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sender.id', $sender->id)
            ->assertJsonPath('data.receiver.id', $receiver->id)
            ->assertJsonPath('data.template.id', $template->id);

        $this->assertDatabaseHas('messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
            'status' => 'sent',
        ]);
    }

    public function test_store_queues_the_message_when_the_receiver_is_busy(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiverOwner = User::factory()->create(['status' => 'busy']);
        $receiver = Number::factory()->for($receiverOwner)->create();
        $template = $this->template();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseHas('messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'status' => 'queued',
        ]);
    }

    public function test_store_rejects_a_dnd_receiver(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiverOwner = User::factory()->create(['status' => 'dnd']);
        $receiver = Number::factory()->for($receiverOwner)->create();
        $template = $this->template();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_rejects_a_blocked_sender(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiverOwner = User::factory()->create(['status' => 'active']);
        $receiver = Number::factory()->for($receiverOwner)->create();
        Block::create(['number_id' => $receiver->id, 'type' => 'number', 'value' => $sender->number]);
        $template = $this->template();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_rejects_an_inactive_sender_number(): void
    {
        // ANDROID_APP_CONTEXT.md §2: inactive numbers can't send or receive. The web
        // app only enforced this by omitting them from the compose picker.
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->inactive()->create();
        $receiver = Number::factory()->create();
        $template = $this->template();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_rejects_an_inactive_receiver_number(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->inactive()->create();
        $template = $this->template();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_requires_sender_receiver_and_some_content(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sender_number_id', 'receiver_number_id', 'body']);
    }

    // ── free text vs template ────────────────────────────────────────────

    /** @return array{0: User, 1: Number, 2: Number} */
    private function activePair(): array
    {
        $owner = User::factory()->create();

        return [$owner, Number::factory()->for($owner)->create(), Number::factory()->create()];
    }

    public function test_store_accepts_free_text_with_no_template(): void
    {
        [$owner, $sender, $receiver] = $this->activePair();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'body' => 'Typed by hand',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Typed by hand')
            ->assertJsonPath('data.template', null);

        $this->assertDatabaseHas('messages', ['body' => 'Typed by hand', 'template_id' => null]);
    }

    public function test_store_falls_back_to_the_template_body_when_no_text_is_given(): void
    {
        [$owner, $sender, $receiver] = $this->activePair();
        $template = MessageTemplate::factory()
            ->for(MessageCategory::factory()->create(), 'category')
            ->create(['body' => 'Can you talk?']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Can you talk?')
            ->assertJsonPath('data.template.id', $template->id);
    }

    public function test_typed_text_overrides_the_template_body(): void
    {
        // The composer inserts a canned response as editable text, so what was
        // sent can differ from the template it came from.
        [$owner, $sender, $receiver] = $this->activePair();
        $template = MessageTemplate::factory()
            ->for(MessageCategory::factory()->create(), 'category')
            ->create(['body' => 'Can you talk?']);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
                'body' => 'Can you talk in 10 minutes?',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Can you talk in 10 minutes?')
            ->assertJsonPath('data.template.id', $template->id);

        $this->assertDatabaseHas('messages', [
            'body' => 'Can you talk in 10 minutes?',
            'template_id' => $template->id,
        ]);
    }

    public function test_store_rejects_a_body_over_255_characters(): void
    {
        [$owner, $sender, $receiver] = $this->activePair();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'body' => str_repeat('a', 256),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_store_rejects_a_nonexistent_sender(): void
    {
        $owner = User::factory()->create();
        $receiver = Number::factory()->create();
        $template = $this->template();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => 999999,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sender_number_id']);
    }

    public function test_store_rejects_a_nonexistent_receiver(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $template = $this->template();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => 999999,
                'template_id' => $template->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_number_id']);
    }

    public function test_store_rejects_the_same_number_as_sender_and_receiver(): void
    {
        $owner = User::factory()->create();
        $number = Number::factory()->for($owner)->create();
        $template = $this->template();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $number->id,
                'receiver_number_id' => $number->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_number_id']);
    }

    public function test_store_rejects_a_nonexistent_template(): void
    {
        $owner = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template_id']);
    }

    public function test_store_is_forbidden_when_sender_is_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        $receiver = Number::factory()->create();
        $template = $this->template();

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_is_forbidden_for_a_delegate_of_the_sender_number(): void
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $sender = Number::factory()->for($owner)->create();
        Delegate::create(['number_id' => $sender->id, 'assistant_user_id' => $assistant->id]);
        $receiver = Number::factory()->create();
        $template = $this->template();

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/messages', [
                'sender_number_id' => $sender->id,
                'receiver_number_id' => $receiver->id,
                'template_id' => $template->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_store_requires_authentication(): void
    {
        $sender = Number::factory()->create();
        $receiver = Number::factory()->create();
        $template = $this->template();

        $this->postJson('/api/messages', [
            'sender_number_id' => $sender->id,
            'receiver_number_id' => $receiver->id,
            'template_id' => $template->id,
        ])->assertStatus(401);
    }
}
