<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'sender_number_id', 'receiver_number_id', 'template_id', 'body', 'parent_id', 'status', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    /**
     * Every message belongs to the conversation for its number pair, and every
     * message advances that conversation's activity clock.
     *
     * Hooked on the model rather than done by callers so no write path can forget:
     * the send/reply actions, factories and seeders all inherit it.
     */
    protected static function booted(): void
    {
        static::creating(function (Message $message) {
            $message->conversation_id ??= Conversation::between(
                $message->sender_number_id,
                $message->receiver_number_id,
            )->id;
        });

        static::created(function (Message $message) {
            $conversation = $message->conversation;

            // Guarded so a backdated insert (factories, seeders, backfills) can't
            // drag the ordering clock backwards.
            if (is_null($conversation->last_message_at)
                || $message->created_at->gt($conversation->last_message_at)) {
                $conversation->update(['last_message_at' => $message->created_at]);
            }
        });
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }

    public function sender() { return $this->belongsTo(Number::class, 'sender_number_id'); }
    public function receiver() { return $this->belongsTo(Number::class, 'receiver_number_id'); }
    public function template() { return $this->belongsTo(MessageTemplate::class); }
    public function replies() { return $this->hasMany(Message::class, 'parent_id'); }
    public function parent() { return $this->belongsTo(Message::class, 'parent_id'); }

    // ── Inbox queries ────────────────────────────────────────────────────────
    // Shared by both MessageController@index methods so the web app and the API
    // scope and filter the inbox identically.

    /** Messages where one of the given numbers is the sender or the receiver. */
    public function scopeAccessibleTo(Builder $query, Collection $numberIds): Builder
    {
        return $query->where(function ($q) use ($numberIds) {
            $q->whereIn('receiver_number_id', $numberIds)
                ->orWhereIn('sender_number_id', $numberIds);
        });
    }

    /**
     * Narrow to the conversation with one exact counterpart number.
     *
     * Exact, not partial. Two reasons, both hit in real data:
     *  - `numbers.number` is an arbitrary unique string, not necessarily digits
     *    (e.g. "kazkas1"), so there is no safe way to normalise the input.
     *  - Real numbers are routinely prefixes of one another ("+370864179" vs
     *    "+37086417999"), so a substring match returns a superset and the filter
     *    looks like it did nothing.
     *
     * Layers on top of scopeAccessibleTo() rather than replacing it, so no value
     * here can widen the result past the caller's own numbers.
     */
    public function scopeWithCounterpart(Builder $query, string $number, Collection $accessibleIds): Builder
    {
        $counterpartIds = Number::where('number', $number)->pluck('id');

        return $query->where(function ($q) use ($counterpartIds, $accessibleIds) {
            $q->where(function ($side) use ($counterpartIds, $accessibleIds) {
                $side->whereIn('sender_number_id', $counterpartIds)
                    ->whereIn('receiver_number_id', $accessibleIds);
            })->orWhere(function ($side) use ($counterpartIds, $accessibleIds) {
                $side->whereIn('receiver_number_id', $counterpartIds)
                    ->whereIn('sender_number_id', $accessibleIds);
            });
        });
    }

    // ── Reply eligibility ────────────────────────────────────────────────────
    // Single source of truth for the §3 reply rules, called by both the web and
    // API controllers. Threads are one level deep, one reply per message, and a
    // reply template must be active and share the original's category.

    /** True if this message is itself a reply, and so cannot be replied to. */
    public function isReply(): bool
    {
        return ! is_null($this->parent_id);
    }

    /** True if a reply already exists for this message. */
    public function hasReply(): bool
    {
        return $this->replies()->exists();
    }

    /**
     * True if the given template is a valid reply to this message.
     *
     * A message typed freely has no template and therefore no category, so the
     * same-category rule cannot be evaluated and no templated reply qualifies.
     */
    public function canBeRepliedWith(MessageTemplate $template): bool
    {
        return ! is_null($this->template)
            && (bool) $template->is_reply
            && (bool) $template->is_active
            && $template->category_id === $this->template->category_id;
    }

    /** Active reply templates in this message's category; empty when it has no template. */
    public function availableReplyTemplates()
    {
        if (is_null($this->template)) {
            return new Collection;
        }

        return $this->template->category->templates()
            ->where('is_reply', true)
            ->where('is_active', true)
            ->get();
    }
}
