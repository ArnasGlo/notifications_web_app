<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
