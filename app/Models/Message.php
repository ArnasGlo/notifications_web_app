<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['sender_number_id', 'receiver_number_id', 'template_id', 'parent_id', 'status', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

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

    /** True if the given template is a valid reply to this message. */
    public function canBeRepliedWith(MessageTemplate $template): bool
    {
        return (bool) $template->is_reply
            && (bool) $template->is_active
            && $template->category_id === $this->template->category_id;
    }
}
