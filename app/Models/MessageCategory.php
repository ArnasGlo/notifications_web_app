<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'icon', 'is_active'];
    public function templates() { return $this->hasMany(MessageTemplate::class, 'category_id'); }
}
