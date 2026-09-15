<?php

namespace App\Models;

use App\Exceptions\CannotSendMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

    // ── Content ──────────────────────────────────────────────────────────────

    /**
     * The body and template_id to store for a message written from an optional
     * template and optional text. SendMessage and ReplyToMessage both build their
     * messages through this, so the rule has one home.
     *
     * template_id means "the body IS this template": no text sends the template
     * verbatim, text identical to it keeps the link, and any other text is typed
     * text with no template — including a template edited before sending.
     *
     * @return array{body: string, template_id: int|null}
     */
    public static function contentFrom(?MessageTemplate $template, ?string $body): array
    {
        if (blank($body)) {
            if (is_null($template)) {
                throw new InvalidArgumentException('A message needs a template or a body.');
            }

            return ['body' => $template->body, 'template_id' => $template->id];
        }

        return [
            'body' => $body,
            'template_id' => $template && $body === $template->body ? $template->id : null,
        ];
    }

    // ── Thread rendering ─────────────────────────────────────────────────────

    /**
     * Everything a chat bubble needs, loaded up front so a page of bubbles runs a
     * fixed number of queries: both ends, the message a reply answers (for its
     * "in reply to" line), and each message's reply options. Shared by the chat
     * page and its polling endpoint so a bubble renders the same from either.
     */
    public function scopeForThread(Builder $query): Builder
    {
        return $query->with(['sender', 'receiver', 'parent', 'template.activeReplyTemplates.category']);
    }

    /**
     * This message's reply options in the shape the composer's "/" menu reads —
     * the same shape as its compose-template groups: [{name, icon, templates: [{id, body}]}].
     */
    public function replyOptionGroups(): Collection
    {
        return $this->availableReplyTemplates()
            ->groupBy(fn (MessageTemplate $template) => $template->category->name)
            ->map(fn ($templates, $name) => [
                'name' => $name,
                'icon' => $templates->first()->category->icon,
                'templates' => $templates->map(fn ($template) => ['id' => $template->id, 'body' => $template->body])->values(),
            ])
            ->values()
            ->toBase();
    }

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
    // API controllers. Threads are one level deep, a message may be replied to
    // any number of times, and a reply template must be active and mapped to the
    // original's template in message_template_replies.

    /** True if this message is itself a reply, and so cannot be replied to. */
    public function isReply(): bool
    {
        return ! is_null($this->parent_id);
    }

    /**
     * Why a viewer may not reply to this message, or null if they may.
     *
     * The one eligibility check: ReplyToMessage throws whatever this returns, and
     * anything showing a Reply action asks canBeRepliedToFrom(), so the two can't
     * disagree. It takes the viewer's accessible number ids rather than a User so
     * a page of messages can be checked without a query per message.
     *
     * Covers who may reply and to which message. Whether the content is an
     * acceptable answer is canBeRepliedWith()'s job. There is no cap on replies:
     * earlier replies to the same message, from anyone, don't change the answer.
     */
    public function replyRefusal(Collection $accessibleNumberIds): ?CannotSendMessage
    {
        if (! $accessibleNumberIds->contains($this->receiver_number_id)) {
            return CannotSendMessage::notOnTheReceivingSide();
        }

        if ($this->isReply()) {
            return CannotSendMessage::cannotReplyToAReply();
        }

        return null;
    }

    /** True when a viewer who can reach these numbers may reply to this message. */
    public function canBeRepliedToFrom(Collection $accessibleNumberIds): bool
    {
        return is_null($this->replyRefusal($accessibleNumberIds));
    }

    /**
     * True if the given template is a valid reply to this message.
     *
     * Defined as membership of availableReplyTemplates(), so the options a client
     * is shown and what the reply endpoint accepts come from one query and cannot
     * disagree. A message typed freely has no template, so nothing qualifies.
     */
    public function canBeRepliedWith(MessageTemplate $template): bool
    {
        return $this->availableReplyTemplates()->contains('id', $template->id);
    }

    /**
     * Active reply templates mapped to this message's own template; empty when it
     * has no template.
     *
     * Read from message_template_replies via MessageTemplate::activeReplyTemplates(),
     * so each prompt offers only the answers mapped to it; the category plays no
     * part. is_reply is still required, so a mapping can't make a prompt template
     * count as an answer.
     *
     * Read through the relation property, so a thread loaded with forThread()
     * uses its eager-loaded options instead of querying per message.
     */
    public function availableReplyTemplates()
    {
        if (is_null($this->template)) {
            return new Collection;
        }

        return $this->template->activeReplyTemplates;
    }
}
