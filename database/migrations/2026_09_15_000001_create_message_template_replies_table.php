<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which reply templates are valid answers to which prompt template.
 *
 * Until now that was implied by category: every active reply template answered
 * every message whose template shared its category, so a prompt could be offered
 * answers that don't fit it, and the only way to narrow them was one category per
 * prompt. One row per (prompt, reply) pair makes the answers explicit.
 *
 * The backfill reproduces the category rule for today's active templates, so the
 * reply options a client sees are unchanged until mappings are pruned.
 *
 * Both foreign keys cascade: a mapping means nothing once either end is gone.
 * Templates themselves are never hard-deleted (messages.template_id RESTRICTs it,
 * and the admin "delete" deactivates instead), so in practice this never fires.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_template_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_template_id')->constrained('message_templates')->cascadeOnDelete();
            $table->foreignId('reply_template_id')->constrained('message_templates')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Its leading column also serves the lookup every reply list makes:
            // "the answers to this prompt". Named explicitly: Laravel's default
            // name is 68 characters, over MySQL's 64 (sqlite doesn't limit it).
            $table->unique(['prompt_template_id', 'reply_template_id'], 'template_replies_prompt_reply_unique');
            $table->index('reply_template_id');
        });

        $this->backfill();
    }

    /**
     * Map every active prompt to every active reply template in its category.
     *
     * Grouped in PHP rather than with an INSERT..SELECT join, so MySQL and sqlite
     * run exactly the same statements. sort_order follows template id, the order
     * the category-based lookup returned them in.
     */
    private function backfill(): void
    {
        $now = now();

        $repliesByCategory = DB::table('message_templates')
            ->where('is_reply', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'category_id'])
            ->groupBy(fn ($template) => (int) $template->category_id);

        $prompts = DB::table('message_templates')
            ->where('is_reply', false)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'category_id']);

        foreach ($prompts as $prompt) {
            $rows = [];

            foreach ($repliesByCategory->get((int) $prompt->category_id, []) as $position => $reply) {
                $rows[] = [
                    'prompt_template_id' => $prompt->id,
                    'reply_template_id' => $reply->id,
                    'sort_order' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('message_template_replies')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_replies');
    }
};
