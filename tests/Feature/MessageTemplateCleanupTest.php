<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use App\Models\Number;
use App\Models\User;
use Database\Seeders\MessageCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The seed-data cleanup migration and the idempotent MessageCategorySeeder.
 *
 * Most tests start from the state the dev DB was in: the old seeder run three
 * times, the slice-1 backfill over all three copies, and one copy of "Can you
 * talk?" deactivated afterwards.
 */
class MessageTemplateCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_15_000002_clean_up_seeded_message_templates';

    private const MAPPING_MIGRATION = '2026_09_15_000001_create_message_template_replies_table';

    /** What the old seeder created on each run: name => [icon, prompts, replies]. */
    private const LEGACY_SEED = [
        'Meeting' => ['fas fa-calendar', ['Can you talk?', 'Call me back'], ['I am busy', "Let's meet at 18:00", 'Sure, one moment']],
        'Status' => ['fas fa-info-circle', ['I am on my way', 'Running late'], ['Arrived', 'OK, got it']],
        'Urgent' => ['fas fa-exclamation-triangle', ['Call me urgently', 'Emergency - contact me'], ['On my way', 'Cannot respond now']],
    ];

    /** The approved set, written out independently of the seeder and the migration. */
    private const APPROVED_CATEGORIES = [
        'Meeting' => [
            'icon' => 'fas fa-calendar',
            'prompts' => ['Can you talk?', 'Call me back', 'Can we meet today?'],
            'replies' => ['Sure, one moment', 'I am busy', "Let's meet at 18:00", "I'll call you later", 'Not today, sorry'],
        ],
        'Status' => [
            'icon' => 'fas fa-info-circle',
            'prompts' => ['I am on my way', 'Running late', 'I have arrived'],
            'replies' => ['OK, got it', 'See you soon', 'No problem, take your time'],
        ],
        'Urgent' => [
            'icon' => 'fas fa-exclamation-triangle',
            'prompts' => ['Call me urgently', 'Emergency - contact me'],
            'replies' => ['On my way', 'Cannot respond now', 'Calling you now'],
        ],
    ];

    private const APPROVED_REPLIES = [
        'Can you talk?' => ['Sure, one moment', "I'll call you later", "Let's meet at 18:00", 'I am busy'],
        'Call me back' => ["I'll call you later", 'Sure, one moment', "Let's meet at 18:00", 'I am busy'],
        'Can we meet today?' => ["Let's meet at 18:00", 'Not today, sorry', 'I am busy'],
        'I am on my way' => ['See you soon', 'OK, got it'],
        'Running late' => ['No problem, take your time', 'OK, got it'],
        'I have arrived' => ['Sure, one moment', 'OK, got it'],
        'Call me urgently' => ['Calling you now', 'On my way', 'Cannot respond now'],
        'Emergency - contact me' => ['Calling you now', 'On my way', 'Cannot respond now'],
    ];

    /**
     * One run of the old seeder.
     *
     * Each template keeps the category it was created in as a loaded relation,
     * so a test can still read its name after the migration deletes that row.
     *
     * @return array<string, MessageTemplate> body => template
     */
    private function legacySeedRun(): array
    {
        $templates = [];

        foreach (self::LEGACY_SEED as $name => [$icon, $prompts, $replies]) {
            $category = MessageCategory::create(['name' => $name, 'icon' => $icon]);

            foreach ([false => $prompts, true => $replies] as $isReply => $bodies) {
                foreach ($bodies as $body) {
                    $templates[$body] = $category->templates()
                        ->create(['body' => $body, 'is_reply' => (bool) $isReply])
                        ->setRelation('category', $category);
                }
            }
        }

        return $templates;
    }

    /**
     * The dev DB before the cleanup.
     *
     * @return array<int, array<string, MessageTemplate>> one body => template map per copy
     */
    private function devDatabaseBeforeCleanup(): array
    {
        $copies = [$this->legacySeedRun(), $this->legacySeedRun(), $this->legacySeedRun()];

        $this->rerunMigration(self::MAPPING_MIGRATION);
        $copies[2]['Can you talk?']->update(['is_active' => false]);

        return $copies;
    }

    /** Active categories, templates and mappings, keyed by text so ids don't matter. */
    private function activeSet(): array
    {
        $categories = MessageCategory::where('is_active', true)->get()
            ->mapWithKeys(fn (MessageCategory $category) => [$category->name => [
                'icon' => $category->icon,
                'prompts' => $category->templates()->where('is_active', true)->where('is_reply', false)->pluck('body')->all(),
                'replies' => $category->templates()->where('is_active', true)->where('is_reply', true)->pluck('body')->all(),
            ]])
            ->all();

        // Every mapped reply, active or not, so a leftover row to a retired
        // template shows up here.
        $replies = MessageTemplate::where('is_active', true)->where('is_reply', false)->get()
            ->mapWithKeys(fn (MessageTemplate $prompt) => [$prompt->body => $prompt->replyTemplates->pluck('body')->all()])
            ->all();

        return $this->normalised($categories, $replies);
    }

    /** Template lists sorted and maps keyed in order; reply lists keep their display order. */
    private function normalised(array $categories, array $replies): array
    {
        foreach ($categories as &$set) {
            sort($set['prompts'], SORT_STRING);
            sort($set['replies'], SORT_STRING);
        }

        ksort($categories, SORT_STRING);
        ksort($replies, SORT_STRING);

        return ['categories' => $categories, 'replies' => $replies];
    }

    private function approvedSet(): array
    {
        return $this->normalised(self::APPROVED_CATEGORIES, self::APPROVED_REPLIES);
    }

    /** Every template and mapping row, ids included, without timestamps. */
    private function fullState(): array
    {
        return [
            'categories' => DB::table('message_categories')->orderBy('id')->get(['id', 'name', 'icon', 'is_active'])->map(fn ($row) => (array) $row)->all(),
            'templates' => DB::table('message_templates')->orderBy('id')->get(['id', 'category_id', 'body', 'is_reply', 'is_active'])->map(fn ($row) => (array) $row)->all(),
            'replies' => DB::table('message_template_replies')->orderBy('prompt_template_id')->orderBy('sort_order')
                ->get(['prompt_template_id', 'reply_template_id', 'sort_order'])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function test_it_does_nothing_on_an_empty_database(): void
    {
        $this->rerunMigration(self::MIGRATION);

        $this->assertDatabaseCount('message_categories', 0);
        $this->assertDatabaseCount('message_templates', 0);
        $this->assertDatabaseCount('message_template_replies', 0);
    }

    public function test_duplicate_categories_are_merged_into_the_oldest_and_their_templates_moved_not_deleted(): void
    {
        $copies = $this->devDatabaseBeforeCleanup();
        $viewer = User::factory()->create();
        $sent = Message::factory()->create([
            'receiver_number_id' => Number::factory()->for($viewer)->create()->id,
            'template_id' => $copies[2]['Call me urgently']->id,
        ]);
        $survivors = MessageCategory::orderBy('id')->take(3)->pluck('id', 'name')->all();
        $templateIds = MessageTemplate::orderBy('id')->pluck('id')->all();

        $this->rerunMigration(self::MIGRATION);

        $this->assertSame($survivors, MessageCategory::orderBy('id')->pluck('id', 'name')->all());

        // All 39 original templates are still there, now in the surviving
        // category of the same name, and the third copy's message still names its own.
        $this->assertSame($templateIds, MessageTemplate::whereIn('id', $templateIds)->orderBy('id')->pluck('id')->all());

        foreach ($copies as $copy) {
            foreach ($copy as $template) {
                $this->assertSame($survivors[$template->category->name], $template->fresh()->category_id);
            }
        }

        $this->assertSame($copies[2]['Call me urgently']->id, $sent->fresh()->template_id);

        // Every copy but the first is retired.
        foreach ([$copies[1], $copies[2]] as $copy) {
            foreach ($copy as $template) {
                $this->assertFalse($template->fresh()->is_active, "Duplicate \"{$template->body}\" is still active");
            }
        }
    }

    public function test_messages_sent_with_a_duplicate_template_keep_their_template_and_reply_options(): void
    {
        $copies = $this->devDatabaseBeforeCleanup();
        $viewer = User::factory()->create();
        $mine = Number::factory()->for($viewer)->create();
        $theirs = Number::factory()->create();

        $received = fn (MessageTemplate $template) => Message::factory()->create([
            'sender_number_id' => $theirs->id,
            'receiver_number_id' => $mine->id,
            'template_id' => $template->id,
        ]);

        // The eight dev-DB messages that name a duplicate: six sent with a
        // duplicate prompt, and two replies sent with a duplicate reply template.
        $prompts = [
            'Can you talk?' => $received($copies[1]['Can you talk?']),
            'I am on my way' => $received($copies[1]['I am on my way']),
            'Call me urgently' => $received($copies[1]['Call me urgently']),
            'Emergency - contact me' => $received($copies[1]['Emergency - contact me']),
        ];
        $prompts['Call me urgently (third copy, 1)'] = $received($copies[2]['Call me urgently']);
        $prompts['Call me urgently (third copy, 2)'] = $received($copies[2]['Call me urgently']);

        $replies = collect([
            [$prompts['Can you talk?'], $copies[1]['Sure, one moment']],
            [$prompts['I am on my way'], $copies[1]['Arrived']],
        ])->map(fn ($pair) => Message::factory()->reply($pair[0]->id)->create([
            'sender_number_id' => $mine->id,
            'receiver_number_id' => $theirs->id,
            'template_id' => $pair[1]->id,
        ]));

        $this->rerunMigration(self::MIGRATION);

        foreach ($prompts as $label => $message) {
            $body = $message->template->body;
            $kept = $copies[0][$body];

            $offered = $this->actingAs($viewer, 'sanctum')
                ->getJson("/api/messages/{$message->id}")
                ->assertStatus(200)
                ->assertJsonPath('data.template.id', $message->template_id)
                ->assertJsonPath('data.template.body', $body)
                ->assertJsonPath('data.template.category.name', $kept->category->name)
                ->json('data.reply_templates');

            $this->assertNotEmpty($offered, "{$label}: no reply options after the cleanup");
            $this->assertSame(self::APPROVED_REPLIES[$body], array_column($offered, 'body'), $label);

            // The same templates, by id, as a message sent with the copy that was kept.
            $this->assertSame(
                $kept->fresh()->activeReplyTemplates->pluck('id')->all(),
                array_column($offered, 'id'),
                $label
            );
        }

        // And replying with an offered template is accepted.
        $offered = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/messages/{$prompts['Can you talk?']->id}")
            ->json('data.reply_templates');

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/messages/{$prompts['Can you talk?']->id}/reply", ['template_id' => $offered[0]['id']])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Sure, one moment');

        // The replies still show their own template, retired "Arrived" included.
        // A reply template offers nothing, before the cleanup and after it.
        foreach ($replies as $reply) {
            $this->actingAs($viewer, 'sanctum')
                ->getJson("/api/messages/{$reply->id}")
                ->assertStatus(200)
                ->assertJsonPath('data.template.id', $reply->template_id)
                ->assertJsonPath('data.template.body', $reply->body)
                ->assertJsonPath('data.reply_templates', []);
        }
    }

    public function test_the_migration_brings_the_dev_database_to_the_approved_set(): void
    {
        $copies = $this->devDatabaseBeforeCleanup();

        $this->rerunMigration(self::MIGRATION);

        $this->assertSame($this->approvedSet(), $this->activeSet());
        $this->assertDatabaseCount('message_categories', 3);

        // Existing templates were reused, not recreated: 39 plus the 7 new ones.
        $this->assertDatabaseCount('message_templates', 46);
        $this->assertSame(
            $copies[0]['Can you talk?']->id,
            MessageTemplate::where('is_active', true)->where('body', 'Can you talk?')->sole()->id
        );
        $this->assertFalse($copies[0]['Arrived']->fresh()->is_active);
    }

    public function test_the_migration_and_the_seeder_produce_the_same_active_set(): void
    {
        $this->devDatabaseBeforeCleanup();
        $this->rerunMigration(self::MIGRATION);
        $migrated = $this->activeSet();

        DB::table('message_template_replies')->delete();
        DB::table('message_templates')->delete();
        DB::table('message_categories')->delete();

        $this->seed(MessageCategorySeeder::class);

        $this->assertSame($migrated, $this->activeSet());
    }

    public function test_the_seeder_creates_the_approved_set_and_running_it_again_adds_nothing(): void
    {
        $this->seed(MessageCategorySeeder::class);

        $this->assertSame($this->approvedSet(), $this->activeSet());
        $this->assertDatabaseCount('message_categories', 3);
        $this->assertDatabaseCount('message_templates', 19);
        $this->assertDatabaseCount('message_template_replies', 23);
        $afterFirstRun = $this->fullState();

        $this->seed(MessageCategorySeeder::class);

        $this->assertDatabaseCount('message_categories', 3);
        $this->assertDatabaseCount('message_templates', 19);
        $this->assertDatabaseCount('message_template_replies', 23);
        $this->assertSame($afterFirstRun, $this->fullState());
    }

    public function test_the_seeder_adds_nothing_to_a_cleaned_up_database(): void
    {
        $this->devDatabaseBeforeCleanup();
        $this->rerunMigration(self::MIGRATION);
        $cleaned = $this->fullState();

        $this->seed(MessageCategorySeeder::class);

        $this->assertSame($cleaned, $this->fullState());
    }

    public function test_running_the_migration_again_changes_nothing(): void
    {
        $this->devDatabaseBeforeCleanup();
        $this->rerunMigration(self::MIGRATION);
        $once = $this->fullState();

        $this->rerunMigration(self::MIGRATION);

        $this->assertSame($once, $this->fullState());
    }

    public function test_only_exact_seeded_names_and_texts_are_touched(): void
    {
        $this->devDatabaseBeforeCleanup();

        // Not a seeded name: the case differs, which MySQL's collation would ignore.
        $lowercase = MessageCategory::create(['name' => 'meeting', 'icon' => 'fas fa-tag']);
        $lowercasePrompt = $lowercase->templates()->create(['body' => 'Can you talk?', 'is_reply' => false]);

        // An admin's own category, with a repeated template, is left as it is.
        $personal = MessageCategory::create(['name' => 'Personal', 'icon' => 'fas fa-user']);
        $first = $personal->templates()->create(['body' => 'Happy birthday!', 'is_reply' => false]);
        $second = $personal->templates()->create(['body' => 'Happy birthday!', 'is_reply' => false]);
        $thanks = $personal->templates()->create(['body' => 'Thanks!', 'is_reply' => true]);
        $first->replyTemplates()->attach($thanks->id);

        $this->rerunMigration(self::MIGRATION);

        $this->assertModelExists($lowercase);
        $this->assertSame($lowercase->id, $lowercasePrompt->fresh()->category_id);
        $this->assertTrue($lowercasePrompt->fresh()->is_active);

        $this->assertModelExists($personal);
        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame([$thanks->id], $first->fresh()->replyTemplates->pluck('id')->all());
    }

    public function test_without_all_three_seeded_categories_it_only_removes_duplicates(): void
    {
        $meeting = MessageCategory::create(['name' => 'Meeting', 'icon' => 'fas fa-calendar']);
        $prompt = $meeting->templates()->create(['body' => 'Can you talk?', 'is_reply' => false]);
        $busy = $meeting->templates()->create(['body' => 'I am busy', 'is_reply' => true]);
        $prompt->replyTemplates()->attach($busy->id);

        $copy = MessageCategory::create(['name' => 'Meeting', 'icon' => 'fas fa-calendar']);
        $duplicate = $copy->templates()->create(['body' => 'Can you talk?', 'is_reply' => false]);

        $this->rerunMigration(self::MIGRATION);

        $this->assertModelMissing($copy);
        $this->assertSame($meeting->id, $duplicate->fresh()->category_id);
        $this->assertFalse($duplicate->fresh()->is_active);
        $this->assertSame([$busy->id], $duplicate->fresh()->replyTemplates->pluck('id')->all());

        // No new templates, and the existing mapping is untouched.
        $this->assertDatabaseCount('message_templates', 3);
        $this->assertSame([$busy->id], $prompt->fresh()->replyTemplates->pluck('id')->all());
    }
}
