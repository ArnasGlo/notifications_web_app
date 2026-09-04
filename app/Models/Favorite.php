<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    protected $fillable = ['user_id', 'number_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function number()
    {
        return $this->belongsTo(Number::class);
    }
}
