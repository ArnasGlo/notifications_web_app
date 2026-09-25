<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Delegate;
use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\TypingAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The web side of typing agreements: the chat page's bar and buttons, the
 * composer's templates-only mode, the number setting and the compose check.
 * The rules themselves are covered in Api\TypingAgreementTest.
 */
class TypingAgreementWebTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{alice: User, bob: User, aliceNumber: Number, bobNumber: Number, conversation: Conversation} */
    private function pair(bool $aliceAllows = false, bool $bobAllows = false): array
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceNumber = Number::factory()->for($alice)->create(['allow_typing' => $aliceAllows, 'number' => '+37060000001']);
        $bobNumber = Number::factory()->for($bob)->create(['allow_typing' => $bobAllows, 'number' => '+37060000002']);

        $message = Message::factory()->create([
            'sender_number_id' => $aliceNumber->id,
            'receiver_number_id' => $bobNumber->id,
        ]);

        return [
            'alice' => $alice, 'bob' => $bob, 'aliceNumber' => $aliceNumber, 'bobNumber' => $bobNumber,
            'conversation' => $message->conversation,
        ];
    }

    public function test_with_no_agreement_the_chat_offers_a_request_and_a_templates_only_composer(): void
    {
        ['alice' => $alice, 'conversation' => $c] = $this->pair();

        $this->actingAs($alice)->get(route('conversations.show', $c))
            ->assertOk()
            ->assertSee('Templates only — typing needs an agreement with +37060000002.')
            ->assertSee('Request typing')
            ->assertSee('data-typing="0"', false)
            ->assertSee('readonly', false);
    }

    public function test_the_bar_shows_each_state_with_the_right_actions(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'conversation' => $c] = $this->pair();
        $agreement = TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id]);

        $this->actingAs($alice)->get(route('conversations.show', $c))
            ->assertSee('Typing request sent — waiting for +37060000002 to accept.')
            ->assertSee('Cancel request')
            ->assertDontSee('Request typing');

        $this->actingAs($bob)->get(route('conversations.show', $c))
            ->assertSee('+37060000001 asks to allow typing in this conversation.')
            ->assertSee('Accept')
            ->assertSee('Decline');

        $agreement->update(['accepted_at' => now()]);
        $this->actingAs($bob)->get(route('conversations.show', $c))
            ->assertSee('Typing agreement active — you can type freely.')
            ->assertSee('Remove')
            ->assertSee('data-typing="1"', false);
    }

    public function test_automatic_typing_has_no_remove_button(): void
    {
        ['alice' => $alice, 'conversation' => $c] = $this->pair(true, true);

        $this->actingAs($alice)->get(route('conversations.show', $c))
            ->assertSee('Typing allowed — both numbers allow typing in chat.')
            ->assertDontSee('conversations/'.$c->id.'/typing', false);
    }

    public function test_the_owners_buttons_request_accept_and_remove(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'conversation' => $c] = $this->pair();

        $this->actingAs($alice)->post(route('conversations.typing.store', $c))
            ->assertRedirect(route('conversations.show', $c))
            ->assertSessionHas('success', 'Typing request sent.');

        $this->actingAs($bob)->post(route('conversations.typing.accept', $c))
            ->assertRedirect(route('conversations.show', $c))
            ->assertSessionHas('success', 'Typing is now allowed in this conversation.');
        $this->assertTrue($c->fresh()->allowsTyping());

        $this->actingAs($alice)->delete(route('conversations.typing.destroy', $c))
            ->assertRedirect(route('conversations.show', $c))
            ->assertSessionHas('success', 'Typing agreement removed. Only templates can be sent now.');
        $this->assertFalse($c->fresh()->allowsTyping());
    }

    public function test_a_request_to_a_number_that_allows_typing_is_accepted_at_once(): void
    {
        ['alice' => $alice, 'conversation' => $c] = $this->pair(false, true);

        $this->actingAs($alice)->post(route('conversations.typing.store', $c))
            ->assertSessionHas('success', 'Typing is now allowed in this conversation.');
    }

    public function test_a_refused_change_is_flashed_back_to_the_chat(): void
    {
        ['alice' => $alice, 'conversation' => $c] = $this->pair(true, true);

        $this->actingAs($alice)->delete(route('conversations.typing.destroy', $c))
            ->assertRedirect(route('conversations.show', $c))
            ->assertSessionHas('error');
    }

    public function test_assistants_and_strangers_are_forbidden_and_guests_go_to_login(): void
    {
        ['bobNumber' => $b, 'conversation' => $c] = $this->pair();
        $assistant = User::factory()->create();
        Delegate::create(['number_id' => $b->id, 'assistant_user_id' => $assistant->id]);

        $this->actingAs($assistant)->get(route('conversations.show', $c))
            ->assertOk()
            ->assertDontSee('Request typing');
        $this->actingAs($assistant)->post(route('conversations.typing.store', $c))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('conversations.typing.store', $c))->assertForbidden();
        $this->assertDatabaseCount('typing_agreements', 0);

        auth()->logout();
        $this->post(route('conversations.typing.store', $c))->assertRedirect(route('login'));
    }

    public function test_a_typed_message_without_typing_is_refused_and_kept(): void
    {
        ['alice' => $alice, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair();

        $this->actingAs($alice)
            ->from(route('conversations.show', $c))
            ->post(route('messages.store'), [
                'sender_number_id' => $a->id,
                'receiver_number_id' => $b->id,
                'body' => 'Typed by hand',
            ])
            ->assertRedirect(route('conversations.show', $c))
            ->assertSessionHas('error', 'Typing is not allowed with this number yet. Send a template, or ask for a typing agreement.')
            ->assertSessionHasInput('body', 'Typed by hand');

        $this->assertDatabaseMissing('messages', ['body' => 'Typed by hand']);
    }

    public function test_the_owner_sets_allow_typing_on_the_number_edit_page(): void
    {
        ['alice' => $alice, 'aliceNumber' => $a] = $this->pair();

        $this->actingAs($alice)->get(route('numbers.edit', $a))
            ->assertOk()
            ->assertSee('Allow typing in chat');

        $this->actingAs($alice)->put(route('numbers.update', $a), ['status' => 'active', 'allow_typing' => '1'])
            ->assertRedirect(route('numbers.index'));
        $this->assertTrue($a->fresh()->allow_typing);

        $this->actingAs($alice)->put(route('numbers.update', $a), ['status' => 'active', 'allow_typing' => '0']);
        $this->assertFalse($a->fresh()->allow_typing);

        $this->actingAs($alice)->put(route('numbers.update', $a), ['allow_typing' => 'maybe'])
            ->assertSessionHasErrors('allow_typing');
    }

    public function test_the_compose_check_reports_typing_for_the_chosen_numbers(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b] = $this->pair(true, true);
        $params = ['sender_number_id' => $a->id, 'receiver_number_id' => $b->id];

        $this->actingAs($alice)->getJson(route('messages.typing', $params))
            ->assertOk()
            ->assertExactJson(['typing_allowed' => true]);

        $this->actingAs($bob)->getJson(route('messages.typing', $params))->assertForbidden();
        $this->actingAs($alice)->getJson(route('messages.typing', ['sender_number_id' => $a->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_number_id']);
    }

    public function test_the_compose_page_starts_templates_only(): void
    {
        $user = User::factory()->create();
        Number::factory()->for($user)->create();
        MessageTemplate::factory()->for(MessageCategory::factory()->create(), 'category')->create();

        $this->actingAs($user)->get(route('messages.compose'))
            ->assertOk()
            ->assertSee('data-typing="0"', false)
            ->assertSee(route('messages.typing'), false);
    }

    public function test_thread_polling_carries_the_rendered_typing_bar(): void
    {
        ['bob' => $bob, 'aliceNumber' => $a, 'conversation' => $c] = $this->pair();
        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id]);

        $response = $this->actingAs($bob)->getJson(route('conversations.updates', $c))
            ->assertOk()
            ->assertJsonPath('typing.allowed', false);

        $this->assertStringContainsString('asks to allow typing', $response->json('typing.html'));
        $this->assertSame('pending', json_decode($response->json('typing.state'), true)['status']);
    }
}
