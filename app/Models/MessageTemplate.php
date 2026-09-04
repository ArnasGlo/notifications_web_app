<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'body', 'is_reply', 'is_active'];

    protected $casts = ['is_reply' => 'boolean', 'is_active' => 'boolean'];

    public function category() { return $this->belongsTo(MessageCategory::class); }
}
