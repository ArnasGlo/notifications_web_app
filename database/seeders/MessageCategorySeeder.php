<?php

namespace Database\Seeders;

use App\Models\MessageCategory;
use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * The starter categories and templates, and which replies answer which prompt.
 *
 * Safe to run again: each category, template and mapping row is looked up before
 * it is created, so a second `db:seed` adds nothing. The previous version used
 * create() and left a full extra copy behind on every run. It only ever adds
 * what is missing; it never deactivates, reorders or removes anything.
 *
 * Databases seeded by the old version were brought to this set by the
 * 2026_09_15_000002_clean_up_seeded_message_templates migration, which keeps its
 * own copy of it. MessageTemplateCleanupTest checks the two agree.
 */
class MessageCategorySeeder extends Seeder
{
    private const CATEGORIES = [
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

    public function run(): void
    {
        $templates = [];

        foreach (self::CATEGORIES as $name => $set) {
            $category = MessageCategory::firstOrCreate(['name' => $name], ['icon' => $set['icon']]);

            foreach ($set['prompts'] as $body) {
                $templates[$body] = $this->template($category, $body, false);
            }

            foreach ($set['replies'] as $body) {
                $templates[$body] = $this->template($category, $body, true);
            }
        }

        foreach (self::REPLIES as $prompt => $replies) {
            $mapped = $templates[$prompt]->replyTemplates()->allRelatedIds();

            foreach ($replies as $position => $reply) {
                if (! $mapped->contains($templates[$reply]->id)) {
                    $templates[$prompt]->replyTemplates()->attach($templates[$reply]->id, ['sort_order' => $position]);
                }
            }
        }
    }

    /** The template with this text and role, preferring an active copy, or a new one. */
    private function template(MessageCategory $category, string $body, bool $isReply): MessageTemplate
    {
        return $category->templates()
            ->where('body', $body)
            ->where('is_reply', $isReply)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first()
            ?? $category->templates()->create(['body' => $body, 'is_reply' => $isReply, 'is_active' => true]);
    }
}
