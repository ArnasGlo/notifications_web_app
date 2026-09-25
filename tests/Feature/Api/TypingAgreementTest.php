<?php

namespace Tests\Feature\Api;

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
 * Templates are the default; free text needs typing to be allowed between the
 * two numbers — automatically when both allow typing, otherwise by an agreement
 * one owner requests and the other accepts (at once, if their number allows it).
 */
class TypingAgreementTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSED = 'Typing is not allowed with this number yet. Send a template, or ask for a typing agreement.';

    /**
     * Alice and Bob, one number each, with a conversation between them.
     *
     * @return array{alice: User, bob: User, aliceNumber: Number, bobNumber: Number, conversation: Conversation, template: MessageTemplate}
     */
    private function pair(bool $aliceAllows = false, bool $bobAllows = false): array
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $aliceNumber = Number::factory()->for($alice)->create(['allow_typing' => $aliceAllows]);
        $bobNumber = Number::factory()->for($bob)->create(['allow_typing' => $bobAllows]);
        $template = MessageTemplate::factory()->for(MessageCategory::factory()->create(), 'category')->create(['body' => 'Can you talk?']);

        $message = Message::factory()->create([
            'sender_number_id' => $aliceNumber->id,
            'receiver_number_id' => $bobNumber->id,
            'template_id' => $template->id,
            'body' => $template->body,
        ]);

        return [
            'alice' => $alice, 'bob' => $bob, 'aliceNumber' => $aliceNumber, 'bobNumber' => $bobNumber,
            'conversation' => $message->conversation, 'template' => $template,
        ];
    }

    private function typeAs(User $user, Number $from, Number $to, string $body = 'Typed by hand')
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $from->id,
            'receiver_number_id' => $to->id,
            'body' => $body,
        ]);
    }

    // ── The four setting combinations ────────────────────────────────────────

    public function test_both_numbers_allowing_typing_makes_it_active_with_no_request(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair(true, true);

        $this->actingAs($alice, 'sanctum')->getJson("/api/conversations/{$c->id}/typing")
            ->assertOk()
            ->assertExactJson(['data' => [
                'status' => 'active', 'automatic' => true, 'requested_by_me' => null,
                'can_request' => false, 'can_accept' => false, 'can_remove' => false,
            ]]);

        $this->typeAs($alice, $a, $b)->assertCreated();
        $this->typeAs($bob, $b, $a)->assertCreated();

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Typing is already allowed in this conversation.');
        $this->assertDatabaseCount('typing_agreements', 0);
    }

    public function test_a_request_from_an_enabled_number_to_a_disabled_one_waits_for_acceptance(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair(true, false);

        $this->typeAs($alice, $a, $b)->assertStatus(422)->assertJsonPath('message', self::REFUSED);

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_by_me', true)
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.can_remove', true);

        $this->typeAs($alice, $a, $b)->assertStatus(422);
        $this->typeAs($bob, $b, $a)->assertStatus(422);

        $this->actingAs($bob, 'sanctum')->getJson("/api/conversations/{$c->id}/typing")
            ->assertJsonPath('data.requested_by_me', false)
            ->assertJsonPath('data.can_accept', true);

        $this->actingAs($bob, 'sanctum')->postJson("/api/conversations/{$c->id}/typing/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.automatic', false)
            ->assertJsonPath('data.can_remove', true);

        $this->typeAs($alice, $a, $b)->assertCreated();
        $this->typeAs($bob, $b, $a)->assertCreated();
    }

    public function test_a_request_to_a_number_that_allows_typing_is_accepted_at_once(): void
    {
        ['alice' => $alice, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair(false, true);

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.automatic', false);

        $this->assertNotNull(TypingAgreement::first()->accepted_at);
        $this->typeAs($alice, $a, $b)->assertCreated();
    }

    public function test_between_two_disabled_numbers_the_recipient_accepts_whichever_side_asked(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'conversation' => $c] = $this->pair();

        $this->actingAs($bob, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertJsonPath('data.status', 'pending');

        // The requester can't accept their own request, or ask twice.
        $this->actingAs($bob, 'sanctum')->postJson("/api/conversations/{$c->id}/typing/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is no typing request for you to accept.');
        $this->actingAs($bob, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A typing request is already waiting in this conversation.');
        // Nor can the other side answer with a request of its own.
        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertStatus(422);

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    // ── Removal ──────────────────────────────────────────────────────────────

    public function test_either_owner_can_end_an_active_agreement_and_templates_are_all_that_is_left(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c, 'template' => $t] = $this->pair();
        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id, 'accepted_at' => now()]);

        $this->actingAs($bob, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertOk()
            ->assertJsonPath('data.status', 'none')
            ->assertJsonPath('data.can_request', true);

        $this->typeAs($alice, $a, $b)->assertStatus(422)->assertJsonPath('message', self::REFUSED);
        $this->actingAs($alice, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $a->id, 'receiver_number_id' => $b->id, 'template_id' => $t->id,
        ])->assertCreated();

        $this->actingAs($alice, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is no typing agreement to remove.');
    }

    public function test_a_pending_request_can_be_cancelled_or_declined(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'conversation' => $c] = $this->pair();

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing");
        $this->actingAs($alice, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertJsonPath('data.status', 'none');

        $this->actingAs($alice, 'sanctum')->postJson("/api/conversations/{$c->id}/typing");
        $this->actingAs($bob, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertJsonPath('data.status', 'none');

        $this->assertDatabaseCount('typing_agreements', 0);
    }

    public function test_typing_between_two_numbers_that_both_allow_it_cannot_be_removed(): void
    {
        ['alice' => $alice, 'aliceNumber' => $a, 'conversation' => $c] = $this->pair(true, true);
        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id, 'accepted_at' => now()]);

        $this->actingAs($alice, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Both numbers allow typing, so this agreement cannot be removed. Turn off "Allow typing in chat" on your number instead.');

        $this->assertDatabaseCount('typing_agreements', 1);
    }

    // ── The setting changing afterwards ──────────────────────────────────────

    public function test_turning_typing_on_accepts_requests_waiting_for_that_number_only(): void
    {
        ['bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair();
        $incoming = TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id]);

        $carol = Number::factory()->create();
        $outgoing = TypingAgreement::create([
            'conversation_id' => Conversation::between($b->id, $carol->id)->id,
            'requested_by_number_id' => $b->id,
        ]);

        $this->actingAs($bob, 'sanctum')->patchJson("/api/numbers/{$b->id}", ['allow_typing' => true])
            ->assertOk()
            ->assertJsonPath('data.allow_typing', true);

        $this->assertNotNull($incoming->fresh()->accepted_at);
        $this->assertNull($outgoing->fresh()->accepted_at);
    }

    public function test_turning_typing_off_ends_the_automatic_agreement_but_keeps_an_accepted_one(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b] = $this->pair(true, true);
        $carolNumber = Number::factory()->allowsTyping()->create();
        $withCarol = Conversation::between($a->id, $carolNumber->id);
        TypingAgreement::create(['conversation_id' => $withCarol->id, 'requested_by_number_id' => $carolNumber->id, 'accepted_at' => now()]);

        $this->actingAs($alice, 'sanctum')->patchJson("/api/numbers/{$a->id}", ['allow_typing' => false])->assertOk();

        $this->typeAs($bob, $b, $a)->assertStatus(422)->assertJsonPath('message', self::REFUSED);
        $this->typeAs($alice, $a, $carolNumber)->assertCreated();
    }

    public function test_allow_typing_must_be_a_boolean_and_defaults_to_off(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->postJson('/api/numbers', ['number' => '+37060099999'])
            ->assertCreated()
            ->assertJsonPath('data.allow_typing', false);

        $number = $owner->numbers()->first();
        $this->actingAs($owner, 'sanctum')->patchJson("/api/numbers/{$number->id}", ['allow_typing' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allow_typing']);
    }

    // ── Who may manage it ────────────────────────────────────────────────────

    public function test_an_assistant_cannot_manage_the_agreement_but_can_type_once_it_is_active(): void
    {
        ['bobNumber' => $b, 'aliceNumber' => $a, 'conversation' => $c] = $this->pair();
        $assistant = User::factory()->create();
        Delegate::create(['number_id' => $b->id, 'assistant_user_id' => $assistant->id]);
        $message = $c->messages()->first();

        $this->actingAs($assistant, 'sanctum')->getJson("/api/conversations/{$c->id}/typing")
            ->assertOk()
            ->assertJsonPath('data.can_request', false);
        $this->actingAs($assistant, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")
            ->assertForbidden();

        $this->actingAs($assistant, 'sanctum')->postJson("/api/messages/{$message->id}/reply", ['body' => 'Bob is out'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::REFUSED);

        $agreement = TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id]);
        $this->actingAs($assistant, 'sanctum')->postJson("/api/conversations/{$c->id}/typing/accept")
            ->assertForbidden();

        $agreement->update(['accepted_at' => now()]);
        $this->actingAs($assistant, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")
            ->assertForbidden();
        $this->actingAs($assistant, 'sanctum')->postJson("/api/messages/{$message->id}/reply", ['body' => 'Bob is out'])
            ->assertCreated();
    }

    public function test_someone_outside_the_conversation_is_forbidden_and_a_guest_is_unauthenticated(): void
    {
        ['conversation' => $c] = $this->pair();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/conversations/{$c->id}/typing")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/conversations/{$c->id}/typing")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/conversations/{$c->id}/typing/accept")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/conversations/{$c->id}/typing")->assertForbidden();
        $this->assertDatabaseCount('typing_agreements', 0);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/conversations/{$c->id}/typing")->assertUnauthorized();
        $this->postJson("/api/conversations/{$c->id}/typing")->assertUnauthorized();
    }

    // ── What sending needs ───────────────────────────────────────────────────

    public function test_without_typing_only_an_exact_template_can_be_sent(): void
    {
        ['alice' => $alice, 'aliceNumber' => $a, 'bobNumber' => $b, 'template' => $t] = $this->pair();

        $this->actingAs($alice, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $a->id, 'receiver_number_id' => $b->id,
            'template_id' => $t->id, 'body' => 'Can you talk? Now?',
        ])->assertStatus(422)->assertJsonPath('message', self::REFUSED);

        $this->actingAs($alice, 'sanctum')->postJson('/api/messages', [
            'sender_number_id' => $a->id, 'receiver_number_id' => $b->id,
            'template_id' => $t->id, 'body' => 'Can you talk?',
        ])->assertCreated()->assertJsonPath('data.template.id', $t->id);
    }

    public function test_a_first_message_to_a_new_number_can_be_typed_only_when_both_allow_typing(): void
    {
        $owner = User::factory()->create();
        $mine = Number::factory()->for($owner)->allowsTyping()->create();
        $closed = Number::factory()->create();
        $open = Number::factory()->allowsTyping()->create();

        $this->typeAs($owner, $mine, $closed)->assertStatus(422);
        $this->typeAs($owner, $mine, $open)->assertCreated();

        // The refused send left no empty conversation behind.
        $this->assertNull(Conversation::findBetween($mine->id, $closed->id));
    }

    public function test_a_typed_reply_needs_typing_but_a_template_reply_does_not(): void
    {
        ['bob' => $bob, 'aliceNumber' => $a, 'conversation' => $c, 'template' => $prompt] = $this->pair();
        $answer = MessageTemplate::factory()->for($prompt->category, 'category')->reply()->create(['body' => 'On my way']);
        $prompt->replyTemplates()->attach($answer->id);
        $message = $c->messages()->first();

        $this->actingAs($bob, 'sanctum')->postJson("/api/messages/{$message->id}/reply", ['body' => 'Five minutes'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::REFUSED);
        $this->actingAs($bob, 'sanctum')->postJson("/api/messages/{$message->id}/reply", ['template_id' => $answer->id])
            ->assertCreated();

        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id, 'accepted_at' => now()]);
        $this->actingAs($bob, 'sanctum')->postJson("/api/messages/{$message->id}/reply", ['body' => 'Five minutes'])
            ->assertCreated();
    }

    // ── Where clients read the state ─────────────────────────────────────────

    public function test_conversation_lists_and_thread_polling_carry_the_viewers_typing_state(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'conversation' => $c] = $this->pair();
        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id]);

        $this->actingAs($alice, 'sanctum')->getJson('/api/conversations')
            ->assertJsonPath('data.0.typing.status', 'pending')
            ->assertJsonPath('data.0.typing.requested_by_me', true);

        $this->actingAs($bob, 'sanctum')->getJson('/api/conversations/updates?since='.urlencode(now()->subDay()->toIso8601String()))
            ->assertJsonPath('data.0.typing.can_accept', true);

        $this->actingAs($bob, 'sanctum')->getJson("/api/conversations/{$c->id}/messages")
            ->assertJsonPath('meta.typing.status', 'pending')
            ->assertJsonPath('meta.typing.can_accept', true);
    }

    public function test_the_compose_check_answers_for_two_numbers_with_or_without_a_conversation(): void
    {
        ['alice' => $alice, 'bob' => $bob, 'aliceNumber' => $a, 'bobNumber' => $b, 'conversation' => $c] = $this->pair();
        $stranger = Number::factory()->allowsTyping()->create();

        $this->actingAs($alice, 'sanctum')->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id={$b->id}")
            ->assertOk()
            ->assertExactJson(['data' => ['typing_allowed' => false]]);

        TypingAgreement::create(['conversation_id' => $c->id, 'requested_by_number_id' => $a->id, 'accepted_at' => now()]);
        $this->actingAs($alice, 'sanctum')->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id={$b->id}")
            ->assertJsonPath('data.typing_allowed', true);

        $a->update(['allow_typing' => true]);
        $this->actingAs($alice, 'sanctum')->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id={$stranger->id}")
            ->assertJsonPath('data.typing_allowed', true);

        // Only from your own number, and both numbers must exist.
        $this->actingAs($bob, 'sanctum')->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id={$b->id}")
            ->assertForbidden();
        $this->actingAs($alice, 'sanctum')->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id=999999")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receiver_number_id']);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/messages/typing?sender_number_id={$a->id}&receiver_number_id={$b->id}")->assertUnauthorized();
    }
}
