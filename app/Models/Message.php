<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['sender_number_id', 'receiver_number_id', 'template_id', 'parent_id', 'status'];
    public function sender() { return $this->belongsTo(Number::class, 'sender_number_id'); }
    public function receiver() { return $this->belongsTo(Number::class, 'receiver_number_id'); }
    public function template() { return $this->belongsTo(MessageTemplate::class); }
    public function replies() { return $this->hasMany(Message::class, 'parent_id'); }
    public function parent() { return $this->belongsTo(Message::class, 'parent_id'); }
}
