<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reply options come from explicit prompt -> reply rows in message_template_replies,
 * which the migration backfills from the old same-category rule.
 */
class MessageTemplateReplyMappingTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_15_000001_create_message_template_replies_table';

    /** A message from $template, received by a number $viewer owns. */
    private function receivedMessage(?MessageTemplate $template, User $viewer, array $attributes = []): Message
    {
        return Message::factory()->create($attributes + [
            'sender_number_id' => Number::factory()->create()->id,
            'receiver_number_id' => Number::factory()->for($viewer)->create()->id,
            'template_id' => $template?->id,
        ]);
    }

    public function test_index_names_fit_within_the_mysql_identifier_limit(): void
    {
        // sqlite accepts any length, so the suite can't fail on this by itself:
        // the default unique-index name here is 68 characters, and MySQL refused
        // it partway through migrating, leaving a half-built table behind.
        $names = collect(DB::select("PRAGMA index_list('message_template_replies')"))->pluck('name');

        $this->assertContains('template_replies_prompt_reply_unique', $names);

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), "Index name too long for MySQL: {$name}");
        }
    }

    public function test_the_backfill_maps_each_active_prompt_to_the_active_replies_in_its_category(): void
    {
        $meeting = MessageCategory::factory()->create();
        $status = MessageCategory::factory()->create();

        $canYouTalk = MessageTemplate::factory()->for($meeting, 'category')->create();
        $callMeBack = MessageTemplate::factory()->for($meeting, 'category')->create();
        $imBusy = MessageTemplate::factory()->for($meeting, 'category')->reply()->create();
        $oneMoment = MessageTemplate::factory()->for($meeting, 'category')->reply()->create();
        MessageTemplate::factory()->for($meeting, 'category')->reply()->create(['is_active' => false]);
        MessageTemplate::factory()->for($meeting, 'category')->create(['is_active' => false]);
        $runningLate = MessageTemplate::factory()->for($status, 'category')->create();
        $gotIt = MessageTemplate::factory()->for($status, 'category')->reply()->create();

        $this->rerunMigration(self::MIGRATION);

        $mappings = DB::table('message_template_replies')
            ->orderBy('prompt_template_id')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => [(int) $row->prompt_template_id, (int) $row->reply_template_id, (int) $row->sort_order])
            ->all();

        // No inactive template on either side, no reply template as a prompt, and
        // nothing across categories.
        $this->assertSame([
            [$canYouTalk->id, $imBusy->id, 0],
            [$canYouTalk->id, $oneMoment->id, 1],
            [$callMeBack->id, $imBusy->id, 0],
            [$callMeBack->id, $oneMoment->id, 1],
            [$runningLate->id, $gotIt->id, 0],
        ], $mappings);
    }

    public function test_after_the_backfill_messages_are_offered_what_the_category_rule_offered(): void
    {
        $viewer = User::factory()->create();
        $meeting = MessageCategory::factory()->create();
        $status = MessageCategory::factory()->create();
        $empty = MessageCategory::factory()->create();

        $canYouTalk = MessageTemplate::factory()->for($meeting, 'category')->create();
        $imBusy = MessageTemplate::factory()->for($meeting, 'category')->reply()->create(['body' => 'I am busy']);
        MessageTemplate::factory()->for($meeting, 'category')->reply()->create(['is_active' => false]);
        $oneMoment = MessageTemplate::factory()->for($meeting, 'category')->reply()->create(['body' => 'Sure, one moment']);
        $runningLate = MessageTemplate::factory()->for($status, 'category')->create();
        MessageTemplate::factory()->for($status, 'category')->reply()->create();
        $noAnswers = MessageTemplate::factory()->for($empty, 'category')->create();

        $messages = collect([$canYouTalk, $runningLate, $noAnswers])
            ->map(fn ($template) => $this->receivedMessage($template, $viewer));

        $this->rerunMigration(self::MIGRATION);

        foreach ($messages as $message) {
            // The lookup availableReplyTemplates() made before the mapping existed.
            $byCategory = $message->template->category->templates()
                ->where('is_reply', true)
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $this->assertSame($byCategory, $message->availableReplyTemplates()->pluck('id')->all());
        }

        // And the shape Android reads is exactly what it was: only id and body, in
        // order — no pivot columns leaking out of the new relation.
        $offered = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/messages/{$messages[0]->id}")
            ->assertStatus(200)
            ->json('data.reply_templates');

        $this->assertSame([
            ['id' => $imBusy->id, 'body' => 'I am busy'],
            ['id' => $oneMoment->id, 'body' => 'Sure, one moment'],
        ], $offered);
    }

    public function test_a_pruned_mapping_is_no_longer_offered_and_only_its_own_prompt_changes(): void
    {
        $viewer = User::factory()->create();
        $category = MessageCategory::factory()->create();
        $canYouTalk = MessageTemplate::factory()->for($category, 'category')->create();
        $callMeBack = MessageTemplate::factory()->for($category, 'category')->create();
        $imBusy = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $letsMeet = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $oneMoment = MessageTemplate::factory()->for($category, 'category')->reply()->create();

        $fromCanYouTalk = $this->receivedMessage($canYouTalk, $viewer);
        $fromCallMeBack = $this->receivedMessage($callMeBack, $viewer);

        $this->rerunMigration(self::MIGRATION);

        // "Let's meet at 18:00" doesn't answer "Can you talk?".
        $canYouTalk->replyTemplates()->detach($letsMeet->id);

        $this->assertSame(
            [$imBusy->id, $oneMoment->id],
            $fromCanYouTalk->availableReplyTemplates()->pluck('id')->all()
        );

        // Options follow the message's own template, not its category.
        $this->assertSame(
            [$imBusy->id, $letsMeet->id, $oneMoment->id],
            $fromCallMeBack->availableReplyTemplates()->pluck('id')->all()
        );

        $offered = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/messages/{$fromCanYouTalk->id}")
            ->assertStatus(200)
            ->json('data.reply_templates');

        $this->assertSame([$imBusy->id, $oneMoment->id], array_column($offered, 'id'));
    }

    public function test_an_inactive_reply_template_is_not_offered_while_still_mapped(): void
    {
        $viewer = User::factory()->create();
        $category = MessageCategory::factory()->create();
        $prompt = MessageTemplate::factory()->for($category, 'category')->create();
        $kept = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $retired = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $message = $this->receivedMessage($prompt, $viewer);

        $this->rerunMigration(self::MIGRATION);
        $retired->update(['is_active' => false]);

        $this->assertDatabaseHas('message_template_replies', [
            'prompt_template_id' => $prompt->id,
            'reply_template_id' => $retired->id,
        ]);
        $this->assertSame([$kept->id], $message->availableReplyTemplates()->pluck('id')->all());
    }

    public function test_a_mapped_template_that_is_not_a_reply_template_is_not_offered(): void
    {
        // Kept from the category rule: a mapping can't make a prompt template
        // count as an answer.
        $viewer = User::factory()->create();
        $category = MessageCategory::factory()->create();
        $prompt = MessageTemplate::factory()->for($category, 'category')->create();
        $otherPrompt = MessageTemplate::factory()->for($category, 'category')->create();
        $prompt->replyTemplates()->attach($otherPrompt->id);

        $message = $this->receivedMessage($prompt, $viewer);

        $this->assertTrue($message->availableReplyTemplates()->isEmpty());
    }

    public function test_changing_is_reply_anywhere_clears_only_the_old_side_of_that_template(): void
    {
        // Enforced on the model, so it holds outside the admin form too.
        $category = MessageCategory::factory()->create();
        $prompt = MessageTemplate::factory()->for($category, 'category')->create();
        $otherPrompt = MessageTemplate::factory()->for($category, 'category')->create();
        $reply = MessageTemplate::factory()->for($category, 'category')->reply()->create();
        $prompt->replyTemplates()->attach($reply->id);
        $otherPrompt->replyTemplates()->attach($reply->id);

        // A save that doesn't flip is_reply leaves the mapping alone.
        $prompt->update(['body' => 'Reworded']);
        $this->assertDatabaseCount('message_template_replies', 2);

        $prompt->update(['is_reply' => true]);
        $this->assertDatabaseMissing('message_template_replies', ['prompt_template_id' => $prompt->id]);
        $this->assertDatabaseHas('message_template_replies', [
            'prompt_template_id' => $otherPrompt->id,
            'reply_template_id' => $reply->id,
        ]);

        $reply->update(['is_reply' => false]);
        $this->assertDatabaseCount('message_template_replies', 0);
    }

    public function test_a_message_with_no_template_has_no_reply_options(): void
    {
        $viewer = User::factory()->create();
        $category = MessageCategory::factory()->create();
        MessageTemplate::factory()->for($category, 'category')->create(['body' => 'Can you talk?']);
        MessageTemplate::factory()->for($category, 'category')->reply()->create();

        // Same words as the template, but typed: nothing ties it to the mapping.
        $typed = $this->receivedMessage(null, $viewer, ['body' => 'Can you talk?']);

        $this->rerunMigration(self::MIGRATION);

        $this->assertTrue($typed->availableReplyTemplates()->isEmpty());

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/messages/{$typed->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.template', null)
            ->assertJsonPath('data.reply_templates', []);
    }
}
