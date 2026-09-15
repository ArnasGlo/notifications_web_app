<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'body', 'is_reply', 'is_active'];

    protected $casts = ['is_reply' => 'boolean', 'is_active' => 'boolean'];

    /**
     * A template is mapped from one side only: a prompt lists the replies it
     * offers, a reply template the prompts it answers. When is_reply flips, the
     * rows from its old side would stay behind — hidden on the admin form but
     * still read by the reply endpoint — so they are removed.
     *
     * Hooked on the model rather than done in the controller so no write path
     * can forget.
     */
    protected static function booted(): void
    {
        static::updated(function (MessageTemplate $template) {
            if (! $template->wasChanged('is_reply')) {
                return;
            }

            $template->is_reply
                ? $template->replyTemplates()->detach()
                : $template->promptTemplates()->detach();
        });
    }

    public function category() { return $this->belongsTo(MessageCategory::class); }

    /** The reply templates mapped as valid answers to this one, in display order. */
    public function replyTemplates()
    {
        return $this->belongsToMany(MessageTemplate::class, 'message_template_replies', 'prompt_template_id', 'reply_template_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('message_templates.id');
    }

    /** The prompts this template is mapped as an answer to: the same rows, read from the other side. */
    public function promptTemplates()
    {
        return $this->belongsToMany(MessageTemplate::class, 'message_template_replies', 'reply_template_id', 'prompt_template_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * The answers this prompt actually offers: mapped, active reply templates.
     *
     * The one definition of a valid answer. Message::availableReplyTemplates(),
     * and through it Message::canBeRepliedWith(), read it, as does the admin
     * warning about a template with nothing mapped.
     */
    public function activeReplyTemplates()
    {
        return $this->replyTemplates()
            ->where('is_reply', true)
            ->where('is_active', true);
    }

    /**
     * True when this template's side of the mapping does nothing: a prompt with
     * no active reply templates to offer, or a reply template mapped to no prompt.
     */
    public function hasNoEffectiveMappings(): bool
    {
        return $this->is_reply
            ? ! $this->promptTemplates()->exists()
            : ! $this->activeReplyTemplates()->exists();
    }

    /**
     * Replace this template's side of message_template_replies with the given ids:
     * the reply templates it offers when it's a prompt, the prompts it answers
     * when it's a reply.
     *
     * Rows that stay keep their sort_order and new ones go to the end of their
     * prompt's list, so saving never reshuffles the options clients already show.
     */
    public function syncMapping(array $templateIds): void
    {
        $relation = $this->is_reply ? $this->promptTemplates() : $this->replyTemplates();

        $wanted = collect($templateIds)
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $this->id)
            ->unique();
        $current = $relation->allRelatedIds()->map(fn ($id) => (int) $id);

        DB::transaction(function () use ($relation, $wanted, $current) {
            $removed = $current->diff($wanted);

            if ($removed->isNotEmpty()) {
                $relation->detach($removed->all());
            }

            foreach ($wanted->diff($current) as $id) {
                $promptId = $this->is_reply ? $id : $this->id;

                $relation->attach($id, ['sort_order' => static::nextSortOrder($promptId)]);
            }
        });
    }

    private static function nextSortOrder(int $promptId): int
    {
        $last = DB::table('message_template_replies')
            ->where('prompt_template_id', $promptId)
            ->max('sort_order');

        return is_null($last) ? 0 : (int) $last + 1;
    }
}
