<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remove the duplicated seed data and bring the seeded templates to the current set.
 *
 * The old MessageCategorySeeder used create(), so every `db:seed` added another
 * full copy: the dev DB had Meeting, Status and Urgent three times each, with
 * every template repeated. In one transaction (data only, so MySQL rolls it back
 * cleanly), this:
 *
 *  1. merges same-named seeded categories into the oldest, moving their templates
 *     across before deleting the emptied duplicates. Deleting a category that
 *     still had templates would cascade them away, and messages reference them;
 *  2. brings the surviving templates to the set below: creates the new ones,
 *     retires "Arrived", and replaces each prompt's list of replies;
 *  3. deactivates duplicate templates, never deleting them, and gives each
 *     duplicate prompt the same replies as the copy that was kept. Messages
 *     already sent with a duplicate keep offering replies that way, because the
 *     duplicate replies they were mapped to are now inactive.
 *
 * No message row is written, so every message keeps its template_id.
 *
 * Only exact category names and template texts are touched, compared in PHP
 * because MySQL's default collation is case-insensitive and sqlite's is not.
 * Step 2 runs only when all three seeded categories exist. On an empty database
 * (a fresh clone, the test suite) nothing happens; those get the same set from
 * MessageCategorySeeder. The set is copied here rather than read from the seeder
 * so this migration keeps doing what it did when it ran, whatever the seeder
 * becomes. MessageTemplateCleanupTest checks the two agree.
 */
