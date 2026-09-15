<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The admin template form edits message_template_replies from either side: a
 * prompt picks its replies, a reply template picks the prompts it answers.
 */
class AdminTemplateMappingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function template(string $category, string $body, array $attributes = []): MessageTemplate
    {
        return MessageTemplate::factory()
            ->for(MessageCategory::firstOrCreate(['name' => $category], ['is_active' => true]), 'category')
            ->create(['body' => $body] + $attributes);
    }

    /** What the form posts for a template, with the given fields changed. */
    private function form(MessageTemplate $template, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $template->category_id,
            'body' => $template->body,
            'is_reply' => $template->is_reply ? 1 : 0,
            'is_active' => $template->is_active ? 1 : 0,
        ], $overrides);
    }

    /** Reply template ids mapped to a prompt, in the order clients are shown them. */
    private function mappedReplies(MessageTemplate $prompt): array
    {
        return DB::table('message_template_replies')
            ->where('prompt_template_id', $prompt->id)
            ->orderBy('sort_order')
            ->pluck('reply_template_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ── The form ─────────────────────────────────────────────────────────────

    public function test_a_prompt_form_lists_reply_templates_by_category_with_its_mapping_checked(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?');
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $gotIt = $this->template('Status', 'OK, got it', ['is_reply' => true]);
        $prompt->replyTemplates()->attach($busy->id);

        $this->actingAs($this->admin())
            ->get(route('admin.templates.edit', $prompt))
            ->assertStatus(200)
            ->assertSeeInOrder(['Replies this template offers', 'Meeting', 'I am busy', 'Status', 'OK, got it'])
            ->assertSee('name="reply_template_ids[]" value="'.$busy->id.'" checked', false)
            ->assertDontSee('value="'.$gotIt->id.'" checked', false);
    }

    public function test_a_reply_template_form_lists_the_prompts_it_answers(): void
    {
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $canYouTalk = $this->template('Meeting', 'Can you talk?');
        $runningLate = $this->template('Status', 'Running late');
        $canYouTalk->replyTemplates()->attach($busy->id);

        $this->actingAs($this->admin())
            ->get(route('admin.templates.edit', $busy))
            ->assertStatus(200)
            ->assertSeeInOrder(['Prompts this reply answers', 'Meeting', 'Can you talk?', 'Status', 'Running late'])
            ->assertSee('name="prompt_template_ids[]" value="'.$canYouTalk->id.'" checked', false)
            ->assertDontSee('value="'.$runningLate->id.'" checked', false);
    }

    public function test_the_create_form_offers_both_sides_of_the_mapping(): void
    {
        $this->template('Meeting', 'Can you talk?');
        $this->template('Meeting', 'I am busy', ['is_reply' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.templates.create'))
            ->assertStatus(200)
            ->assertSee('Replies this template offers')
            ->assertSee('Prompts this reply answers')
            ->assertSee('I am busy')
            ->assertSee('Can you talk?');
    }

    public function test_the_index_says_deactivate_not_delete(): void
    {
        $active = $this->template('Meeting', 'Can you talk?');
        $inactive = $this->template('Meeting', 'Call me back', ['is_active' => false]);

        $this->actingAs($this->admin())
            ->get(route('admin.templates.index'))
            ->assertStatus(200)
            ->assertSee('Deactivate this template?')
            ->assertSee('title="Deactivate"', false)
            ->assertDontSee('Delete this template?')
            ->assertDontSee('title="Delete"', false)
            // Nothing to deactivate on a template that already is inactive.
            ->assertSee('action="'.route('admin.templates.destroy', $active).'"', false)
            ->assertDontSee('action="'.route('admin.templates.destroy', $inactive).'"', false);
    }

    // ── Saving from both directions ──────────────────────────────────────────

    public function test_saving_a_prompt_replaces_its_replies_and_keeps_the_existing_order(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?');
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $meet = $this->template('Meeting', "Let's meet at 18:00", ['is_reply' => true]);
        $gotIt = $this->template('Status', 'OK, got it', ['is_reply' => true]);
        $prompt->replyTemplates()->attach([
            $busy->id => ['sort_order' => 0],
            $meet->id => ['sort_order' => 1],
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, [
                'reply_template_ids' => [$gotIt->id, $meet->id],
            ]))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionMissing('warning');

        // "I am busy" is gone, "Let's meet" keeps its place, and the reply from
        // another category is accepted and added after it.
        $this->assertSame([$meet->id, $gotIt->id], $this->mappedReplies($prompt));

        // Which is exactly what a client is now offered for this prompt.
        $message = Message::factory()->create(['template_id' => $prompt->id]);
        $this->assertSame([$meet->id, $gotIt->id], $message->availableReplyTemplates()->pluck('id')->all());
    }

    public function test_saving_a_reply_template_replaces_the_prompts_it_answers(): void
    {
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $oneMoment = $this->template('Meeting', 'Sure, one moment', ['is_reply' => true]);
        $canYouTalk = $this->template('Meeting', 'Can you talk?');
        $callMeBack = $this->template('Meeting', 'Call me back');
        $runningLate = $this->template('Status', 'Running late');
        $canYouTalk->replyTemplates()->attach($busy->id, ['sort_order' => 0]);
        $callMeBack->replyTemplates()->attach($oneMoment->id, ['sort_order' => 0]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $busy), $this->form($busy, [
                'prompt_template_ids' => [$callMeBack->id, $runningLate->id],
            ]))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionMissing('warning');

        $this->assertSame([], $this->mappedReplies($canYouTalk));
        // Added at the end of each prompt's own list.
        $this->assertSame([$oneMoment->id, $busy->id], $this->mappedReplies($callMeBack));
        $this->assertSame([$busy->id], $this->mappedReplies($runningLate));
    }

    public function test_creating_templates_saves_their_mapping_from_either_side(): void
    {
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $runningLate = $this->template('Status', 'Running late');
        $admin = $this->admin();
        $category = MessageCategory::where('name', 'Meeting')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.templates.store'), [
                'category_id' => $category->id,
                'body' => 'Can you talk?',
                'is_active' => 1,
                'reply_template_ids' => [$busy->id],
            ])
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionMissing('warning');

        $this->actingAs($admin)
            ->post(route('admin.templates.store'), [
                'category_id' => $category->id,
                'body' => 'On my way',
                'is_reply' => 1,
                'is_active' => 1,
                'prompt_template_ids' => [$runningLate->id],
            ])
            ->assertRedirect(route('admin.templates.index'));

        $canYouTalk = MessageTemplate::where('body', 'Can you talk?')->firstOrFail();
        $onMyWay = MessageTemplate::where('body', 'On my way')->firstOrFail();

        $this->assertSame([$busy->id], $this->mappedReplies($canYouTalk));
        $this->assertSame([$onMyWay->id], $this->mappedReplies($runningLate));
    }

    public function test_a_prompt_template_cannot_be_chosen_as_a_reply(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?');
        $otherPrompt = $this->template('Meeting', 'Call me back');

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, [
                'reply_template_ids' => [$otherPrompt->id],
            ]))
            ->assertSessionHasErrors('reply_template_ids.0');

        $this->assertDatabaseCount('message_template_replies', 0);
    }

    // ── Warnings ─────────────────────────────────────────────────────────────

    public function test_creating_a_prompt_with_no_replies_warns_that_it_offers_none(): void
    {
        $category = MessageCategory::factory()->create();

        $response = $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), [
                'category_id' => $category->id,
                'body' => 'Can you talk?',
                'is_active' => 1,
            ]);

        $created = MessageTemplate::where('body', 'Can you talk?')->firstOrFail();

        $response->assertRedirect(route('admin.templates.edit', $created).'#template-mapping')
            ->assertSessionHas('success', 'Template created.')
            ->assertSessionHas('warning', fn ($warning) => str_starts_with($warning, 'Saved, but')
                && str_contains($warning, 'will offer no replies'));
    }

    public function test_a_prompt_mapped_only_to_inactive_replies_still_warns(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?');
        $retired = $this->template('Meeting', 'I am busy', ['is_reply' => true, 'is_active' => false]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, [
                'reply_template_ids' => [$retired->id],
            ]))
            ->assertRedirect(route('admin.templates.edit', $prompt).'#template-mapping')
            ->assertSessionHas('warning');

        // The choice is still saved; it just offers nothing while that reply is inactive.
        $this->assertSame([$retired->id], $this->mappedReplies($prompt));
    }

    public function test_reactivating_a_prompt_with_no_replies_prompts_the_admin_to_set_them(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?', ['is_active' => false]);

        $this->actingAs($this->admin())
            ->followingRedirects()
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, ['is_active' => 1]))
            ->assertStatus(200)
            ->assertSee('Reactivated, but no active reply templates are mapped to this template')
            ->assertSee('Go to the mapping')
            ->assertSee('Replies this template offers');

        $this->assertTrue($prompt->fresh()->is_active);
    }

    public function test_reactivating_a_reply_template_that_answers_no_prompt_prompts_the_admin(): void
    {
        $reply = $this->template('Meeting', 'I am busy', ['is_reply' => true, 'is_active' => false]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $reply), $this->form($reply, ['is_active' => 1]))
            ->assertRedirect(route('admin.templates.edit', $reply).'#template-mapping')
            ->assertSessionHas('warning', fn ($warning) => str_starts_with($warning, 'Reactivated, but this reply template is not mapped to any prompt'));
    }

    public function test_reactivating_a_template_that_still_has_mappings_does_not_warn(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?', ['is_active' => false]);
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $prompt->replyTemplates()->attach($busy->id);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, [
                'is_active' => 1,
                'reply_template_ids' => [$busy->id],
            ]))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionMissing('warning');
    }

    public function test_saving_an_active_reply_template_with_no_prompts_warns(): void
    {
        $reply = $this->template('Meeting', 'I am busy', ['is_reply' => true]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $reply), $this->form($reply))
            ->assertRedirect(route('admin.templates.edit', $reply).'#template-mapping')
            ->assertSessionHas('success', 'Template updated.')
            ->assertSessionHas('warning', fn ($warning) => str_starts_with($warning, 'Saved, but this reply template is not mapped to any prompt')
                && str_contains($warning, 'will not be offered as an answer'));
    }

    public function test_an_inactive_reply_template_saved_with_no_prompts_is_not_warned_about(): void
    {
        // It isn't offered to anyone while inactive, so an empty mapping isn't news.
        $reply = $this->template('Meeting', 'I am busy', ['is_reply' => true, 'is_active' => false]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $reply), $this->form($reply))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionMissing('warning');
    }

    // ── Flipping "Reply only" ────────────────────────────────────────────────

    public function test_a_prompt_flipped_to_a_reply_template_stops_offering_its_old_answers(): void
    {
        $prompt = $this->template('Meeting', 'Can you talk?');
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $prompt->replyTemplates()->attach($busy->id);

        $recipient = User::factory()->create();
        $message = Message::factory()->create([
            'sender_number_id' => Number::factory()->create()->id,
            'receiver_number_id' => Number::factory()->for($recipient)->create()->id,
            'template_id' => $prompt->id,
        ]);
        $this->assertSame([$busy->id], $message->availableReplyTemplates()->pluck('id')->all());

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, ['is_reply' => 1]));

        // No row left on its old side for the form to hide and the API to read.
        $this->assertSame([], $this->mappedReplies($prompt));
        $this->assertTrue($message->fresh()->availableReplyTemplates()->isEmpty());

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/messages/{$message->id}/reply", ['template_id' => $busy->id])
            ->assertStatus(422);
    }

    public function test_a_reply_template_flipped_to_a_prompt_no_longer_answers_its_old_prompts(): void
    {
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);
        $canYouTalk = $this->template('Meeting', 'Can you talk?');
        $gotIt = $this->template('Status', 'OK, got it', ['is_reply' => true]);
        $canYouTalk->replyTemplates()->attach($busy->id);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $busy), $this->form($busy, [
                'is_reply' => 0,
                'reply_template_ids' => [$gotIt->id],
            ]))
            ->assertRedirect(route('admin.templates.index'));

        $this->assertSame([], $this->mappedReplies($canYouTalk));
        // Its new side, chosen in the same save, is kept.
        $this->assertSame([$gotIt->id], $this->mappedReplies($busy));
    }

    public function test_the_template_show_route_is_gone(): void
    {
        // It rendered a view that never existed; nothing linked to it.
        $template = $this->template('Meeting', 'Can you talk?');

        $this->assertFalse(Route::has('admin.templates.show'));

        $this->actingAs($this->admin())
            ->get('/admin/templates/'.$template->id)
            ->assertStatus(405);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_a_non_admin_gets_403_and_changes_no_mapping(): void
    {
        $user = User::factory()->create();
        $prompt = $this->template('Meeting', 'Can you talk?');
        $busy = $this->template('Meeting', 'I am busy', ['is_reply' => true]);

        $this->actingAs($user)->get(route('admin.templates.edit', $prompt))->assertStatus(403);
        $this->actingAs($user)->get(route('admin.templates.create'))->assertStatus(403);

        $this->actingAs($user)
            ->put(route('admin.templates.update', $prompt), $this->form($prompt, ['reply_template_ids' => [$busy->id]]))
            ->assertStatus(403);

        $this->actingAs($user)
            ->post(route('admin.templates.store'), [
                'category_id' => $prompt->category_id,
                'body' => 'Call me back',
                'is_active' => 1,
                'reply_template_ids' => [$busy->id],
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('message_template_replies', 0);
        $this->assertDatabaseCount('message_templates', 2);
    }
}
