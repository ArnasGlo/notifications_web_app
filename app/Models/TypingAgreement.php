<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An explicit typing agreement on one conversation: pending until the other
 * side's owner accepts it, active once accepted_at is set. Removing it (cancel,
 * decline or end) deletes the row.
 *
 * Two numbers that both allow typing need no row — see Conversation::allowsTyping().
 */
class TypingAgreement extends Model
{
    protected $fillable = ['conversation_id', 'requested_by_number_id', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime'];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isAccepted(): bool
    {
        return ! is_null($this->accepted_at);
    }
}
