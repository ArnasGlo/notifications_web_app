<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A thread between two numbers.
 *
 * Identity is the unordered pair, normalised so number_one_id < number_two_id.
 * Two of your own numbers talking to the same person are two conversations —
 * which keeps "who do I reply from" unambiguous, since each thread has exactly
 * one of your numbers on it.
 */
class Conversation extends Model
{
    protected $fillable = ['number_one_id', 'number_two_id', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    // ── Relationships ────────────────────────────────────────────────────────

    public function numberOne()
    {
        return $this->belongsTo(Number::class, 'number_one_id');
    }

    public function numberTwo()
    {
        return $this->belongsTo(Number::class, 'number_two_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    // ── Identity ─────────────────────────────────────────────────────────────

    /**
     * The conversation between two numbers, creating it if new.
     *
     * Normalises in PHP so direction never matters: A→B and B→A resolve to the
     * same row. Idempotent, as with Delegate::firstOrCreate in InviteController.
     */
    public static function between(int $numberA, int $numberB): self
    {
        return static::firstOrCreate([
            'number_one_id' => min($numberA, $numberB),
            'number_two_id' => max($numberA, $numberB),
        ]);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    /** Conversations where at least one side is one of the given numbers. */
    public function scopeAccessibleTo(Builder $query, Collection $numberIds): Builder
    {
        return $query->where(function ($q) use ($numberIds) {
            $q->whereIn('number_one_id', $numberIds)
                ->orWhereIn('number_two_id', $numberIds);
        });
    }

    /** Conversations that involve the given number, matched exactly (Phase 1 ?q=). */
    public function scopeWithNumber(Builder $query, string $number): Builder
    {
        $matchIds = Number::where('number', $number)->pluck('id');

        return $query->where(function ($q) use ($matchIds) {
            $q->whereIn('number_one_id', $matchIds)
                ->orWhereIn('number_two_id', $matchIds);
        });
    }

    /**
     * Threads whose activity clock moved after the given moment.
     *
     * `$since` is always a server timestamp handed out by a previous response,
     * so no client clock takes part in deciding what is "new". Parsed and
     * normalised to the app timezone here, in one place, because a raw ISO-8601
     * string with an offset would otherwise be bound as its own wall time.
     */
    public function scopeUpdatedSince(Builder $query, $since): Builder
    {
        return $query->where('last_message_at', '>', Carbon::parse($since)->setTimezone(config('app.timezone')));
    }

    /**
     * The eager loads and unread count every conversation list needs.
     *
     * Shared by the Messages page, the per-number inbox, GET /api/conversations
     * and both polling endpoints, so a row never renders differently depending
     * on which of them produced it — and so none of them N+1s on the pair.
     */
    public function scopeWithListData(Builder $query, Collection $accessibleIds): Builder
    {
        return $query->with(['numberOne', 'numberTwo', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($q) use ($accessibleIds) {
                $q->whereIn('receiver_number_id', $accessibleIds)->where('status', 'sent');
            }]);
    }

    // ── Incremental updates (polling today, push later) ───────────────────────

    /**
     * Messages in this thread after the given id, oldest first.
     *
     * The cursor is the message id rather than a timestamp: ids are monotonic,
     * so two messages in the same second can't shadow each other and no clock
     * skew can skip one. `$afterId` of 0/null means "everything", which is what
     * a client with no cursor yet asks for — capped so that first call stays
     * bounded; it simply polls again with the new cursor for the rest.
     */
    public function messagesAfter(?int $afterId, int $limit = 100): HasMany
    {
        return $this->messages()
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit);
    }

    /**
     * Flip every inbound, still-unread message in this thread to read.
     *
     * One statement, one home: opening the thread and polling it are the same
     * "the viewer is looking at this" signal, on both the web and the API.
     */
    public function markInboundRead(Collection $accessibleIds): int
    {
        return $this->messages()
            ->whereIn('receiver_number_id', $accessibleIds)
            ->where('status', 'sent')
            ->update(['status' => 'read', 'read_at' => now()]);
    }

    /** Mirrors Number::isAccessibleBy — owner or delegate of either side. */
    public function isAccessibleBy(User $user): bool
    {
        $accessible = $user->accessibleNumberIds();

        return $accessible->contains($this->number_one_id)
            || $accessible->contains($this->number_two_id);
    }

    /** The viewer's side of the thread. Falls back to number one if they own both. */
    public function myNumberFor(Collection $accessibleIds): ?Number
    {
        if ($accessibleIds->contains($this->number_one_id)) {
            return $this->numberOne;
        }

        return $accessibleIds->contains($this->number_two_id) ? $this->numberTwo : null;
    }

    /** The other side of the thread, from the viewer's perspective. */
    public function counterpartFor(Collection $accessibleIds): ?Number
    {
        if (! $accessibleIds->contains($this->number_one_id)) {
            return $this->numberOne;
        }

        if (! $accessibleIds->contains($this->number_two_id)) {
            return $this->numberTwo;
        }

        // The viewer owns both ends: myNumberFor() picks number one, so the
        // counterpart is number two.
        return $this->numberTwo;
    }
}
