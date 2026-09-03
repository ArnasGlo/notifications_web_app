<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Number extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'number', 'country', 'city', 'status', 'share_token'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($number) {
            $number->share_token = (string) Str::uuid();
        });
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function delegates()
    {
        return $this->hasMany(Delegate::class);
    }

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_number_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_number_id');
    }

    // ── Delegation helpers ───────────────────────────────────────────────────

    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }
        return $this->delegates()->where('assistant_user_id', $user->id)->exists();
    }

    public function hasAssistant(User $user): bool
    {
        return $this->delegates()->where('assistant_user_id', $user->id)->exists();
    }

    // ── Messaging permission ─────────────────────────────────────────────────

    public function canReceiveFrom(Number $sender): bool
    {
        if ($this->user->status === 'dnd') {
            return false;
        }

        $blocked = $this->blocks()->where(function ($q) use ($sender) {
            $q->where(function ($q) use ($sender) {
                $q->where('type', 'number')->where('value', $sender->number);
            })->orWhere(function ($q) use ($sender) {
                $q->where('type', 'user')->where('value', $sender->user_id);
            })->orWhere(function ($q) use ($sender) {
                $q->where('type', 'city')->where('value', $sender->city);
            })->orWhere(function ($q) use ($sender) {
                $q->where('type', 'country')->where('value', $sender->country);
            });
        })->exists();

        return !$blocked;
    }
}
