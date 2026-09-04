<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'body', 'is_reply', 'is_active'];
    public function category() { return $this->belongsTo(MessageCategory::class); }
}
