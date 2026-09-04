<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