return new class extends Migration
{
    /** Seeded category name => its prompts and replies. */
    private const CATEGORIES = [
        'Meeting' => [
            'prompts' => ['Can you talk?', 'Call me back', 'Can we meet today?'],
            'replies' => ['Sure, one moment', 'I am busy', "Let's meet at 18:00", "I'll call you later", 'Not today, sorry'],
        ],
        'Status' => [
            'prompts' => ['I am on my way', 'Running late', 'I have arrived'],
            'replies' => ['OK, got it', 'See you soon', 'No problem, take your time'],
        ],
        'Urgent' => [
            'prompts' => ['Call me urgently', 'Emergency - contact me'],
            'replies' => ['On my way', 'Cannot respond now', 'Calling you now'],
        ],
    ];

    /** Seeded reply templates no longer offered, by category. */
    private const RETIRED_REPLIES = [
        'Status' => ['Arrived'],
    ];

    /** Prompt => the replies it offers, in display order. Every text is unique across the set. */
    private const REPLIES = [
        'Can you talk?' => ['Sure, one moment', "I'll call you later", "Let's meet at 18:00", 'I am busy'],
        'Call me back' => ["I'll call you later", 'Sure, one moment', "Let's meet at 18:00", 'I am busy'],
        'Can we meet today?' => ["Let's meet at 18:00", 'Not today, sorry', 'I am busy'],
        'I am on my way' => ['See you soon', 'OK, got it'],
        'Running late' => ['No problem, take your time', 'OK, got it'],
        'I have arrived' => ['Sure, one moment', 'OK, got it'],
        'Call me urgently' => ['Calling you now', 'On my way', 'Cannot respond now'],
        'Emergency - contact me' => ['Calling you now', 'On my way', 'Cannot respond now'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $categories = $this->mergeDuplicateCategories();

            if ($categories === []) {
                return;
            }

            if (count($categories) === count(self::CATEGORIES)) {
                $this->applyTemplateSet($categories);
            }

            $this->retireDuplicateTemplates($categories);
        });
    }

    /**
     * Irreversible: once merged, a moved template can't be told apart from one
     * that was always in its category.
     */
    public function down(): void
    {
        //
    }

    /**
     * Fold each seeded category name into its oldest row.
     *
     * @return array<string, int> category name => surviving id
     */
    private function mergeDuplicateCategories(): array
    {
        $survivors = [];

        $byName = DB::table('message_categories')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->filter(fn ($category) => array_key_exists($category->name, self::CATEGORIES))
            ->groupBy('name');

        foreach ($byName as $name => $copies) {
            $survivor = (int) $copies->first()->id;
            $duplicates = $copies->skip(1)->pluck('id');

            if ($duplicates->isNotEmpty()) {
                DB::table('message_templates')
                    ->whereIn('category_id', $duplicates)
                    ->update(['category_id' => $survivor, 'updated_at' => now()]);

                // Checked again at the delete itself: the category_id cascade must
                // have nothing left to take with it.
                DB::table('message_categories')
                    ->whereIn('id', $duplicates)
                    ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                        ->from('message_templates')
                        ->whereColumn('message_templates.category_id', 'message_categories.id'))
                    ->delete();
            }

            $survivors[$name] = $survivor;
        }

        return $survivors;
    }

    /** @param array<string, int> $categories */
    private function applyTemplateSet(array $categories): void
    {
        $ids = [];

        foreach (self::CATEGORIES as $name => $set) {
            foreach ($set['prompts'] as $body) {
                $ids[$body] = $this->activeTemplate($categories[$name], $body, false);
            }

            foreach ($set['replies'] as $body) {
                $ids[$body] = $this->activeTemplate($categories[$name], $body, true);
            }
        }

        foreach (self::RETIRED_REPLIES as $name => $bodies) {
            foreach ($bodies as $body) {
                DB::table('message_templates')
                    ->whereIn('id', $this->copies($categories[$name], $body, true)->pluck('id'))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
        }

        foreach (self::REPLIES as $prompt => $replies) {
            $this->replaceReplies($ids[$prompt], collect($replies)->map(fn ($reply) => $ids[$reply]));
        }
    }

    /**
     * Deactivate every copy but one of each template, and give duplicate prompts
     * the replies of the copy kept.
     *
     * @param  array<string, int>  $categories
     */
    private function retireDuplicateTemplates(array $categories): void
    {
        $groups = DB::table('message_templates')
            ->whereIn('category_id', array_values($categories))
            ->get(['id', 'category_id', 'body', 'is_reply', 'is_active'])
            ->groupBy(fn ($template) => implode("\0", [$template->category_id, (int) $template->is_reply, $template->body]));

        foreach ($groups as $copies) {
            if ($copies->count() < 2) {
                continue;
            }

            $copies = $this->keeperFirst($copies);
            $keeper = $copies->first();
            $duplicates = $copies->skip(1)->pluck('id');

            DB::table('message_templates')
                ->whereIn('id', $duplicates)
                ->update(['is_active' => false, 'updated_at' => now()]);

            if (! (int) $keeper->is_reply) {
                $replies = DB::table('message_template_replies')
                    ->where('prompt_template_id', $keeper->id)
                    ->orderBy('sort_order')
                    ->pluck('reply_template_id');

                foreach ($duplicates as $duplicate) {
                    $this->replaceReplies((int) $duplicate, $replies);
                }
            }
        }
    }

    /** The kept copy of a template, made active, or a new one when there is none. */
    private function activeTemplate(int $categoryId, string $body, bool $isReply): int
    {
        $keeper = $this->copies($categoryId, $body, $isReply)->first();

        if (is_null($keeper)) {
            return DB::table('message_templates')->insertGetId([
                'category_id' => $categoryId,
                'body' => $body,
                'is_reply' => $isReply,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! (int) $keeper->is_active) {
            DB::table('message_templates')
                ->where('id', $keeper->id)
                ->update(['is_active' => true, 'updated_at' => now()]);
        }

        return (int) $keeper->id;
    }

    /** Templates in a category with exactly this text and role, the copy to keep first. */
    private function copies(int $categoryId, string $body, bool $isReply): Collection
    {
        $copies = DB::table('message_templates')
            ->where('category_id', $categoryId)
            ->where('is_reply', $isReply)
            ->where('body', $body)
            ->get(['id', 'body', 'is_reply', 'is_active'])
            ->filter(fn ($template) => $template->body === $body);

        return $this->keeperFirst($copies);
    }

    /** Active copies before inactive ones, then oldest first. */
    private function keeperFirst(Collection $copies): Collection
    {
        return $copies
            ->sortBy([
                fn ($a, $b) => (int) $b->is_active <=> (int) $a->is_active,
                fn ($a, $b) => (int) $a->id <=> (int) $b->id,
            ])
            ->values();
    }

    /** Set a prompt's replies to exactly these template ids, in this order. */
    private function replaceReplies(int $promptId, Collection $replyIds): void
    {
        DB::table('message_template_replies')->where('prompt_template_id', $promptId)->delete();

        if ($replyIds->isEmpty()) {
            return;
        }

        DB::table('message_template_replies')->insert($replyIds->values()->map(fn ($replyId, $position) => [
            'prompt_template_id' => $promptId,
            'reply_template_id' => (int) $replyId,
            'sort_order' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }
};
