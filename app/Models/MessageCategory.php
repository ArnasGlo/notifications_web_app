<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'icon', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function templates() { return $this->hasMany(MessageTemplate::class, 'category_id'); }

    /**
     * Active categories with their active, non-reply templates — the payload the
     * compose wizard, the chat composer and GET /api/messages/compose-data all
     * offer for a new message (ANDROID_APP_CONTEXT.md §3).
     */
    public static function composePayload()
    {
        return static::with(['templates' => function ($q) {
            $q->where('is_active', true)->where('is_reply', false);
        }])->where('is_active', true)->get();
    }
}
