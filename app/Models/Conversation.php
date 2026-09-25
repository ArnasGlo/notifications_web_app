<?php

namespace App\Models;

use App\Exceptions\TypingAgreementRefused;
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

    public function typingAgreement()
    {
        return $this->hasOne(TypingAgreement::class);
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
        return static::firstOrCreate(static::pair($numberA, $numberB));
    }

    /** The conversation between two numbers, or null if they have never talked. */
    public static function findBetween(int $numberA, int $numberB): ?self
    {
        return static::where(static::pair($numberA, $numberB))->first();
    }

    /** @return array{number_one_id: int, number_two_id: int} */
    private static function pair(int $numberA, int $numberB): array
    {
        return [
            'number_one_id' => min($numberA, $numberB),
            'number_two_id' => max($numberA, $numberB),
        ];
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
        return $query->with(['numberOne', 'numberTwo', 'latestMessage', 'typingAgreement'])
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

    // ── Typing agreement ─────────────────────────────────────────────────────
    // Templates are the default; free text needs typing to be allowed between
    // the two numbers. It is allowed automatically when both numbers allow
    // typing, or by an explicit agreement one owner requested and the other's
    // owner accepted (at once, if their number allows typing). SendMessage and
    // ReplyToMessage enforce it; both clients read typingStateFor().

    /** Whether free text may pass between two numbers, before any conversation exists too. */
    public static function typingAllowedBetween(Number $numberA, Number $numberB): bool
    {
        if ($numberA->allow_typing && $numberB->allow_typing) {
            return true;
        }

        return (bool) static::findBetween($numberA->id, $numberB->id)?->typingAgreement?->isAccepted();
    }

    /** Both numbers allow typing: active with no agreement, and it can't be removed. */
    public function typingIsAutomatic(): bool
    {
        return $this->numberOne->allow_typing && $this->numberTwo->allow_typing;
    }

    public function allowsTyping(): bool
    {
        return $this->typingIsAutomatic() || (bool) $this->typingAgreement?->isAccepted();
    }

    /**
     * The typing state as one viewer sees it, including what they may do about it.
     * Only the owner of the viewer's side may request, accept or remove.
     *
     * @return array{status: string, automatic: bool, requested_by_me: bool|null, can_request: bool, can_accept: bool, can_remove: bool}
     */
    public function typingStateFor(?Number $myNumber, User $viewer): array
    {
        $agreement = $this->typingAgreement;
        $automatic = $this->typingIsAutomatic();

        $status = match (true) {
            $automatic || (bool) $agreement?->isAccepted() => 'active',
            ! is_null($agreement) => 'pending',
            default => 'none',
        };

        $requestedByMe = $status === 'pending'
            ? $agreement->requested_by_number_id === $myNumber?->id
            : null;

        $manages = $myNumber?->user_id === $viewer->id;

        return [
            'status' => $status,
            'automatic' => $automatic,
            'requested_by_me' => $requestedByMe,
            'can_request' => $manages && $status === 'none',
            'can_accept' => $manages && $status === 'pending' && ! $requestedByMe,
            'can_remove' => $manages && ! is_null($agreement) && ! $automatic,
        ];
    }

    /**
     * Ask the other side to allow typing. A number that allows typing accepts
     * incoming requests automatically, so the agreement may be active at once.
     *
     * @throws TypingAgreementRefused
     */
    public function requestTyping(User $actor): TypingAgreement
    {
        $mine = $this->ownedSideFor($actor);

        if ($this->allowsTyping()) {
            throw TypingAgreementRefused::alreadyActive();
        }

        if ($this->typingAgreement) {
            throw TypingAgreementRefused::alreadyRequested();
        }

        $theirs = $mine->is($this->numberOne) ? $this->numberTwo : $this->numberOne;

        $agreement = $this->typingAgreement()->create([
            'requested_by_number_id' => $mine->id,
            'accepted_at' => $theirs->allow_typing ? now() : null,
        ]);

        $this->setRelation('typingAgreement', $agreement);

        return $agreement;
    }

    /**
     * Accept a request the other side sent.
     *
     * @throws TypingAgreementRefused
     */
    public function acceptTyping(User $actor): void
    {
        $mine = $this->ownedSideFor($actor);
        $agreement = $this->typingAgreement;

        if (! $agreement || $agreement->isAccepted() || $agreement->requested_by_number_id === $mine->id) {
            throw TypingAgreementRefused::nothingToAccept();
        }

        $agreement->update(['accepted_at' => now()]);
    }

    /**
     * Cancel a request, decline one, or end an active agreement — all the same
     * act of removing it, open to either owner. An automatic agreement has no
     * row to remove: it lasts while both numbers allow typing.
     *
     * @throws TypingAgreementRefused
     */
    public function removeTyping(User $actor): void
    {
        $this->ownedSideFor($actor);

        if ($this->typingIsAutomatic()) {
            throw TypingAgreementRefused::automatic();
        }

        if (! $this->typingAgreement) {
            throw TypingAgreementRefused::nothingToRemove();
        }

        $this->typingAgreement->delete();
        $this->setRelation('typingAgreement', null);
    }

    /**
     * The side of this thread the actor owns. Assistants can type once typing is
     * allowed, but managing it is the owner's, as with blocks and delegates.
     *
     * @throws TypingAgreementRefused
     */
    private function ownedSideFor(User $actor): Number
    {
        foreach ([$this->numberOne, $this->numberTwo] as $number) {
            if ($number->user_id === $actor->id) {
                return $number;
            }
        }

        throw TypingAgreementRefused::notTheOwner();
    }
}
